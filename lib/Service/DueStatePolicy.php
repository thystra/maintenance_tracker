<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Pure due-state math. Calendar semantics are evaluated on UTC calendar dates
 * so a shared workspace sees one deterministic derived status regardless of
 * the viewer's local timezone. A workspace timezone can replace this policy in
 * a later schema version without persisting stale due-state rows.
 */
final class DueStatePolicy {
	/** @return array{state:string,dueOn:string,remainingDays:int} */
	public function calendar(int $performedAt, int $asOf, int $interval, string $unit): array {
		$baseline = $this->utcDate($performedAt);
		$due = match ($unit) {
			'day' => $baseline->modify('+' . $interval . ' days'),
			'week' => $baseline->modify('+' . ($interval * 7) . ' days'),
			'month' => $this->addMonthsClamped($baseline, $interval),
			'year' => $this->addYearsClamped($baseline, $interval),
			default => throw new InvalidArgumentException('Unsupported calendar interval unit'),
		};

		return $this->dateResult($due, $asOf);
	}

	/** @return array{state:string,dueOn:string,remainingDays:int} */
	public function businessDays(int $performedAt, int $asOf, int $interval, int $weekdayMask): array {
		if ($weekdayMask < 1 || $weekdayMask > 127) {
			throw new InvalidArgumentException('Business-day weekday mask must select at least one weekday');
		}
		$due = $this->utcDate($performedAt);
		$daysPerWeek = substr_count(decbin($weekdayMask), '1');
		$fullWeeks = intdiv($interval, $daysPerWeek);
		if ($fullWeeks > 0) {
			$due = $due->modify('+' . ($fullWeeks * 7) . ' days');
		}
		$remaining = $interval - ($fullWeeks * $daysPerWeek);
		while ($remaining > 0) {
			$due = $due->modify('+1 day');
			if (($weekdayMask & (1 << (int)$due->format('w'))) !== 0) {
				$remaining--;
			}
		}

		return $this->dateResult($due, $asOf);
	}

	/** @return array{state:string,dueCanonicalValue:int,remainingCanonicalValue:int} */
	public function meter(int $baselineValue, int $currentValue, int $interval): array {
		if ($baselineValue < 0 || $currentValue < 0 || $interval < 1) {
			throw new InvalidArgumentException('Meter due-state inputs must be non-negative with a positive interval');
		}
		if ($baselineValue > MeterValueConverter::MAX_CANONICAL_VALUE - $interval) {
			throw new InvalidArgumentException('Meter due value exceeds the canonical safe-integer range');
		}
		$due = $baselineValue + $interval;
		$remaining = $due - $currentValue;
		$state = $remaining > 0 ? 'upcoming' : ($remaining === 0 ? 'due' : 'overdue');

		return [
			'state' => $state,
			'dueCanonicalValue' => $due,
			'remainingCanonicalValue' => $remaining,
		];
	}

	/** @return array{state:string,dueOn:string,remainingDays:int} */
	private function dateResult(DateTimeImmutable $due, int $asOf): array {
		$today = $this->utcDate($asOf);
		$remainingDays = (int)$today->diff($due)->format('%r%a');
		$state = $remainingDays > 0 ? 'upcoming' : ($remainingDays === 0 ? 'due' : 'overdue');

		return [
			'state' => $state,
			'dueOn' => $due->format('Y-m-d'),
			'remainingDays' => $remainingDays,
		];
	}

	private function utcDate(int $timestamp): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $timestamp))
			->setTimezone(new DateTimeZone('UTC'))
			->setTime(0, 0, 0);
	}

	private function addMonthsClamped(DateTimeImmutable $date, int $months): DateTimeImmutable {
		$year = (int)$date->format('Y');
		$monthIndex = ((int)$date->format('n') - 1) + $months;
		$targetYear = $year + intdiv($monthIndex, 12);
		$targetMonth = ($monthIndex % 12) + 1;
		$day = min((int)$date->format('j'), (int)$date->setDate($targetYear, $targetMonth, 1)->format('t'));

		return $date->setDate($targetYear, $targetMonth, $day);
	}

	private function addYearsClamped(DateTimeImmutable $date, int $years): DateTimeImmutable {
		$targetYear = (int)$date->format('Y') + $years;
		$month = (int)$date->format('n');
		$day = min((int)$date->format('j'), (int)$date->setDate($targetYear, $month, 1)->format('t'));

		return $date->setDate($targetYear, $month, $day);
	}
}
