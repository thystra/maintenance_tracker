<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getWorkspaceId()
 * @method void setWorkspaceId(int $workspaceId)
 * @method string getEntityType()
 * @method void setEntityType(string $entityType)
 * @method string getEntityUuid()
 * @method void setEntityUuid(string $entityUuid)
 * @method string getOperation()
 * @method void setOperation(string $operation)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method int getChangedAt()
 * @method void setChangedAt(int $changedAt)
 */
final class ChangeRecord extends Entity {
	protected int $workspaceId = 0;
	protected string $entityType = '';
	protected string $entityUuid = '';
	protected string $operation = '';
	protected int $revision = 0;
	protected int $changedAt = 0;

	public function __construct() {
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('entityType', Types::STRING);
		$this->addType('entityUuid', Types::STRING);
		$this->addType('operation', Types::STRING);
		$this->addType('revision', Types::INTEGER);
		$this->addType('changedAt', Types::BIGINT);
	}
}
