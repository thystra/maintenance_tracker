<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\Unit\Service;

use OCA\MaintenanceTracker\Service\CalendarEventBuilder;
use PHPUnit\Framework\TestCase;

final class CalendarEventBuilderTest extends TestCase {
	public function testUidIsStablePerBaselineAndChangesWithANewCompletion(): void {
		$b = new CalendarEventBuilder();
		$uid = $b->uid('ws', 'alice', 'personal', 'def', 'base1');
		self::assertSame($uid, $b->uid('ws', 'alice', 'personal', 'def', 'base1'));
		self::assertNotSame($uid, $b->uid('ws', 'alice', 'personal', 'def', 'base2'));
		self::assertNotSame($uid, $b->uid('ws', 'bob', 'personal', 'def', 'base1'));
		self::assertMatchesRegularExpression('/^maintenance-tracker-[0-9a-f]{64}@maintenance-tracker$/', $uid);
		self::assertSame(substr($uid, 0, 84) . '.ics', $b->objectName($uid));
	}

	public function testAllDayNeutralEvent(): void {
		$b = new CalendarEventBuilder();
		$ics = $b->build('uid-1@maintenance-tracker', '2026-12-31', 'Maintenance due', 'https://cloud.example/apps/maintenance_tracker/', 0);
		self::assertStringContainsString("DTSTART;VALUE=DATE:20261231\r\n", $ics);
		self::assertStringContainsString("DTEND;VALUE=DATE:20270101\r\n", $ics);
		self::assertStringContainsString("SUMMARY:Maintenance due\r\n", $ics);
		self::assertStringContainsString("TRANSP:TRANSPARENT\r\n", $ics);
		self::assertStringContainsString("UID:uid-1@maintenance-tracker\r\n", $ics);
		self::assertStringNotContainsString('DESCRIPTION', $ics);
		self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
	}

	public function testSummaryIsEscapedAndInvalidDatesRejected(): void {
		$b = new CalendarEventBuilder();
		self::assertStringContainsString('SUMMARY:a\\, b\\; c d', $b->build('u@x', '2026-01-01', "a, b; c\nd", 'https://x/', 0));
		$this->expectException(\InvalidArgumentException::class);
		$b->build('u@x', '2026-02-30', 'x', 'https://x/', 0);
	}
}
