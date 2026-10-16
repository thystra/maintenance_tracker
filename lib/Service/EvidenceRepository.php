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
 * Workspace-scoped persistence for evidence references, links, managed
 * folders, and folder shares. Every query binds its parameters.
 */
final class EvidenceRepository {
	private const INT_COLUMNS = ['id', 'workspace_id', 'file_id', 'size', 'revision', 'created_at', 'updated_at', 'deleted_at', 'evidence_id', 'target_id', 'folder_id', 'permissions'];

	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string,mixed>|null */
	public function findByUuid(?int $workspaceId, string $uuid): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_evidence')->where($q->expr()->eq('uuid', $q->createNamedParameter($uuid)));
		if ($workspaceId !== null) {
			$q->andWhere($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		}
		return $this->one($q);
	}

	/** @return array<string,mixed>|null */
	public function findActiveByFile(int $workspaceId, int $fileId): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_evidence')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('file_id', $q->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'));
		return $this->one($q);
	}

	/** @return list<array<string,mixed>> */
	public function findActiveForTarget(int $workspaceId, string $targetType, int $targetId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('e.*')->from('maint_evidence', 'e')
			->innerJoin('e', 'maint_evidence_links', 'l', $q->expr()->eq('l.evidence_id', 'e.id'))
			->where($q->expr()->eq('e.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('l.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('l.target_type', $q->createNamedParameter($targetType)))
			->andWhere($q->expr()->eq('l.target_id', $q->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('e.deleted_at'))
			->orderBy('e.created_at', 'DESC')->addOrderBy('e.id', 'DESC');
		return $this->many($q);
	}

	/** @param array<string,mixed> $row */
	public function insert(array $row): int {
		$q = $this->db->getQueryBuilder();
		$values = [];
		foreach ($row as $column => $value) {
			$values[$column] = $this->param($q, $column, $value);
		}
		$q->insert('maint_evidence')->values($values)->executeStatement();
		return $q->getLastInsertId();
	}

	/** @param array<string,mixed> $changes */
	public function update(int $workspaceId, int $id, int $expectedRevision, array $changes, int $now): bool {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_evidence');
		foreach ($changes as $column => $value) {
			$q->set($column, $this->param($q, $column, $value));
		}
		$q->set('revision', $q->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('revision', $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'));
		return $q->executeStatement() === 1;
	}

	/** @return list<array{target_type:string,target_id:int}> */
	public function links(int $workspaceId, int $evidenceId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('target_type', 'target_id')->from('maint_evidence_links')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('evidence_id', $q->createNamedParameter($evidenceId, IQueryBuilder::PARAM_INT)))
			->orderBy('target_type')->addOrderBy('target_id');
		return $this->many($q);
	}

	public function addLink(int $workspaceId, int $evidenceId, string $targetType, int $targetId, int $now): bool {
		foreach ($this->links($workspaceId, $evidenceId) as $link) {
			if ($link['target_type'] === $targetType && $link['target_id'] === $targetId) {
				return false;
			}
		}
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_evidence_links')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'evidence_id' => $q->createNamedParameter($evidenceId, IQueryBuilder::PARAM_INT),
			'target_type' => $q->createNamedParameter($targetType),
			'target_id' => $q->createNamedParameter($targetId, IQueryBuilder::PARAM_INT),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return true;
	}

	public function removeLink(int $workspaceId, int $evidenceId, string $targetType, int $targetId): bool {
		$q = $this->db->getQueryBuilder();
		$q->delete('maint_evidence_links')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('evidence_id', $q->createNamedParameter($evidenceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('target_type', $q->createNamedParameter($targetType)))
			->andWhere($q->expr()->eq('target_id', $q->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)));
		return $q->executeStatement() === 1;
	}

	/** @return array<string,mixed>|null */
	public function findFolder(int $workspaceId, string $scope): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_ev_folders')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('scope', $q->createNamedParameter($scope)));
		return $this->one($q);
	}

	public function saveFolder(int $workspaceId, string $scope, string $ownerUid, int $folderId, int $now): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('maint_ev_folders')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('scope', $q->createNamedParameter($scope)))
			->executeStatement();
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_ev_folders')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'scope' => $q->createNamedParameter($scope),
			'owner_uid' => $q->createNamedParameter($ownerUid),
			'folder_id' => $q->createNamedParameter($folderId, IQueryBuilder::PARAM_INT),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/** @return list<array<string,mixed>> */
	public function shares(int $workspaceId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_ev_shares')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('member_uid');
		return $this->many($q);
	}

	public function saveShare(int $workspaceId, string $memberUid, string $shareId, int $permissions, int $now): void {
		$this->deleteShare($workspaceId, $memberUid);
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_ev_shares')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'member_uid' => $q->createNamedParameter($memberUid),
			'share_id' => $q->createNamedParameter($shareId),
			'permissions' => $q->createNamedParameter($permissions, IQueryBuilder::PARAM_INT),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	public function deleteShare(int $workspaceId, string $memberUid): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('maint_ev_shares')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('member_uid', $q->createNamedParameter($memberUid)))
			->executeStatement();
	}

	/** @return list<array<string,mixed>> */
	public function folders(int $workspaceId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_ev_folders')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		return $this->many($q);
	}

	public function repointFolder(int $folderRowId, string $ownerUid, int $folderId): void {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_ev_folders')
			->set('owner_uid', $q->createNamedParameter($ownerUid))
			->set('folder_id', $q->createNamedParameter($folderId, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('id', $q->createNamedParameter($folderRowId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return list<array<string,mixed>> */
	public function activeEvidence(int $workspaceId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'file_id')->from('maint_evidence')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'))
			->orderBy('id');
		return $this->many($q);
	}

	/** Re-point an evidence reference after its file moved to another storage. */
	public function repointEvidence(int $evidenceId, int $fileId, string $path, int $now): void {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_evidence')
			->set('file_id', $q->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->set('last_known_path', $q->createNamedParameter($path))
			->set('storage', $q->createNamedParameter('workspace'))
			->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('id', $q->createNamedParameter($evidenceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteAllShares(int $workspaceId): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('maint_ev_shares')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteAllFolders(int $workspaceId): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('maint_ev_folders')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** Account deletion: drop share bookkeeping for a deleted member everywhere. */
	public function deleteSharesForMember(string $memberUid): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('maint_ev_shares')
			->where($q->expr()->eq('member_uid', $q->createNamedParameter($memberUid)))
			->executeStatement();
	}

	/** Resolve a link target's UUID; table names come from a fixed allowlist. */
	public function targetUuid(int $workspaceId, string $table, int $id): ?string {
		if (!in_array($table, ['maint_assets', 'maint_activities', 'maint_costs'], true)) {
			throw new \LogicException('Unsupported evidence target table');
		}
		$q = $this->db->getQueryBuilder();
		$q->select('uuid')->from($table)
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $this->one($q);
		return $row === null ? null : (string)$row['uuid'];
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
