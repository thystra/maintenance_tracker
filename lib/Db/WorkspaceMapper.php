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
