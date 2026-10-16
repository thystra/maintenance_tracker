<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\BackgroundJob;

use OCA\MaintenanceTracker\Service\ReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Delivers due/overdue reminder notifications and writes projected due dates to selected calendars.
 */
final class SendMaintenanceReminders extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ReminderService $service,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->service->run();
	}
}
