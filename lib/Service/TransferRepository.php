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
 * Persistence for workspace ownership transfers and their move snapshots.
 * Every query binds its parameters.
 */
final class TransferRepository {
	private const INT_COLUMNS = ['id', 'workspace_id', 'leave_after', 'expires_at', 'created_at', 'updated_at', 'finished_at', 'transfer_id', 'ref_id', 'old_file_id', 'new_file_id'];

	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string,mixed>|null */
	public function findByUuid(string $uuid): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_ws_transfers')->where($q->expr()->eq('uuid', $q->createNamedParameter($uuid)));
		return $this->one($q);
	}

	/** Pending or in-progress transfer of a workspace, if any. @return array<string,mixed>|null */
	public function findOpenForWorkspace(int $workspaceId): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_ws_transfers')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->in('status', $q->createNamedParameter(OwnershipTransferPolicy::OPEN_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'DESC')->setMaxResults(1);
		return $this->one($q);
	}

	/** @return list<array<string,mixed>> */
	public function findOpenForUser(string $uid): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_ws_transfers')
			->where($q->expr()->orX(
				$q->expr()->eq('to_uid', $q->createNamedParameter($uid)),
				$q->expr()->eq('from_uid', $q->createNamedParameter($uid)),
			))
			->andWhere($q->expr()->in('status', $q->createNamedParameter(OwnershipTransferPolicy::OPEN_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id');
		return $this->many($q);
	}

	/** Pending offers whose deadline has passed, oldest first. @return list<array<string,mixed>> */
	public function findExpiredPending(int $now, int $limit): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_ws_transfers')
			->where($q->expr()->eq('status', $q->createNamedParameter(OwnershipTransferPolicy::STATUS_PENDING)))
			->andWhere($q->expr()->lte('expires_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('id')->setMaxResults($limit);
		return $this->many($q);
	}

	/** @param array<string,mixed> $row */
	public function insert(array $row): int {
		$q = $this->db->getQueryBuilder();
		$values = [];
		foreach ($row as $column => $value) {
			$values[$column] = $this->param($q, $column, $value);
		}
		$q->insert('maint_ws_transfers')->values($values)->executeStatement();
		return $q->getLastInsertId();
	}

	/**
	 * Compare-and-set update guarded by the expected status and step so two
	 * concurrent requests can never both advance the same transfer.
	 *
	 * @param array<string,mixed> $changes
	 */
	public function advance(int $id, string $expectedStatus, string $expectedStep, array $changes): bool {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_ws_transfers');
		foreach ($changes as $column => $value) {
			$q->set($column, $this->param($q, $column, $value));
		}
		$q->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('status', $q->createNamedParameter($expectedStatus)))
			->andWhere($q->expr()->eq('step', $q->createNamedParameter($expectedStep)));
		return $q->executeStatement() === 1;
	}

	public function recordError(int $id, ?string $error, int $now): void {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_ws_transfers')
			->set('error', $error === null ? $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL) : $q->createNamedParameter(substr($error, 0, 64)))
			->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function addItem(int $transferId, string $type, int $refId, int $oldFileId, string $relPath): void {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_ws_xfer_items')->values([
			'transfer_id' => $q->createNamedParameter($transferId, IQueryBuilder::PARAM_INT),
			'item_type' => $q->createNamedParameter($type),
			'ref_id' => $q->createNamedParameter($refId, IQueryBuilder::PARAM_INT),
			'old_file_id' => $q->createNamedParameter($oldFileId, IQueryBuilder::PARAM_INT),
			'rel_path' => $q->createNamedParameter($relPath),
		])->executeStatement();
	}

	/** @return list<array<string,mixed>> */
	public function items(int $transferId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_ws_xfer_items')
			->where($q->expr()->eq('transfer_id', $q->createNamedParameter($transferId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		return $this->many($q);
	}

	public function setItemFile(int $itemId, int $fileId): void {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_ws_xfer_items')
			->set('new_file_id', $q->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('id', $q->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteItems(int $transferId): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('maint_ws_xfer_items')
			->where($q->expr()->eq('transfer_id', $q->createNamedParameter($transferId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** Workspace purge: transfers and their snapshots go with the workspace. */
	public function deleteForWorkspace(int $workspaceId): void {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('maint_ws_transfers')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		foreach ($this->many($q) as $row) {
			$this->deleteItems((int)$row['id']);
		}
		$q = $this->db->getQueryBuilder();
		$q->delete('maint_ws_transfers')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function param(IQueryBuilder $q, string $column, mixed $value): mixed {
		if ($value === null) {
			return $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL);
		}
		return in_array($column, self::INT_COLUMNS, true)
			? $q->createNamedParameter((int)$value, IQueryBuilder::PARAM_INT)
			: $q->createNamedParameter((string)$value, IQueryBuilder::PARAM_STR);
	}

	/** @return array<string,mixed>|null */
	private function one(IQueryBuilder $q): ?array {
		$r = $q->executeQuery();
		try {
			$row = $r->fetchAssociative();
			return $row === false ? null : $this->cast($row);
		} finally {
			$r->closeCursor();
		}
	}

	/** @return list<array<string,mixed>> */
	private function many(IQueryBuilder $q): array {
		$r = $q->executeQuery();
		try {
			return array_map(fn (array $row): array => $this->cast($row), $r->fetchAllAssociative());
		} finally {
			$r->closeCursor();
		}
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function cast(array $row): array {
		foreach (self::INT_COLUMNS as $c) {
			if (array_key_exists($c, $row) && $row[$c] !== null) {
				$row[$c] = (int)$row[$c];
			}
		}
		return $row;
	}
}
