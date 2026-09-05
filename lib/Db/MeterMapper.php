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

/** @extends QBMapper<Meter> */
final class MeterMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'maint_meters', Meter::class);
	}

	/** @return list<Meter> */
	public function findForAsset(int $workspaceId, int $assetId, bool $includeDeleted = false): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_meters')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('asset_id', $query->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}

		return $this->findEntities($query);
	}

	public function findById(int $workspaceId, int $id, bool $includeDeleted = false): Meter {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_meters')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}

		return $this->findEntity($query);
	}

	public function findByUuid(int $workspaceId, string $uuid, bool $includeDeleted = false): Meter {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_meters')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('uuid', $query->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}

		return $this->findEntity($query);
	}

	/** @return list<Meter> */
	public function findByTargetAndKey(
		int $workspaceId,
		int $assetId,
		?int $componentId,
		string $meterKey,
	): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_meters')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('asset_id', $query->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('meter_key', $query->createNamedParameter($meterKey, IQueryBuilder::PARAM_STR)))
			->andWhere($query->expr()->isNull('deleted_at'));
		if ($componentId === null) {
			$query->andWhere($query->expr()->isNull('component_id'));
		} else {
			$query->andWhere($query->expr()->eq('component_id', $query->createNamedParameter($componentId, IQueryBuilder::PARAM_INT)));
		}

		return $this->findEntities($query);
	}

	public function updateWithExpectedRevision(Meter $meter, int $expectedRevision): bool {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_meters')
			->set('meter_key', $query->createNamedParameter($meter->getMeterKey(), IQueryBuilder::PARAM_STR))
			->set('name', $query->createNamedParameter($meter->getName(), IQueryBuilder::PARAM_STR))
			->set('display_unit', $query->createNamedParameter($meter->getDisplayUnit(), IQueryBuilder::PARAM_STR))
			->set('monotonic', $query->createNamedParameter($meter->getMonotonic(), IQueryBuilder::PARAM_BOOL))
			->set('revision', $query->createNamedParameter($meter->getRevision(), IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter($meter->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->set('deleted_at', $this->nullableIntParameter($query, $meter->getDeletedAt()))
			->where($query->expr()->eq('id', $query->createNamedParameter($meter->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('workspace_id', $query->createNamedParameter($meter->getWorkspaceId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('revision', $query->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));

		return $query->executeStatement() === 1;
	}

	private function nullableIntParameter(IQueryBuilder $query, ?int $value): IParameter {
		return $query->createNamedParameter(
			$value,
			$value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT,
		);
	}
}
