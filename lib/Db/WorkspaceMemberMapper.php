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
 * @extends QBMapper<WorkspaceMember>
 */
final class WorkspaceMemberMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'maint_members', WorkspaceMember::class);
	}

	public function findByWorkspaceAndUser(int $workspaceId, string $userUid): WorkspaceMember {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_members')
			->where($query->expr()->eq(
				'workspace_id',
				$query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			))
			->andWhere($query->expr()->eq(
				'user_uid',
				$query->createNamedParameter($userUid, IQueryBuilder::PARAM_STR),
			));

		return $this->findEntity($query);
	}

	/**
	 * @return list<WorkspaceMember>
	 */
	public function findForWorkspace(int $workspaceId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_members')
			->where($query->expr()->eq(
				'workspace_id',
				$query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			))
			->orderBy('id', 'ASC');

		return $this->findEntities($query);
	}

	/**
	 * @return list<WorkspaceMember>
	 */
	public function findForUser(string $userUid): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_members')
			->where($query->expr()->eq(
				'user_uid',
				$query->createNamedParameter($userUid, IQueryBuilder::PARAM_STR),
			))
			->orderBy('id', 'ASC');

		return $this->findEntities($query);
	}
}
