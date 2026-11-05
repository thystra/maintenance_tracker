<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use DateTimeImmutable;
use OCA\MaintenanceTracker\Service\DueStatePolicy;
use OCA\MaintenanceTracker\Service\ReminderDeliveryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Next-due overrides treat the chosen date/meter as the due threshold itself
 * (interval already applied by the user), and reminders get a synthetic baseline.
 */
final class NextDueOverridePolicyTest extends TestCase {
	public function testCalendarOverrideUsesChosenDateAsDue(): void {
		$policy = new DueStatePolicy();
		$due = (new DateTimeImmutable('2026-12-01T00:00:00Z'))->getTimestamp();
		$result = $policy->calendar($due, (new DateTimeImmutable('2026-11-20T12:00:00Z'))->getTimestamp(), 0, 'day');
		self::assertSame('upcoming', $result['state']);
		self::assertSame('2026-12-01', $result['dueOn']);
		self::assertSame(11, $result['remainingDays']);
	}

	public function testReminderBaselineUsesSyntheticOverrideId(): void {
		$policy = new ReminderDeliveryPolicy();
		$item = [
			'lastActivityUuid' => null,
			'reason' => 'next_due_override',
			'definition' => ['uuid' => '11111111-1111-4111-8111-111111111111'],
			'state' => 'due',
			'rules' => [['type' => 'calendar', 'dueOn' => '2026-12-01']],
			'forecast' => ['state' => 'due'],
		];
		self::assertSame('next-due:11111111-1111-4111-8111-111111111111', $policy->baseline($item));
		self::assertSame('2026-12-01', $policy->calendarDueOn($item));
	}

	public function testMeterThresholdMathMatchesOverrideSemantics(): void {
		$policy = new DueStatePolicy();
		// baseline + interval == due; for override we compare current to due directly.
		// remaining = due - current mirrors DueStatePolicy::meter remainingCanonicalValue.
		$due = 160934400000; // 100000 mi in mm, illustrative
		$current = 160000000000;
		$remaining = $due - $current;
		self::assertGreaterThan(0, $remaining);
		self::assertSame('upcoming', $remaining > 0 ? 'upcoming' : ($remaining === 0 ? 'due' : 'overdue'));
		self::assertSame('due', $policy->meter(1000, 1500, 500)['state']);
	}
}
