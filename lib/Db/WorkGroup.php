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
 * @method string getUuid()
 * @method void setUuid(string $value)
 * @method string getGroupKey()
 * @method void setGroupKey(string $value)
 * @method string getName()
 * @method void setName(string $value)
 * @method string|null getDescription()
 * @method void setDescription(?string $value)
 * @method int getSortOrder()
 * @method void setSortOrder(int $value)
 * @method int getRevision()
 * @method void setRevision(int $value)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $value)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $value)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $value)
 */
final class WorkGroup extends Entity {
	protected int $workspaceId = 0;
	protected int $assetId = 0;
	protected string $uuid = '';
	protected string $groupKey = '';
	protected string $name = '';
	protected ?string $description = null;
	protected int $sortOrder = 0;
	protected int $revision = 1;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('assetId', Types::BIGINT);
		$this->addType('uuid', Types::STRING);
		$this->addType('groupKey', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('description', Types::STRING);
		$this->addType('sortOrder', Types::INTEGER);
		$this->addType('revision', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('deletedAt', Types::BIGINT);
	}
}
