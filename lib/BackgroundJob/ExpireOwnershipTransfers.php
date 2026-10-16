<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\BackgroundJob;

use OCA\MaintenanceTracker\Service\OwnershipTransferService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Expires pending ownership-transfer offers past their deadline; the audit projection then notifies the offerer.
 */
final class ExpireOwnershipTransfers extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private OwnershipTransferService $service,
	) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$this->service->expireDue();
	}
}
