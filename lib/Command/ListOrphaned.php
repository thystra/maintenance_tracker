<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Command;

use OCA\MaintenanceTracker\Service\OwnershipTransferService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** List workspaces whose owner account was deleted while other members remained. */
final class ListOrphaned extends Command {
	public function __construct(private OwnershipTransferService $transfers) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('maintenance_tracker:workspace:list-orphaned')
			->setDescription('List Maintenance Tracker workspaces that need an administrator to assign an owner')
			->addOption('output', null, InputOption::VALUE_REQUIRED, 'plain or json', 'plain');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$rows = $this->transfers->orphanedWorkspaces();
		if ($input->getOption('output') === 'json') {
			$output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
			return 0;
		}
		foreach ($rows as $row) {
			$output->writeln($row['uuid'] . "\t" . $row['orphanedAt'] . "\tformer owner: " . $row['formerOwnerUid']);
		}
		if ($rows === []) {
			$output->writeln('No orphaned workspaces.');
		}
		return 0;
	}
}
