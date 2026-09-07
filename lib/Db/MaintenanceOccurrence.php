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
 * @method int getDefinitionId()
 * @method void setDefinitionId(int $value)
 * @method string getDefinitionUuid()
 * @method void setDefinitionUuid(string $value)
 * @method string getUuid()
 * @method void setUuid(string $value)
 * @method string|null getBaselineActivityUuid()
 * @method void setBaselineActivityUuid(?string $value)
 * @method string|null getOpenMarker()
 * @method void setOpenMarker(?string $value)
 * @method int getOpenedAt()
 * @method void setOpenedAt(int $value)
 * @method int|null getClosedAt()
 * @method void setClosedAt(?int $value)
 * @method string|null getClosedReason()
 * @method void setClosedReason(?string $value)
 * @method int getRevision()
 * @method void setRevision(int $value)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $value)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $value)
 */
final class MaintenanceOccurrence extends Entity {
	protected int $workspaceId = 0;
	protected int $assetId = 0;
	protected int $definitionId = 0;
	protected string $definitionUuid = '';
	protected string $uuid = '';
	protected ?string $baselineActivityUuid = null;
	protected ?string $openMarker = 'open';
	protected int $openedAt = 0;
	protected ?int $closedAt = null;
	protected ?string $closedReason = null;
	protected int $revision = 1;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		foreach (['workspaceId','assetId','definitionId','openedAt','closedAt','createdAt','updatedAt'] as $field) {
			$this->addType($field, Types::BIGINT);
		}
		$this->addType('definitionUuid', Types::STRING);
		$this->addType('uuid', Types::STRING);
		$this->addType('baselineActivityUuid', Types::STRING);
		$this->addType('openMarker', Types::STRING);
		$this->addType('closedReason', Types::STRING);
		$this->addType('revision', Types::INTEGER);
	}
}
