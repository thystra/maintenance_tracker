<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Workspace-scoped persistence for part/vendor outside links. */
final class LinkRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return list<array<string,mixed>> */
	public function findActive(int $workspaceId, string $subjectType, int $subjectId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_links')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('subject_type', $q->createNamedParameter($subjectType, IQueryBuilder::PARAM_STR)))
			->andWhere($q->expr()->eq('subject_id', $q->createNamedParameter($subjectId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'))
			->orderBy('label', 'ASC')->addOrderBy('id', 'ASC');
		$result = $q->executeQuery();
		try {
			return $result->fetchAllAssociative();
		} finally {
			$result->closeCursor();
		}
	}

	/** @return array<string,mixed>|null */
	public function findByUuid(?int $workspaceId, string $uuid): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_links')
			->where($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		if ($workspaceId !== null) {
			$q->andWhere($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		}
		$result = $q->executeQuery();
		try {
			$row = $result->fetchAssociative();
			return $row === false ? null : $row;
		} finally {
			$result->closeCursor();
		}
	}

	public function insert(int $workspaceId, string $uuid, string $subjectType, int $subjectId, string $label, string $url, string $source, int $now): void {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_links')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'uuid' => $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			'subject_type' => $q->createNamedParameter($subjectType, IQueryBuilder::PARAM_STR),
			'subject_id' => $q->createNamedParameter($subjectId, IQueryBuilder::PARAM_INT),
			'label' => $q->createNamedParameter($label, IQueryBuilder::PARAM_STR),
			'url' => $q->createNamedParameter($url, IQueryBuilder::PARAM_STR),
			'source' => $q->createNamedParameter($source, IQueryBuilder::PARAM_STR),
			'revision' => $q->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	public function archive(int $workspaceId, int $id, int $expectedRevision, int $now): bool {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_links')
			->set('deleted_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('revision', $q->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('revision', $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'));
		return $q->executeStatement() === 1;
	}
}
