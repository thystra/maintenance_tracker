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
 * @method string getUuid()
 * @method void setUuid(string $value)
 * @method string getMeterKey()
 * @method void setMeterKey(string $value)
 * @method string getName()
 * @method void setName(string $value)
 * @method string getDimension()
 * @method void setDimension(string $value)
 * @method string getCanonicalUnit()
 * @method void setCanonicalUnit(string $value)
 * @method string getDisplayUnit()
 * @method void setDisplayUnit(string $value)
 * @method bool getMonotonic()
 * @method void setMonotonic(bool $value)
 * @method int getRevision()
 * @method void setRevision(int $value)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $value)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $value)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $value)
 */
final class Meter extends Entity {
	protected int $workspaceId = 0;
	protected int $assetId = 0;
	protected ?int $componentId = null;
	protected string $uuid = '';
	protected string $meterKey = '';
	protected string $name = '';
	protected string $dimension = '';
	protected string $canonicalUnit = '';
	protected string $displayUnit = '';
	protected bool $monotonic = true;
	protected int $revision = 1;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('assetId', Types::BIGINT);
		$this->addType('componentId', Types::BIGINT);
		$this->addType('uuid', Types::STRING);
		$this->addType('meterKey', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('dimension', Types::STRING);
		$this->addType('canonicalUnit', Types::STRING);
		$this->addType('displayUnit', Types::STRING);
		$this->addType('monotonic', Types::BOOLEAN);
		$this->addType('revision', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('deletedAt', Types::BIGINT);
	}
}
