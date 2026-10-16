<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsEnabled;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Constants;
use OCP\IConfig;

/**
 * Per-user integration preferences: due/overdue reminder notifications
 * (on by default) and the optional existing writable calendar that receives
 * projected due dates. The app never creates calendars; it only writes to a
 * calendar the user selected and owns (never a calendar shared with them). Preferences are Nextcloud user values, so
 * Nextcloud removes them with the account.
 */
final class IntegrationSettingsService {
	private const KEY_REMINDERS = 'reminders_enabled';
	private const KEY_CALENDAR = 'calendar_uri';

	public function __construct(
		private IConfig $config,
		private ICalendarManager $calendarManager,
	) {
	}

	public function remindersEnabled(string $uid): bool {
		return $this->config->getUserValue($uid, Application::APP_ID, self::KEY_REMINDERS, 'yes') !== 'no';
	}

	public function calendarUri(string $uid): ?string {
		$uri = $this->config->getUserValue($uid, Application::APP_ID, self::KEY_CALENDAR, '');
		return is_string($uri) && $uri !== '' ? $uri : null;
	}

	/** @return array{reminders: bool, calendarUri: ?string, calendarAvailable: bool} */
	public function get(string $uid): array {
		return [
			'reminders' => $this->remindersEnabled($uid),
			'calendarUri' => $this->calendarUri($uid),
			'calendarAvailable' => $this->calendarManager->isEnabled(),
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{reminders: bool, calendarUri: ?string, calendarAvailable: bool}
	 */
	public function update(string $uid, array $input): array {
		CatalogInput::known($input, ['reminders', 'calendarUri'], 'settings');
		if (array_key_exists('reminders', $input)) {
			if (!is_bool($input['reminders'])) {
				throw new ValidationException('reminders must be a boolean');
			}
			$this->config->setUserValue($uid, Application::APP_ID, self::KEY_REMINDERS, $input['reminders'] ? 'yes' : 'no');
		}
		if (array_key_exists('calendarUri', $input)) {
			$uri = $input['calendarUri'];
			if ($uri === null || $uri === '') {
				$this->config->deleteUserValue($uid, Application::APP_ID, self::KEY_CALENDAR);
			} else {
				if (!is_string($uri) || $this->writableCalendar($uid, $uri) === null) {
					throw new ValidationException('calendarUri must name one of your writable calendars');
				}
				$this->config->setUserValue($uid, Application::APP_ID, self::KEY_CALENDAR, $uri);
			}
		}
		return $this->get($uid);
	}

	/** @return list<array{uri: string, displayName: string, color: ?string}> */
	public function writableCalendars(string $uid): array {
		$items = [];
		foreach ($this->candidates($uid) as $calendar) {
			$items[] = ['uri' => $calendar->getUri(), 'displayName' => (string)($calendar->getDisplayName() ?? $calendar->getUri()), 'color' => $calendar->getDisplayColor()];
		}
		return $items;
	}

	/** @return (ICalendar&ICreateFromString)|null */
	public function writableCalendar(string $uid, string $uri): ?ICalendar {
		foreach ($this->candidates($uid) as $calendar) {
			if ($calendar->getUri() === $uri) {
				return $calendar;
			}
		}
		return null;
	}

	/** @return list<ICalendar&ICreateFromString> */
	private function candidates(string $uid): array {
		if (!$this->calendarManager->isEnabled()) {
			return [];
		}
		$result = [];
		foreach ($this->calendarManager->getCalendarsForPrincipal('principals/users/' . $uid) as $calendar) {
			if (!$calendar instanceof ICreateFromString || $calendar->isDeleted()) {
				continue;
			}
			// Only the user's own calendars: a calendar shared with them may be
			// visible to people outside the workspace.
			if ($calendar instanceof ICalendarIsShared && $calendar->isShared()) {
				continue;
			}
			if ($calendar instanceof ICalendarIsEnabled && !$calendar->isEnabled()) {
				continue;
			}
			$writable = $calendar instanceof ICalendarIsWritable
				? $calendar->isWritable()
				: ($calendar->getPermissions() & Constants::PERMISSION_CREATE) !== 0;
			if ($writable) {
				$result[] = $calendar;
			}
		}
		return $result;
	}
}
