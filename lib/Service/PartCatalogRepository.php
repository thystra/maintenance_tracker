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
 * Workspace-scoped persistence for canonical parts and vendors. Every query
 * binds the workspace id; callers resolve the workspace through capability
 * authorization before reaching this class.
 */
final class PartCatalogRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return list<array<string,mixed>> */
	public function listParts(int $workspaceId, bool $includeArchived = false): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_parts')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		if (!$includeArchived) {
			$q->andWhere($q->expr()->isNull('deleted_at'));
		}
		$q->orderBy('manufacturer', 'ASC')->addOrderBy('part_number', 'ASC')->addOrderBy('id', 'ASC');
		return $this->many($q);
	}

	/** @return array<string,mixed>|null */
	public function findPartByUuid(int $workspaceId, string $uuid, bool $includeArchived = false): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_parts')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		if (!$includeArchived) {
			$q->andWhere($q->expr()->isNull('deleted_at'));
		}
		return $this->one($q);
	}

	/** @return array<string,mixed>|null */
	public function findPartById(int $workspaceId, int $id): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_parts')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->one($q);
	}

	/**
	 * Identity lookup includes archived rows because the identity index is
	 * unique per workspace regardless of archive state.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findPartByIdentity(int $workspaceId, string $identityHash): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_parts')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('identity_hash', $q->createNamedParameter($identityHash, IQueryBuilder::PARAM_STR)));
		return $this->one($q);
	}

	/** @param array<string,string|int|null> $extra Optional columns (notes, vendor_id, unit_price_minor, currency) */
	public function insertPart(int $workspaceId, string $uuid, string $identityHash, string $manufacturer, string $partNumber, string $description, int $now, array $extra = []): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_parts')->values($this->extraValues($q, $extra) + [
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'uuid' => $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			'identity_hash' => $q->createNamedParameter($identityHash, IQueryBuilder::PARAM_STR),
			'manufacturer' => $q->createNamedParameter($manufacturer, IQueryBuilder::PARAM_STR),
			'part_number' => $q->createNamedParameter($partNumber, IQueryBuilder::PARAM_STR),
			'description' => $q->createNamedParameter($description, IQueryBuilder::PARAM_STR),
			'revision' => $q->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'deleted_at' => $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/**
	 * Optimistic revision update. Returns false when the expected revision no longer matches.
	 *
	 * @param array<string,string|int|null> $extra
	 */
	public function updatePart(int $workspaceId, int $id, int $expectedRevision, string $description, ?int $deletedAt, int $now, array $extra = []): bool {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_parts');
		foreach ($this->extraValues($q, $extra) as $column => $value) {
			$q->set($column, $value);
		}
		$q
			->set('description', $q->createNamedParameter($description, IQueryBuilder::PARAM_STR))
			->set('deleted_at', $q->createNamedParameter($deletedAt, $deletedAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('revision', $q->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('revision', $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		return $q->executeStatement() === 1;
	}

	/** @return list<array<string,mixed>> */
	public function listVendors(int $workspaceId, bool $includeArchived = false): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_vendors')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		if (!$includeArchived) {
			$q->andWhere($q->expr()->isNull('deleted_at'));
		}
		$q->orderBy('name', 'ASC')->addOrderBy('id', 'ASC');
		return $this->many($q);
	}

	/** @return array<string,mixed>|null */
	public function findVendorByUuid(int $workspaceId, string $uuid, bool $includeArchived = false): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_vendors')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		if (!$includeArchived) {
			$q->andWhere($q->expr()->isNull('deleted_at'));
		}
		return $this->one($q);
	}

	/** @return array<string,mixed>|null */
	public function findVendorById(int $workspaceId, int $id): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_vendors')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->one($q);
	}

	/** @return array<string,mixed>|null */
	public function findVendorByUuidAnyWorkspace(string $uuid): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('maint_vendors')
			->where($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		return $this->one($q);
	}

	/** @return array<string,mixed>|null */
	public function findPartByUuidAnyWorkspace(string $uuid): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('maint_parts')
			->where($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		return $this->one($q);
	}

	/** @param array<string,string|int|null> $extra Contact columns (website, address, phone, email, contact_name) */
	public function insertVendor(int $workspaceId, string $uuid, string $name, ?string $notes, int $now, array $extra = []): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_vendors')->values($this->extraValues($q, $extra) + [
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'uuid' => $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			'name' => $q->createNamedParameter($name, IQueryBuilder::PARAM_STR),
			'notes' => $this->nullableString($q, $notes),
			'revision' => $q->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'deleted_at' => $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/** @param array<string,string|int|null> $extra */
	public function updateVendor(int $workspaceId, int $id, int $expectedRevision, string $name, ?string $notes, ?int $deletedAt, int $now, array $extra = []): bool {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_vendors');
		foreach ($this->extraValues($q, $extra) as $column => $value) {
			$q->set($column, $value);
		}
		$q
			->set('name', $q->createNamedParameter($name, IQueryBuilder::PARAM_STR))
			->set('notes', $this->nullableString($q, $notes))
			->set('deleted_at', $q->createNamedParameter($deletedAt, $deletedAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('revision', $q->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('revision', $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		return $q->executeStatement() === 1;
	}

	/** @return array<string,mixed>|null */
	private function one(IQueryBuilder $q): ?array {
		$result = $q->executeQuery();
		try {
			$row = $result->fetchAssociative();
			return $row === false ? null : $row;
		} finally {
			$result->closeCursor();
		}
	}

	/** @return list<array<string,mixed>> */
	private function many(IQueryBuilder $q): array {
		$result = $q->executeQuery();
		try {
			return $result->fetchAllAssociative();
		} finally {
			$result->closeCursor();
		}
	}

	private function nullableString(IQueryBuilder $q, ?string $value): mixed {
		return $q->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR);
	}

	/**
	 * @param array<string,string|int|null> $extra
	 * @return array<string,mixed>
	 */
	private function extraValues(IQueryBuilder $q, array $extra): array {
		$values = [];
		foreach ($extra as $column => $value) {
			if (preg_match('/^[a-z_]+$/', $column) !== 1) {
				throw new \LogicException('Invalid column name');
			}
			$values[$column] = $q->createNamedParameter($value, match (true) {
				$value === null => IQueryBuilder::PARAM_NULL,
				is_int($value) => IQueryBuilder::PARAM_INT,
				default => IQueryBuilder::PARAM_STR,
			});
		}
		return $values;
	}
}
