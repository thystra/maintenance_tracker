<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<WorkDefinition> */
final class WorkDefinitionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'maint_work_defs', WorkDefinition::class);
	}

	/** @return list<WorkDefinition> */
	public function findForAsset(int $workspaceId, int $assetId, bool $includeDeleted = false, ?bool $scheduled = null): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('maint_work_defs')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('asset_id', $query->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->orderBy('title', 'ASC')->addOrderBy('id', 'ASC');
		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}
		if ($scheduled === true) {
			$query->andWhere($query->expr()->neq('schedule_type', $query->createNamedParameter('none', IQueryBuilder::PARAM_STR)));
		} elseif ($scheduled === false) {
			$query->andWhere($query->expr()->eq('schedule_type', $query->createNamedParameter('none', IQueryBuilder::PARAM_STR)));
		}
		return $this->findEntities($query);
	}

	public function findByUuid(int $workspaceId, string $uuid, bool $includeDeleted = false): WorkDefinition {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('maint_work_defs')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('uuid', $query->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}
		return $this->findEntity($query);
	}

	/** @return list<WorkDefinition> */
	public function findByAssetAndKey(int $workspaceId, int $assetId, string $key): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('maint_work_defs')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('asset_id', $query->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('definition_key', $query->createNamedParameter($key, IQueryBuilder::PARAM_STR)))
			->andWhere($query->expr()->isNull('deleted_at'));
		return $this->findEntities($query);
	}

	public function countActiveForGroup(int $workspaceId, int $groupId): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('*', 'count'))->from('maint_work_defs')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('group_id', $query->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNull('deleted_at'));
		$result = $query->executeQuery();
		try {
			return (int)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}

	public function updateWithExpectedRevision(WorkDefinition $definition, int $expectedRevision): bool {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_work_defs')
			->set('group_id', $this->nullableIntParameter($query, $definition->getGroupId()))
			->set('definition_key', $query->createNamedParameter($definition->getDefinitionKey(), IQueryBuilder::PARAM_STR))
			->set('title', $query->createNamedParameter($definition->getTitle(), IQueryBuilder::PARAM_STR))
			->set('kind', $query->createNamedParameter($definition->getKind(), IQueryBuilder::PARAM_STR))
			->set('instructions', $this->nullableStringParameter($query, $definition->getInstructions()))
			->set('notes', $this->nullableStringParameter($query, $definition->getNotes()))
			->set('status', $query->createNamedParameter($definition->getStatus(), IQueryBuilder::PARAM_STR))
			->set('schedule_type', $query->createNamedParameter($definition->getScheduleType(), IQueryBuilder::PARAM_STR))
			->set('schedule_combination', $this->nullableStringParameter($query, $definition->getScheduleCombination()))
			->set('revision', $query->createNamedParameter($definition->getRevision(), IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter($definition->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->set('deleted_at', $this->nullableIntParameter($query, $definition->getDeletedAt()))
			->where($query->expr()->eq('id', $query->createNamedParameter($definition->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('workspace_id', $query->createNamedParameter($definition->getWorkspaceId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('revision', $query->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNull('deleted_at'));
		return $query->executeStatement() === 1;
	}

	private function nullableStringParameter(IQueryBuilder $query, ?string $value): IParameter {
		return $query->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR);
	}
	private function nullableIntParameter(IQueryBuilder $query, ?int $value): IParameter {
		return $query->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT);
	}
}
