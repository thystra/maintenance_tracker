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

/** @extends QBMapper<MaintenanceOccurrence> */
final class MaintenanceOccurrenceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'maint_occurrences', MaintenanceOccurrence::class);
	}

	/** @return list<MaintenanceOccurrence> */
	public function findForAsset(int $workspaceId, int $assetId, bool $includeClosed = false): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('maint_occurrences')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('asset_id', $query->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->orderBy('opened_at', 'DESC')->addOrderBy('id', 'DESC');
		if (!$includeClosed) {
			$query->andWhere($query->expr()->eq('open_marker', $query->createNamedParameter('open', IQueryBuilder::PARAM_STR)));
		}
		return $this->findEntities($query);
	}

	public function updateWithExpectedRevision(MaintenanceOccurrence $occurrence, int $expectedRevision): bool {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_occurrences')
			->set('open_marker', $this->string($query, $occurrence->getOpenMarker()))
			->set('closed_at', $this->number($query, $occurrence->getClosedAt()))
			->set('closed_reason', $this->string($query, $occurrence->getClosedReason()))
			->set('revision', $query->createNamedParameter($occurrence->getRevision(), IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter($occurrence->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('id', $query->createNamedParameter($occurrence->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('workspace_id', $query->createNamedParameter($occurrence->getWorkspaceId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('revision', $query->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		return $query->executeStatement() === 1;
	}

	private function string(IQueryBuilder $query, ?string $value): IParameter {
		return $query->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR);
	}

	private function number(IQueryBuilder $query, ?int $value): IParameter {
		return $query->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT);
	}
}
