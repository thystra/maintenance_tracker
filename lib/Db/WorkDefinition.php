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
 * @method void setWorkspaceId(int $value)
 * @method int getAssetId()
 * @method void setAssetId(int $value)
 * @method int|null getComponentId()
 * @method void setComponentId(?int $value)
 * @method int|null getGroupId()
 * @method void setGroupId(?int $value)
 * @method string getUuid()
 * @method void setUuid(string $value)
 * @method string getDefinitionKey()
 * @method void setDefinitionKey(string $value)
 * @method string getTitle()
 * @method void setTitle(string $value)
 * @method string getKind()
 * @method void setKind(string $value)
 * @method string|null getInstructions()
 * @method void setInstructions(?string $value)
 * @method string|null getNotes()
 * @method void setNotes(?string $value)
 * @method string getStatus()
 * @method void setStatus(string $value)
 * @method string|null getScheduleType()
 * @method void setScheduleType(?string $value)
 * @method string|null getScheduleCombination()
 * @method void setScheduleCombination(?string $value)
 * @method int getRevision()
 * @method void setRevision(int $value)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $value)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $value)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $value)
 */
final class WorkDefinition extends Entity {
	protected int $workspaceId = 0;
	protected int $assetId = 0;
	protected ?int $componentId = null;
	protected ?int $groupId = null;
	protected string $uuid = '';
	protected string $definitionKey = '';
	protected string $title = '';
	protected string $kind = '';
	protected ?string $instructions = null;
	protected ?string $notes = null;
	protected string $status = 'active';
	// Null is a pre-persistence sentinel only. WorkDefinitionService requires an explicit
	// schedule and the database column is NOT NULL, so null is never a stored policy.
	protected ?string $scheduleType = null;
	protected ?string $scheduleCombination = null;
	protected int $revision = 1;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('assetId', Types::BIGINT);
		$this->addType('componentId', Types::BIGINT);
		$this->addType('groupId', Types::BIGINT);
		$this->addType('uuid', Types::STRING);
		$this->addType('definitionKey', Types::STRING);
		$this->addType('title', Types::STRING);
		$this->addType('kind', Types::STRING);
		$this->addType('instructions', Types::STRING);
		$this->addType('notes', Types::STRING);
		$this->addType('status', Types::STRING);
		$this->addType('scheduleType', Types::STRING);
		$this->addType('scheduleCombination', Types::STRING);
		$this->addType('revision', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('deletedAt', Types::BIGINT);
	}
}
