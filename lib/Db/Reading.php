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
 * @method int getMeterId()
 * @method void setMeterId(int $value)
 * @method string getUuid()
 * @method void setUuid(string $value)
 * @method int getObservedAt()
 * @method void setObservedAt(int $value)
 * @method int getCanonicalValue()
 * @method void setCanonicalValue(int $value)
 * @method string getOriginalValue()
 * @method void setOriginalValue(string $value)
 * @method string getOriginalUnit()
 * @method void setOriginalUnit(string $value)
 * @method string getSourceType()
 * @method void setSourceType(string $value)
 * @method string|null getSourceRef()
 * @method void setSourceRef(?string $value)
 * @method string|null getNotes()
 * @method void setNotes(?string $value)
 * @method int|null getSupersedesId()
 * @method void setSupersedesId(?int $value)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $value)
 */
final class Reading extends Entity {
	protected int $workspaceId = 0;
	protected int $meterId = 0;
	protected string $uuid = '';
	protected int $observedAt = 0;
	protected int $canonicalValue = 0;
	protected string $originalValue = '';
	protected string $originalUnit = '';
	protected string $sourceType = 'manual';
	protected ?string $sourceRef = null;
	protected ?string $notes = null;
	protected ?int $supersedesId = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('meterId', Types::BIGINT);
		$this->addType('uuid', Types::STRING);
		$this->addType('observedAt', Types::BIGINT);
		$this->addType('canonicalValue', Types::BIGINT);
		$this->addType('originalValue', Types::STRING);
		$this->addType('originalUnit', Types::STRING);
		$this->addType('sourceType', Types::STRING);
		$this->addType('sourceRef', Types::TEXT);
		$this->addType('notes', Types::TEXT);
		$this->addType('supersedesId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
	}
}
