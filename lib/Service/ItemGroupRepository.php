<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Row access for item groups and asset group membership. */
class ItemGroupRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return list<array<string,mixed>> */
	public function listGroups(int $workspaceId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_item_groups')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'))
			->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC')->addOrderBy('id', 'ASC');
		return $this->many($q);
	}

	public function countGroups(int $workspaceId): int {
		$q = $this->db->getQueryBuilder();
		$q->select($q->func()->count('*'))->from('maint_item_groups')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'));
		$result = $q->executeQuery();
		try {
			return (int)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}

	/** @return array<string,mixed>|null */
	public function findByUuid(int $workspaceId, string $uuid, bool $includeArchived = false): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_item_groups')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		if (!$includeArchived) {
			$q->andWhere($q->expr()->isNull('deleted_at'));
		}
		return $this->one($q);
	}

	public function uuidExistsAnywhere(string $uuid): bool {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('maint_item_groups')
			->where($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		return $this->one($q) !== null;
	}

	/** @return array<string,mixed>|null */
	public function findById(int $workspaceId, int $id): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_item_groups')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->one($q);
	}

	public function insert(int $workspaceId, string $uuid, ?int $parentId, string $name, int $sort, int $now): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_item_groups')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'uuid' => $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			'parent_id' => $q->createNamedParameter($parentId, $parentId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
			'name' => $q->createNamedParameter($name, IQueryBuilder::PARAM_STR),
			'sort_order' => $q->createNamedParameter($sort, IQueryBuilder::PARAM_INT),
			'revision' => $q->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'deleted_at' => $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/** Optimistic update of one group; false when the revision no longer matches. */
	public function update(int $workspaceId, int $id, int $expectedRevision, ?int $parentId, string $name, int $sort, ?int $deletedAt, int $now): bool {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_item_groups')
			->set('parent_id', $q->createNamedParameter($parentId, $parentId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('name', $q->createNamedParameter($name, IQueryBuilder::PARAM_STR))
			->set('sort_order', $q->createNamedParameter($sort, IQueryBuilder::PARAM_INT))
			->set('deleted_at', $q->createNamedParameter($deletedAt, $deletedAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('revision', $q->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('revision', $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'));
		return $q->executeStatement() === 1;
	}

	/**
	 * Memberships of active assets in active groups.
	 *
	 * @return list<array{asset_uuid: string, group_uuid: string}>
	 */
	public function memberships(int $workspaceId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('a.uuid AS asset_uuid', 'g.uuid AS group_uuid')
			->from('maint_asset_grp', 'm')
			->innerJoin('m', 'maint_assets', 'a', $q->expr()->andX($q->expr()->eq('a.id', 'm.asset_id'), $q->expr()->eq('a.workspace_id', 'm.workspace_id')))
			->innerJoin('m', 'maint_item_groups', 'g', $q->expr()->andX($q->expr()->eq('g.id', 'm.group_id'), $q->expr()->eq('g.workspace_id', 'm.workspace_id')))
			->where($q->expr()->eq('m.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('a.deleted_at'))
			->andWhere($q->expr()->isNull('g.deleted_at'))
			->orderBy('m.id', 'ASC');
		return array_map(static fn (array $r): array => ['asset_uuid' => (string)$r['asset_uuid'], 'group_uuid' => (string)$r['group_uuid']], $this->many($q));
	}

	/** @return array<string,mixed>|null */
	public function membershipForAsset(int $workspaceId, int $assetId): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_asset_grp')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('asset_id', $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)));
		return $this->one($q);
	}

	/**
	 * Memberships of one group, with the asset UUID.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function membershipsInGroup(int $workspaceId, int $groupId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('m.*', 'a.uuid AS asset_uuid')
			->from('maint_asset_grp', 'm')
			->innerJoin('m', 'maint_assets', 'a', $q->expr()->eq('a.id', 'm.asset_id'))
			->where($q->expr()->eq('m.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('m.group_id', $q->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->orderBy('m.id', 'ASC');
		return $this->many($q);
	}

	public function insertMembership(int $workspaceId, int $assetId, int $groupId, int $now): void {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_asset_grp')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'asset_id' => $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT),
			'group_id' => $q->createNamedParameter($groupId, IQueryBuilder::PARAM_INT),
			'revision' => $q->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		]);
		$q->executeStatement();
	}

	public function moveMembership(int $workspaceId, int $id, int $groupId, int $revision, int $now): void {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_asset_grp')
			->set('group_id', $q->createNamedParameter($groupId, IQueryBuilder::PARAM_INT))
			->set('revision', $q->createNamedParameter($revision, IQueryBuilder::PARAM_INT))
			->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$q->executeStatement();
	}

	public function deleteMembership(int $workspaceId, int $id): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('maint_asset_grp')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$q->executeStatement();
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
}
