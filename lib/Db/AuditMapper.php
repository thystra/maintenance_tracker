<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Append/read-only persistence boundary for audit events.
 *
 * This class intentionally does not expose update or delete methods.
 */
final class AuditMapper {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function append(
		int $workspaceId,
		string $eventType,
		int $eventVersion,
		string $actorUid,
		string $subjectType,
		string $subjectId,
		?int $subjectRevision,
		string $level,
		?string $detailsJson,
		int $createdAt,
	): void {
		$query = $this->db->getQueryBuilder();
		$values = [
			'workspace_id' => $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'event_type' => $query->createNamedParameter($eventType, IQueryBuilder::PARAM_STR),
			'event_version' => $query->createNamedParameter($eventVersion, IQueryBuilder::PARAM_INT),
			'actor_uid' => $query->createNamedParameter($actorUid, IQueryBuilder::PARAM_STR),
			'subject_type' => $query->createNamedParameter($subjectType, IQueryBuilder::PARAM_STR),
			'subject_id' => $query->createNamedParameter($subjectId, IQueryBuilder::PARAM_STR),
			'level' => $query->createNamedParameter($level, IQueryBuilder::PARAM_STR),
			'created_at' => $query->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
		];
		$values['subject_revision'] = $subjectRevision === null
			? $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
			: $query->createNamedParameter($subjectRevision, IQueryBuilder::PARAM_INT);
		$values['details_json'] = $detailsJson === null
			? $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
			: $query->createNamedParameter($detailsJson, IQueryBuilder::PARAM_STR);

		$query->insert('maint_audit')->values($values);
		$query->executeStatement();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function findForWorkspace(int $workspaceId, int $limit): array {
		$query = $this->db->getQueryBuilder();
		$query->select(
			'id',
			'event_type',
			'event_version',
			'actor_uid',
			'subject_type',
			'subject_id',
			'subject_revision',
			'level',
			'details_json',
			'created_at',
		)
			->from('maint_audit')
			->where($query->expr()->eq(
				'workspace_id',
				$query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		$result = $query->executeQuery();
		try {
			/** @var list<array<string, mixed>> $rows */
			$rows = $result->fetchAllAssociative();

			return $rows;
		} finally {
			$result->closeCursor();
		}
	}
}
