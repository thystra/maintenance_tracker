<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\ChangeRecord;
use OCA\MaintenanceTracker\Db\ChangeRecordMapper;

final class ChangeJournal {
	public function __construct(
		private ChangeRecordMapper $mapper,
		private AuditEventCatalog $auditEvents,
		private AuditService $audit,
		private CurrentUser $currentUser,
	) {
	}

	/** @param array<string, scalar|null> $auditDetails */
	public function record(
		int $workspaceId,
		string $entityType,
		string $entityUuid,
		string $operation,
		int $revision,
		int $changedAt,
		?string $auditEventType = null,
		array $auditDetails = [],
	): void {
		$change = new ChangeRecord();
		$change->setWorkspaceId($workspaceId);
		$change->setEntityType($entityType);
		$change->setEntityUuid($entityUuid);
		$change->setOperation($operation);
		$change->setRevision($revision);
		$change->setChangedAt($changedAt);
		$this->mapper->insert($change);

		$eventType = $auditEventType ?? $this->auditEvents->eventForChange($entityType, $operation, $revision);
		if ($eventType !== null) {
			$this->audit->record(
				$workspaceId,
				$eventType,
				$this->currentUser->uid(),
				$entityUuid,
				$revision,
				$auditDetails,
			);
		}
	}
}
