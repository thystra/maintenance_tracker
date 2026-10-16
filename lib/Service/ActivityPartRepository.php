<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Append/read-only persistence for parts-used snapshots. This class
 * intentionally exposes no update or delete path.
 */
final class ActivityPartRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string,mixed> $row */
	public function append(array $row): void {
		$q = $this->db->getQueryBuilder();
		$values = [];
		foreach (['workspace_id', 'activity_id', 'position', 'part_id', 'quantity_milli', 'vendor_id'] as $column) {
			$values[$column] = $q->createNamedParameter($row[$column], $row[$column] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT);
		}
		foreach (['uuid', 'part_uuid', 'manufacturer', 'part_number', 'description', 'quantity_unit', 'vendor_uuid', 'vendor_name', 'notes'] as $column) {
			$values[$column] = $q->createNamedParameter($row[$column], $row[$column] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR);
		}
		$q->insert('maint_activity_parts')->values($values);
		$q->executeStatement();
	}

	/** @return list<array<string,mixed>> */
	public function findForActivity(int $workspaceId, int $activityId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_activity_parts')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('activity_id', $q->createNamedParameter($activityId, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC');
		$result = $q->executeQuery();
		try {
			return $result->fetchAllAssociative();
		} finally {
			$result->closeCursor();
		}
	}

	public function uuidExists(string $uuid): bool {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('maint_activity_parts')
			->where($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		$result = $q->executeQuery();
		try {
			return $result->fetchOne() !== false;
		} finally {
			$result->closeCursor();
		}
	}
}
