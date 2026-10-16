<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Command;

use OCA\MaintenanceTracker\Service\OwnershipTransferService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Administrator owner assignment: give an orphaned workspace a new owner, or
 * move a live workspace (and its evidence folder) to a new owner. Re-running
 * the command resumes an interrupted transfer to the same user.
 */
final class AssignOwner extends Command {
	public function __construct(private OwnershipTransferService $transfers) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('maintenance_tracker:workspace:assign-owner')
			->setDescription('Assign a Maintenance Tracker workspace to a new owner (orphaned workspaces, or an administrative transfer)')
			->addArgument('workspace', InputArgument::REQUIRED, 'Workspace UUID')
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user ID of the new owner')
			->addOption('remove-previous', null, InputOption::VALUE_NONE, 'Remove the previous owner from the workspace instead of making them a Manager');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$result = $this->transfers->assignOwner(
				(string)$input->getArgument('workspace'),
				(string)$input->getArgument('user'),
				(bool)$input->getOption('remove-previous'),
			);
		} catch (Throwable $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('Transfer ' . $result['uuid'] . ': ' . $result['status'] . ' (step ' . $result['step'] . ')');
		return 0;
	}
}
