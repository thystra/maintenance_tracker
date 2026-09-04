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

/** @extends QBMapper<Assignment> */
final class AssignmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'maint_assignments', Assignment::class);
	}

	/** @return list<Assignment> */
	public function findForWorkspace(int $workspaceId, bool $includeDeleted = false): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_assignments')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}

		return $this->findEntities($query);
	}

	public function findByUuid(int $workspaceId, string $uuid, bool $includeDeleted = false): Assignment {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_assignments')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('uuid', $query->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));

		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}

		return $this->findEntity($query);
	}

	/** @return list<Assignment> */
	public function findPrimariesForSource(int $workspaceId, int $sourceAssetId, string $typeKey, ?string $contextKey): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_assignments')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('source_asset_id', $query->createNamedParameter($sourceAssetId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('type_key', $query->createNamedParameter($typeKey, IQueryBuilder::PARAM_STR)))
			->andWhere($query->expr()->eq('is_primary', $query->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($query->expr()->isNull('deleted_at'));

		if ($contextKey === null) {
			$query->andWhere($query->expr()->isNull('context_key'));
		} else {
			$query->andWhere($query->expr()->eq('context_key', $query->createNamedParameter($contextKey, IQueryBuilder::PARAM_STR)));
		}

		return $this->findEntities($query);
	}

	public function updateWithExpectedRevision(Assignment $assignment, int $expectedRevision): bool {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_assignments')
			->set('context_key', $this->nullableStringParameter($query, $assignment->getContextKey()))
			->set('is_primary', $query->createNamedParameter($assignment->getIsPrimary(), IQueryBuilder::PARAM_BOOL))
			->set('effective_from', $query->createNamedParameter($assignment->getEffectiveFrom(), IQueryBuilder::PARAM_STR))
			->set('effective_until', $this->nullableStringParameter($query, $assignment->getEffectiveUntil()))
			->set('notes', $this->nullableStringParameter($query, $assignment->getNotes()))
			->set('revision', $query->createNamedParameter($assignment->getRevision(), IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter($assignment->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->set('deleted_at', $this->nullableIntParameter($query, $assignment->getDeletedAt()))
			->where($query->expr()->eq('id', $query->createNamedParameter($assignment->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('workspace_id', $query->createNamedParameter($assignment->getWorkspaceId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('revision', $query->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNull('deleted_at'));

		return $query->executeStatement() === 1;
	}

	private function nullableStringParameter(IQueryBuilder $query, ?string $value): IParameter {
		return $query->createNamedParameter(
			$value,
			$value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR,
		);
	}

	private function nullableIntParameter(IQueryBuilder $query, ?int $value): IParameter {
		return $query->createNamedParameter(
			$value,
			$value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT,
		);
	}
}
