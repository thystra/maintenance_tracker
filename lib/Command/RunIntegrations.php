<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Command;

use OCA\MaintenanceTracker\Service\IntegrationProjector;
use OCA\MaintenanceTracker\Service\OwnershipTransferService;
use OCA\MaintenanceTracker\Service\ReminderService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the integration background work now, in the same order cron would:
 * expire stale ownership offers, project the audit trail into Activity and
 * notifications, then deliver reminders and calendar projection.
 */
final class RunIntegrations extends Command {
	public function __construct(
		private OwnershipTransferService $transfers,
		private IntegrationProjector $projector,
		private ReminderService $reminders,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('maintenance_tracker:integrations:run')
			->setDescription('Expire ownership offers and deliver Activity, notifications and calendar projection now');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$expired = $this->transfers->expireDue();
		$projected = $this->projector->run();
		$stats = $this->reminders->run();
		$output->writeln(sprintf(
			'expired=%d projected=%d workspaces=%d notifications=%d withdrawn=%d calendarEvents=%d',
			$expired,
			$projected,
			$stats['workspaces'],
			$stats['notifications'],
			$stats['withdrawn'],
			$stats['calendarEvents'],
		));
		return 0;
	}
}
