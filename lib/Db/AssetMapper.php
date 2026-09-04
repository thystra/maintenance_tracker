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

/**
 * @extends QBMapper<Asset>
 */
final class AssetMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'maint_assets', Asset::class);
	}

	public function findById(
		int $workspaceId,
		int $id,
		bool $includeDeleted = false,
	): Asset {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_assets')
			->where($query->expr()->eq(
				'workspace_id',
				$query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			))
			->andWhere($query->expr()->eq(
				'id',
				$query->createNamedParameter($id, IQueryBuilder::PARAM_INT),
			));

		if (!$includeDeleted) {
			$query->andWhere($query->expr()->isNull('deleted_at'));
		}

		return $this->findEntity($query);
	}

	public function findByUuid(
		int $workspaceId,
		string $uuid,
		bool $includeDeleted = false,
	): Asset {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_assets')
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

	/**
	 * @return list<Asset>
	 */
	public function findPageForWorkspace(
		int $workspaceId,
		int $afterId,
		int $limit,
	): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_assets')
			->where($query->expr()->eq(
				'workspace_id',
				$query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			))
			->andWhere($query->expr()->isNull('deleted_at'))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		if ($afterId > 0) {
			$query->andWhere($query->expr()->gt(
				'id',
				$query->createNamedParameter($afterId, IQueryBuilder::PARAM_INT),
			));
		}

		return $this->findEntities($query);
	}

	/**
	 * Atomically update an asset only if its last-seen revision still matches.
	 */
	public function updateWithExpectedRevision(Asset $asset, int $expectedRevision): bool {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_assets')
			->set('category_key', $this->stringParameter($query, $asset->getCategoryKey()))
			->set('asset_class', $this->stringParameter($query, $asset->getAssetClass()))
			->set('name', $this->stringParameter($query, $asset->getName()))
			->set('manufacturer', $this->nullableStringParameter($query, $asset->getManufacturer()))
			->set('model', $this->nullableStringParameter($query, $asset->getModel()))
			->set('model_year', $this->nullableIntParameter($query, $asset->getModelYear()))
			->set('serial_number', $this->nullableStringParameter($query, $asset->getSerialNumber()))
			->set('notes', $this->nullableStringParameter($query, $asset->getNotes()))
			->set('status', $this->stringParameter($query, $asset->getStatus()))
			->set('profile_key', $this->nullableStringParameter($query, $asset->getProfileKey()))
			->set('profile_version', $this->nullableStringParameter($query, $asset->getProfileVersion()))
			->set('acquired_on', $this->nullableStringParameter($query, $asset->getAcquiredOn()))
			->set('purchase_price_minor', $this->nullableIntParameter($query, $asset->getPurchasePriceMinor()))
			->set('currency', $this->nullableStringParameter($query, $asset->getCurrency()))
			->set('revision', $query->createNamedParameter($asset->getRevision(), IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter($asset->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->set('deleted_at', $this->nullableIntParameter($query, $asset->getDeletedAt()))
			->where($query->expr()->eq(
				'id',
				$query->createNamedParameter($asset->getId(), IQueryBuilder::PARAM_INT),
			))
			->andWhere($query->expr()->eq(
				'revision',
				$query->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT),
			))
			->andWhere($query->expr()->eq(
				'workspace_id',
				$query->createNamedParameter($asset->getWorkspaceId(), IQueryBuilder::PARAM_INT),
			))
			->andWhere($query->expr()->isNull('deleted_at'));

		return $query->executeStatement() === 1;
	}

	private function stringParameter(IQueryBuilder $query, string $value): IParameter {
		return $query->createNamedParameter($value, IQueryBuilder::PARAM_STR);
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
