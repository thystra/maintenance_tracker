<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Workspace>
 */
final class WorkspaceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'maint_spaces', Workspace::class);
	}

	/**
	 * Serialize workspace mutations across different member accounts. Changing a
	 * dedicated token guarantees a physical row update even when multiple writes
	 * occur in the same second; the enclosing transaction holds that write lock
	 * until the operation commits.
	 */
	public function serializeWrite(int $workspaceId, string $lockToken): void {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_spaces')
			->set(
				'write_lock_token',
				$query->createNamedParameter($lockToken, IQueryBuilder::PARAM_STR),
			)
			->where($query->expr()->eq(
				'id',
				$query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			))
			->andWhere($query->expr()->isNull('deleted_at'));

		if ($query->executeStatement() !== 1) {
			throw new \RuntimeException('Workspace disappeared while acquiring its write lock');
		}
	}

	public function findPersonalByOwner(string $ownerUid): Workspace {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_spaces')
			->where($query->expr()->eq(
				'personal_owner_uid',
				$query->createNamedParameter($ownerUid, IQueryBuilder::PARAM_STR),
			))
			->andWhere($query->expr()->isNull('deleted_at'));

		return $this->findEntity($query);
	}

	public function findByUuid(string $uuid): Workspace {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_spaces')
			->where($query->expr()->eq(
				'uuid',
				$query->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			))
			->andWhere($query->expr()->isNull('deleted_at'));

		return $this->findEntity($query);
	}
}
