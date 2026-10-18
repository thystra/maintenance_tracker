<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * "Copy from existing item": the source item's setup (meters, components,
 * specifications, parts that fit, task groups, tasks with schedules, fuel
 * regimes and settings) is turned into a profile document, validated, and
 * materialized on the new item through the same code as a profile install,
 * without recording an installation. History is never copied: no readings,
 * maintenance records, fill-ups, costs or files.
 */
class SetupCopyService {
	public function __construct(
		private AssetService $assets,
		private ProfileSnapshotBuilder $snapshots,
		private ProfileValidator $validator,
		private ProfileInstallationService $installer,
		private AuditService $audit,
		private CurrentUser $currentUser,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return array<string,int> what was created */
	public function copy(WorkspaceContext $context, string $targetAssetUuid, string $sourceAssetUuid): array {
		$target = $this->assets->find($context, $targetAssetUuid);
		$source = $this->assets->find($context, CatalogInput::uuid($sourceAssetUuid, 'copySetupFrom'));
		if ($source->getId() === $target->getId()) {
			throw new ValidationException('An item cannot copy its own setup');
		}
		$document = $this->snapshots->fromAsset($context, $source->getUuid(), [
			'id' => 'copy.' . ProfileSnapshotBuilder::slug($source->getName()),
			'version' => '1.0.0',
			'name' => $source->getName(),
			'description' => 'Setup copied between items.',
			'author' => $context->workspace()->getName(),
		]);
		$profile = $this->validator->validate($document)['profile'];
		$conflicts = $this->installer->setupConflicts($context, $target, $profile);
		if ($conflicts !== []) {
			throw new ValidationException($conflicts[0]);
		}
		$created = $this->installer->materialize($context, $target, $profile, null, null, $this->timeFactory->getTime());
		$this->audit->record($context->workspace()->getId(), 'asset.setup_copied', $this->currentUser->uid(), $target->getUuid(), null, ['sourceAssetUuid' => $source->getUuid()]);
		return $created;
	}
}
