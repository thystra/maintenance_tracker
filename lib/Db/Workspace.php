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
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method string|null getPersonalOwnerUid()
 * @method void setPersonalOwnerUid(?string $personalOwnerUid)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method string|null getWriteLockToken()
 * @method void setWriteLockToken(?string $writeLockToken)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $deletedAt)
 */
final class Workspace extends Entity {
	protected string $uuid = '';
	protected string $ownerUid = '';
	protected ?string $personalOwnerUid = null;
	protected string $name = '';
	protected string $kind = 'personal';
	protected int $revision = 1;
	protected ?string $writeLockToken = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('ownerUid', Types::STRING);
		$this->addType('personalOwnerUid', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('kind', Types::STRING);
		$this->addType('revision', Types::INTEGER);
		$this->addType('writeLockToken', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('deletedAt', Types::BIGINT);
	}

	/**
	 * @return array{
	 *     uuid: string,
	 *     name: string,
	 *     kind: string,
	 *     role: string,
	 *     revision: int,
	 *     createdAt: string,
	 *     updatedAt: string
	 * }
	 */
	public function toApi(string $role): array {
		return [
			'uuid' => $this->getUuid(),
			'name' => $this->getName(),
			'kind' => $this->getKind(),
			'role' => $role,
			'revision' => $this->getRevision(),
			'createdAt' => self::formatTimestamp($this->getCreatedAt()),
			'updatedAt' => self::formatTimestamp($this->getUpdatedAt()),
		];
	}

	private static function formatTimestamp(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
	}
}
