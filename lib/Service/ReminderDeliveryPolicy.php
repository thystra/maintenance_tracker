<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

/**
 * Turns a forecast item (the v0.1.8 policy layer over the derived v0.1.7
 * status) into delivery decisions. It never computes due truth itself: the
 * attention level comes from ForecastPolicy and the calendar date from the
 * calendar/business-day rule projection.
 */
final class ReminderDeliveryPolicy {
	public const LEVELS = ['due_soon', 'due', 'overdue'];

	/** Calendar window around today in which projected events are written. */
	public const CALENDAR_PAST_DAYS = 30;
	public const CALENDAR_FUTURE_DAYS = 400;

	/** @param array<string,mixed> $item */
	public function level(array $item): ?string {
		$state = is_array($item['forecast'] ?? null) ? ($item['forecast']['state'] ?? null) : null;
		return is_string($state) && in_array($state, self::LEVELS, true) ? $state : null;
	}

	/** @param array<string,mixed> $item */
	public function baseline(array $item): ?string {
		$uuid = $item['lastActivityUuid'] ?? null;
		return is_string($uuid) && $uuid !== '' ? $uuid : null;
	}

	/** @param array<string,mixed> $item */
	public function definitionUuid(array $item): ?string {
		$uuid = is_array($item['definition'] ?? null) ? ($item['definition']['uuid'] ?? null) : null;
		return is_string($uuid) && $uuid !== '' ? $uuid : null;
	}

	public function notificationSubject(string $level): string {
		return 'maintenance_' . $level;
	}

	/**
	 * Earliest calendar due date among calendar and business-day rules, or
	 * null when the task is unscheduled, lacks a baseline, or is meter-only
	 * (meter thresholds have no date and are never guessed).
	 *
	 * @param array<string,mixed> $item
	 */
	public function calendarDueOn(array $item): ?string {
		if (!in_array($item['state'] ?? null, ['overdue', 'due', 'upcoming'], true) || $this->baseline($item) === null) {
			return null;
		}
		$dates = [];
		foreach (($item['rules'] ?? []) as $rule) {
			if (!is_array($rule) || !in_array($rule['type'] ?? null, ['calendar', 'business_days'], true)) {
				continue;
			}
			$dueOn = $rule['dueOn'] ?? null;
			if (is_string($dueOn) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueOn) === 1) {
				$dates[] = $dueOn;
			}
		}
		if ($dates === []) {
			return null;
		}
		sort($dates, SORT_STRING);
		return $dates[0];
	}

	public function withinCalendarWindow(string $dueOn, string $today): bool {
		$due = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn, new \DateTimeZone('UTC'));
		$now = \DateTimeImmutable::createFromFormat('!Y-m-d', $today, new \DateTimeZone('UTC'));
		if ($due === false || $now === false) {
			return false;
		}
		$days = (int)(($due->getTimestamp() - $now->getTimestamp()) / 86400);
		return $days >= -self::CALENDAR_PAST_DAYS && $days <= self::CALENDAR_FUTURE_DAYS;
	}
}
