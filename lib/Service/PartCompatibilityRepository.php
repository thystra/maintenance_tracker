<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class PartCompatibilityRepository {
	public function __construct(private IDBConnection $db) {
	}

	public function insert(int $workspaceId, int $assetId, int $partId, string $subjectType, string $subjectUuid, ?int $assetProfileId, int $now): void {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_part_compat')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'asset_id' => $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT),
			'part_id' => $q->createNamedParameter($partId, IQueryBuilder::PARAM_INT),
			'subject_type' => $q->createNamedParameter($subjectType, IQueryBuilder::PARAM_STR),
			'subject_uuid' => $q->createNamedParameter($subjectUuid, IQueryBuilder::PARAM_STR),
			'asset_profile_id' => $q->createNamedParameter($assetProfileId, $assetProfileId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		]);
		$q->executeStatement();
	}

	/** @return list<array<string,mixed>> */
	public function findForAsset(int $workspaceId, int $assetId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('c.subject_type', 'c.subject_uuid', 'p.uuid AS part_uuid', 'p.manufacturer', 'p.part_number', 'p.description', 'p.deleted_at AS part_deleted_at')
			->from('maint_part_compat', 'c')
			->innerJoin('c', 'maint_parts', 'p', $q->expr()->andX(
				$q->expr()->eq('c.part_id', 'p.id'),
				$q->expr()->eq('c.workspace_id', 'p.workspace_id'),
			))
			->where($q->expr()->eq('c.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('c.asset_id', $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->orderBy('c.subject_type', 'ASC')->addOrderBy('c.subject_uuid', 'ASC')->addOrderBy('p.manufacturer', 'ASC')->addOrderBy('p.part_number', 'ASC');
		$result = $q->executeQuery();
		try {
			return $result->fetchAllAssociative();
		} finally {
			$result->closeCursor();
		}
	}

	/**
	 * Active items each part fits, grouped by part id. Manual whole-item fits
	 * have subject type "asset"; component/work-definition rows come from
	 * profiles and fitment.
	 *
	 * @return array<int,list<array{assetUuid:string,assetName:string,source:string}>>
	 */
	public function fitsByPart(int $workspaceId, ?int $partId = null): array {
		$q = $this->db->getQueryBuilder();
		$q->select('c.part_id', 'c.subject_type', 'a.uuid', 'a.name')
			->from('maint_part_compat', 'c')
			->innerJoin('c', 'maint_assets', 'a', $q->expr()->andX(
				$q->expr()->eq('c.asset_id', 'a.id'),
				$q->expr()->eq('c.workspace_id', 'a.workspace_id'),
			))
			->where($q->expr()->eq('c.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('a.deleted_at'))
			->orderBy('a.name', 'ASC')->addOrderBy('a.uuid', 'ASC');
		if ($partId !== null) {
			$q->andWhere($q->expr()->eq('c.part_id', $q->createNamedParameter($partId, IQueryBuilder::PARAM_INT)));
		}
		$result = $q->executeQuery();
		$fits = [];
		try {
			while (($row = $result->fetchAssociative()) !== false) {
				$part = (int)$row['part_id'];
				$asset = (string)$row['uuid'];
				$source = $row['subject_type'] === 'asset' ? 'manual' : 'profile';
				$existing = $fits[$part][$asset] ?? null;
				if ($existing === null || $source === 'manual') {
					$fits[$part][$asset] = ['assetUuid' => $asset, 'assetName' => (string)$row['name'], 'source' => $source];
				}
			}
		} finally {
			$result->closeCursor();
		}
		return array_map('array_values', $fits);
	}

	public function hasManualFit(int $workspaceId, int $partId, int $assetId): bool {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('maint_part_compat')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('part_id', $q->createNamedParameter($partId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('asset_id', $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('subject_type', $q->createNamedParameter('asset', IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);
		$result = $q->executeQuery();
		try {
			return $result->fetchOne() !== false;
		} finally {
			$result->closeCursor();
		}
	}

	public function deleteManualFit(int $workspaceId, int $partId, int $assetId): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('maint_part_compat')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('part_id', $q->createNamedParameter($partId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('asset_id', $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('subject_type', $q->createNamedParameter('asset', IQueryBuilder::PARAM_STR)));
		$q->executeStatement();
	}
}
