<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\Unit\Service;

use OCA\MaintenanceTracker\Service\ReminderDeliveryPolicy;
use PHPUnit\Framework\TestCase;

final class ReminderDeliveryPolicyTest extends TestCase {
	/** @param list<array<string,mixed>> $rules @return array<string,mixed> */
	private static function item(string $state, ?string $forecast, ?string $baseline, array $rules = []): array {
		return [
			'definition' => ['uuid' => 'd1'],
			'state' => $state,
			'lastActivityUuid' => $baseline,
			'rules' => $rules,
			'forecast' => ['state' => $forecast],
		];
	}

	public function testLevelComesOnlyFromTheForecastPolicy(): void {
		$p = new ReminderDeliveryPolicy();
		self::assertSame('overdue', $p->level(self::item('overdue', 'overdue', 'b')));
		self::assertSame('due', $p->level(self::item('due', 'due', 'b')));
		self::assertSame('due_soon', $p->level(self::item('upcoming', 'due_soon', 'b')));
		self::assertNull($p->level(self::item('upcoming', 'not_due', 'b')));
		self::assertNull($p->level(self::item('baseline_required', 'setup_required', null)));
		self::assertNull($p->level(self::item('unknown', 'blocked', 'b')));
		self::assertSame('maintenance_due_soon', $p->notificationSubject('due_soon'));
	}

	public function testCalendarDateIsEarliestDatedRuleAndMeterOnlyIsNeverGuessed(): void {
		$p = new ReminderDeliveryPolicy();
		$rules = [
			['type' => 'meter', 'remainingCanonicalValue' => 10],
			['type' => 'calendar', 'dueOn' => '2026-12-01'],
			['type' => 'business_days', 'dueOn' => '2026-11-20'],
		];
		self::assertSame('2026-11-20', $p->calendarDueOn(self::item('upcoming', 'not_due', 'b', $rules)));
		self::assertNull($p->calendarDueOn(self::item('upcoming', 'not_due', 'b', [['type' => 'meter']])));
		self::assertNull($p->calendarDueOn(self::item('upcoming', 'not_due', null, $rules)));
		self::assertNull($p->calendarDueOn(self::item('unscheduled', 'unscheduled', 'b', $rules)));
		self::assertNull($p->calendarDueOn(self::item('upcoming', 'not_due', 'b', [['type' => 'calendar', 'dueOn' => 'soon']])));
	}

	public function testCalendarWindow(): void {
		$p = new ReminderDeliveryPolicy();
		self::assertTrue($p->withinCalendarWindow('2026-10-16', '2026-10-16'));
		self::assertTrue($p->withinCalendarWindow('2026-09-16', '2026-10-16'));
		self::assertFalse($p->withinCalendarWindow('2026-09-14', '2026-10-15'));
		self::assertFalse($p->withinCalendarWindow('2028-01-01', '2026-10-16'));
		self::assertFalse($p->withinCalendarWindow('bad', '2026-10-16'));
	}
}
