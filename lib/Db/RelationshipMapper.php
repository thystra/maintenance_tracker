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

/** @extends QBMapper<Relationship> */
final class RelationshipMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'maint_relationships', Relationship::class);
	}

	/** @return list<Relationship> */
	public function findForWorkspace(int $workspaceId, bool $includeDeleted = false): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_relationships')
			->where($query->expr()->eq(
				'workspace_id',
				$query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			))
			->orderBy('id', 'ASC');

		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}

		return $this->findEntities($query);
	}

	public function findByUuid(int $workspaceId, string $uuid, bool $includeDeleted = false): Relationship {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_relationships')
			->where($query->expr()->eq(
				'workspace_id',
				$query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			))
			->andWhere($query->expr()->eq(
				'uuid',
				$query->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			));

		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}

		return $this->findEntity($query);
	}

	/** @return list<Relationship> */
	public function findDefaultsForSource(int $workspaceId, int $sourceAssetId, string $typeKey, ?string $contextKey): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_relationships')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('source_asset_id', $query->createNamedParameter($sourceAssetId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('type_key', $query->createNamedParameter($typeKey, IQueryBuilder::PARAM_STR)))
			->andWhere($query->expr()->eq('is_default', $query->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($query->expr()->isNull('deleted_at'));

		if ($contextKey === null) {
			$query->andWhere($query->expr()->isNull('context_key'));
		} else {
			$query->andWhere($query->expr()->eq('context_key', $query->createNamedParameter($contextKey, IQueryBuilder::PARAM_STR)));
		}

		return $this->findEntities($query);
	}

	public function updateWithExpectedRevision(Relationship $relationship, int $expectedRevision): bool {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_relationships')
			->set('context_key', $this->nullableStringParameter($query, $relationship->getContextKey()))
			->set('is_default', $query->createNamedParameter($relationship->getIsDefault(), IQueryBuilder::PARAM_BOOL))
			->set('notes', $this->nullableStringParameter($query, $relationship->getNotes()))
			->set('revision', $query->createNamedParameter($relationship->getRevision(), IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter($relationship->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->set('deleted_at', $this->nullableIntParameter($query, $relationship->getDeletedAt()))
			->where($query->expr()->eq('id', $query->createNamedParameter($relationship->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('workspace_id', $query->createNamedParameter($relationship->getWorkspaceId(), IQueryBuilder::PARAM_INT)))
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
