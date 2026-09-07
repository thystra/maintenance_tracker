<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\MaintenanceTracker\Service\DueStatePolicy;
use PHPUnit\Framework\TestCase;

final class DueStatePolicyTest extends TestCase {
	private DueStatePolicy $policy;

	protected function setUp(): void {
		$this->policy = new DueStatePolicy();
	}

	public function testCalendarMonthClampsEndOfMonthAndUsesDateState(): void {
		$performed = $this->ts('2028-01-31T18:30:00Z');
		$due = $this->policy->calendar($performed, $this->ts('2028-02-29T01:00:00Z'), 1, 'month');
		$this->assertSame('due', $due['state']);
		$this->assertSame('2028-02-29', $due['dueOn']);
		$this->assertSame(0, $due['remainingDays']);

		$overdue = $this->policy->calendar($performed, $this->ts('2028-03-01T00:00:00Z'), 1, 'month');
		$this->assertSame('overdue', $overdue['state']);
		$this->assertSame(-1, $overdue['remainingDays']);
	}

	public function testCalendarYearClampsLeapDay(): void {
		$result = $this->policy->calendar(
			$this->ts('2028-02-29T12:00:00Z'),
			$this->ts('2029-02-27T12:00:00Z'),
			1,
			'year',
		);
		$this->assertSame('upcoming', $result['state']);
		$this->assertSame('2029-02-28', $result['dueOn']);
		$this->assertSame(1, $result['remainingDays']);
	}

	public function testBusinessDaysHonorConfiguredWeekdays(): void {
		// Sunday through Thursday: bits 0..4.
		$result = $this->policy->businessDays(
			$this->ts('2026-09-03T12:00:00Z'), // Thursday
			$this->ts('2026-09-09T12:00:00Z'), // Wednesday
			4,
			0b0011111,
		);
		$this->assertSame('due', $result['state']);
		$this->assertSame('2026-09-09', $result['dueOn']);
	}

	public function testMeterStateIsExactAtThresholdAndOverdueAboveIt(): void {
		$this->assertSame([
			'state' => 'upcoming',
			'dueCanonicalValue' => 1500,
			'remainingCanonicalValue' => 1,
		], $this->policy->meter(1000, 1499, 500));
		$this->assertSame('due', $this->policy->meter(1000, 1500, 500)['state']);
		$this->assertSame('overdue', $this->policy->meter(1000, 1501, 500)['state']);
	}

	private function ts(string $value): int {
		return (new DateTimeImmutable($value))->getTimestamp();
	}
}
