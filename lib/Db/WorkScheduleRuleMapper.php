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

/** @extends QBMapper<WorkScheduleRule> */
final class WorkScheduleRuleMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'maint_work_sched', WorkScheduleRule::class);
	}

	/** @return list<WorkScheduleRule> */
	public function findForDefinition(int $workspaceId, int $definitionId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('maint_work_sched')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('definition_id', $query->createNamedParameter($definitionId, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC');
		return $this->findEntities($query);
	}

	public function deleteForDefinition(int $workspaceId, int $definitionId): void {
		$query = $this->db->getQueryBuilder();
		$query->delete('maint_work_sched')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('definition_id', $query->createNamedParameter($definitionId, IQueryBuilder::PARAM_INT)));
		$query->executeStatement();
	}

	public function countActiveForMeter(int $workspaceId, int $meterId): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('s.id', 'count'))
			->from('maint_work_sched', 's')
			->innerJoin('s', 'maint_work_defs', 'd', $query->expr()->eq('s.definition_id', 'd.id'))
			->where($query->expr()->eq('s.workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('s.meter_id', $query->createNamedParameter($meterId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('d.workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNull('d.deleted_at'));
		$result = $query->executeQuery();
		try {
			return (int)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}
}
