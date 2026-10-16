<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\BackgroundJob;

use OCA\MaintenanceTracker\Service\IntegrationProjector;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Projects new audit events into Nextcloud Activity and ownership-transfer notifications.
 */
final class ProjectAuditTrail extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private IntegrationProjector $service,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$this->service->run();
	}
}
