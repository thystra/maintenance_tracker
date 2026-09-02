<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/** @method int getWorkspaceId() @method void setWorkspaceId(int $workspaceId) @method string getUuid() @method void setUuid(string $uuid) @method string getCategoryKey() @method void setCategoryKey(string $categoryKey) @method string getName() @method void setName(string $name) @method string getDefaultClass() @method void setDefaultClass(string $defaultClass) @method string|null getDescription() @method void setDescription(?string $description) @method int getRevision() @method void setRevision(int $revision) @method int getCreatedAt() @method void setCreatedAt(int $createdAt) @method int getUpdatedAt() @method void setUpdatedAt(int $updatedAt) @method int|null getDeletedAt() @method void setDeletedAt(?int $deletedAt) */
final class Category extends Entity {
	protected int $workspaceId = 0;
	protected string $uuid = '';
	protected string $categoryKey = '';
	protected string $name = '';
	protected string $defaultClass = 'other';
	protected ?string $description = null;
	protected int $revision = 1;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $deletedAt = null;

	public function __construct() {
		foreach (['workspaceId' => Types::BIGINT, 'uuid' => Types::STRING, 'categoryKey' => Types::STRING, 'name' => Types::STRING, 'defaultClass' => Types::STRING, 'description' => Types::TEXT, 'revision' => Types::INTEGER, 'createdAt' => Types::BIGINT, 'updatedAt' => Types::BIGINT, 'deletedAt' => Types::BIGINT] as $field => $type) {
			$this->addType($field, $type);
		}
	}

	public function toApi(): array {
		return [
			'uuid' => $this->getUuid(), 'key' => $this->getCategoryKey(), 'name' => $this->getName(),
			'defaultAssetClass' => $this->getDefaultClass(), 'description' => $this->getDescription(),
			'builtIn' => false, 'revision' => $this->getRevision(),
		];
	}
}
