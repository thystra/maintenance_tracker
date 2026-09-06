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

/** @extends QBMapper<WorkGroup> */
final class WorkGroupMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'maint_work_groups', WorkGroup::class);
	}

	/** @return list<WorkGroup> */
	public function findForAsset(int $workspaceId, int $assetId, bool $includeDeleted = false): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_work_groups')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('asset_id', $query->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('name', 'ASC')
			->addOrderBy('id', 'ASC');
		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}
		return $this->findEntities($query);
	}

	public function findById(int $workspaceId, int $id, bool $includeDeleted = false): WorkGroup {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('maint_work_groups')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}
		return $this->findEntity($query);
	}

	public function findByUuid(int $workspaceId, string $uuid, bool $includeDeleted = false): WorkGroup {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('maint_work_groups')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('uuid', $query->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}
		return $this->findEntity($query);
	}

	/** @return list<WorkGroup> */
	public function findByAssetAndKey(int $workspaceId, int $assetId, string $key): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('maint_work_groups')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('asset_id', $query->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('group_key', $query->createNamedParameter($key, IQueryBuilder::PARAM_STR)))
			->andWhere($query->expr()->isNull('deleted_at'));
		return $this->findEntities($query);
	}

	public function updateWithExpectedRevision(WorkGroup $group, int $expectedRevision): bool {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_work_groups')
			->set('group_key', $query->createNamedParameter($group->getGroupKey(), IQueryBuilder::PARAM_STR))
			->set('name', $query->createNamedParameter($group->getName(), IQueryBuilder::PARAM_STR))
			->set('description', $this->nullableStringParameter($query, $group->getDescription()))
			->set('sort_order', $query->createNamedParameter($group->getSortOrder(), IQueryBuilder::PARAM_INT))
			->set('revision', $query->createNamedParameter($group->getRevision(), IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter($group->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->set('deleted_at', $this->nullableIntParameter($query, $group->getDeletedAt()))
			->where($query->expr()->eq('id', $query->createNamedParameter($group->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('workspace_id', $query->createNamedParameter($group->getWorkspaceId(), IQueryBuilder::PARAM_INT)))
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
