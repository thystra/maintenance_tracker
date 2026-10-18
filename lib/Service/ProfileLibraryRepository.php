<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Row access for the workspace profile library (maint_ws_profiles). */
class ProfileLibraryRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return list<array<string,mixed>> active entries, by name */
	public function list(int $workspaceId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_ws_profiles')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'))
			->orderBy('name', 'ASC')->addOrderBy('profile_key', 'ASC')->addOrderBy('profile_version', 'ASC');
		$result = $q->executeQuery();
		try {
			return $result->fetchAllAssociative();
		} finally {
			$result->closeCursor();
		}
	}

	/** @return array<string,mixed>|null */
	public function find(int $workspaceId, string $uuid, bool $includeDeleted = false): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_ws_profiles')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		if (!$includeDeleted) {
			$q->andWhere($q->expr()->isNull('deleted_at'));
		}
		$result = $q->executeQuery();
		try {
			$row = $result->fetchAssociative();
			return $row === false ? null : $row;
		} finally {
			$result->closeCursor();
		}
	}

	public function uuidExists(string $uuid): bool {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('maint_ws_profiles')->where($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		$result = $q->executeQuery();
		try {
			return $result->fetchOne() !== false;
		} finally {
			$result->closeCursor();
		}
	}

	/** @param array<string,mixed> $row */
	public function insert(array $row): void {
		$q = $this->db->getQueryBuilder();
		$values = [];
		foreach ($row as $column => $value) {
			$values[$column] = $q->createNamedParameter($value, $this->type($column, $value));
		}
		$q->insert('maint_ws_profiles')->values($values)->executeStatement();
	}

	/** @param array<string,mixed> $changes */
	public function update(int $workspaceId, int $id, int $expectedRevision, array $changes): bool {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_ws_profiles');
		foreach ($changes as $column => $value) {
			$q->set($column, $q->createNamedParameter($value, $this->type($column, $value)));
		}
		$q->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('revision', $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'));
		return $q->executeStatement() === 1;
	}

	private function type(string $column, mixed $value): int {
		if ($value === null) {
			return IQueryBuilder::PARAM_NULL;
		}
		return in_array($column, ['workspace_id', 'revision', 'created_at', 'updated_at', 'deleted_at'], true) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR;
	}
}
