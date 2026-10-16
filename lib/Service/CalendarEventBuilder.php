<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

/**
 * Builds the iCalendar object for one projected due date. The event is an
 * all-day, transparent VEVENT with a neutral title: the calendar may be
 * shared, so task, asset and workspace names never leave the app. The UID is
 * derived from (workspace, user, calendar, task, completion baseline), so the
 * same projection always yields the same object and a new completion yields
 * a new one.
 */
final class CalendarEventBuilder {
	public const PRODID = '-//Maintenance Tracker//Nextcloud//EN';

	public function uid(string $workspaceUuid, string $userUid, string $calendarUri, string $definitionUuid, string $baselineUuid): string {
		return 'maintenance-tracker-' . hash('sha256', implode("\n", [$workspaceUuid, $userUid, $calendarUri, $definitionUuid, $baselineUuid])) . '@maintenance-tracker';
	}

	public function objectName(string $uid): string {
		return substr($uid, 0, (int)strpos($uid, '@')) . '.ics';
	}

	public function build(string $uid, string $dueOn, string $summary, string $url, int $now): string {
		$start = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn, new \DateTimeZone('UTC'));
		if ($start === false || $start->format('Y-m-d') !== $dueOn) {
			throw new \InvalidArgumentException('dueOn must be a Y-m-d date');
		}
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:' . self::PRODID,
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . gmdate('Ymd\THis\Z', $now),
			'DTSTART;VALUE=DATE:' . $start->format('Ymd'),
			'DTEND;VALUE=DATE:' . $start->modify('+1 day')->format('Ymd'),
			'SUMMARY:' . self::escape($summary),
			'TRANSP:TRANSPARENT',
			'CLASS:PRIVATE',
			'URL:' . str_replace(["\r", "\n"], '', $url),
			'END:VEVENT',
			'END:VCALENDAR',
		];
		return implode("\r\n", $lines) . "\r\n";
	}

	private static function escape(string $value): string {
		$value = str_replace(["\r", "\n"], ' ', $value);
		return addcslashes($value, '\\;,');
	}
}
