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
 * @method string getUuid()
 * @method void setUuid(string $value)
 * @method int getSourceAssetId()
 * @method void setSourceAssetId(int $value)
 * @method int getTargetAssetId()
 * @method void setTargetAssetId(int $value)
 * @method string getTypeKey()
 * @method void setTypeKey(string $value)
 * @method string|null getContextKey()
 * @method void setContextKey(?string $value)
 * @method bool getIsPrimary()
 * @method void setIsPrimary(bool $value)
 * @method string getEffectiveFrom()
 * @method void setEffectiveFrom(string $value)
 * @method string|null getEffectiveUntil()
 * @method void setEffectiveUntil(?string $value)
 * @method string|null getNotes()
 * @method void setNotes(?string $value)
 * @method int getRevision()
 * @method void setRevision(int $value)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $value)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $value)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $value)
 */
final class Assignment extends Entity {
	protected int $workspaceId = 0;
	protected string $uuid = '';
	protected int $sourceAssetId = 0;
	protected int $targetAssetId = 0;
	protected string $typeKey = '';
	protected ?string $contextKey = null;
	protected bool $isPrimary = false;
	protected string $effectiveFrom = '';
	protected ?string $effectiveUntil = null;
	protected ?string $notes = null;
	protected int $revision = 1;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('uuid', Types::STRING);
		$this->addType('sourceAssetId', Types::BIGINT);
		$this->addType('targetAssetId', Types::BIGINT);
		$this->addType('typeKey', Types::STRING);
		$this->addType('contextKey', Types::STRING);
		$this->addType('isPrimary', Types::BOOLEAN);
		$this->addType('effectiveFrom', Types::STRING);
		$this->addType('effectiveUntil', Types::STRING);
		$this->addType('notes', Types::TEXT);
		$this->addType('revision', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('deletedAt', Types::BIGINT);
	}
}
