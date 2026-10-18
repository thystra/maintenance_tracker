<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Hierarchical item groups and asset membership. Every write runs inside the
 * caller's workspace write transaction (WorkspaceService::runWithCapability),
 * is journaled and audited. Deleting a group never deletes items: its
 * subgroups and items move up to the deleted group's parent (items of a
 * deleted top-level group become ungrouped).
 */
final class ItemGroupService {
	public function __construct(
		private ItemGroupRepository $repository,
		private AssetService $assets,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return array{items: list<array<string,mixed>>, assignments: list<array{assetUuid: string, groupUuid: string}>}
	 */
	public function list(WorkspaceContext $context): array {
		$workspaceId = $context->workspace()->getId();
		$rows = $this->repository->listGroups($workspaceId);
		$uuidById = [];
		foreach ($rows as $row) {
			$uuidById[(int)$row['id']] = (string)$row['uuid'];
		}
		return [
			'items' => array_map(fn (array $row): array => $this->toApi($row, $uuidById), $rows),
			'assignments' => array_map(static fn (array $m): array => ['assetUuid' => $m['asset_uuid'], 'groupUuid' => $m['group_uuid']], $this->repository->memberships($workspaceId)),
		];
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function create(WorkspaceContext $context, array $input): array {
		CatalogInput::known($input, ['uuid', 'name', 'parentUuid', 'sort'], 'item group');
		$workspaceId = $context->workspace()->getId();
		$name = ItemGroupTree::name($input['name'] ?? null);
		$sort = array_key_exists('sort', $input) ? ItemGroupTree::sort($input['sort']) : 0;
		$parent = $this->parent($workspaceId, $input['parentUuid'] ?? null);
		$uuid = array_key_exists('uuid', $input) ? CatalogInput::uuid($input['uuid'], 'uuid') : $this->uuidGenerator->generate();

		$existing = $this->repository->findByUuid($workspaceId, $uuid, true);
		if ($existing !== null) {
			$sameParent = ($existing['parent_id'] === null ? null : (int)$existing['parent_id']) === ($parent === null ? null : (int)$parent['id']);
			if ($existing['deleted_at'] === null && $existing['name'] === $name && $sameParent && (int)$existing['sort_order'] === $sort) {
				return $this->show($context, $uuid);
			}
			throw new RevisionConflictException('The supplied item group UUID already exists with different data');
		}
		if ($this->repository->uuidExistsAnywhere($uuid)) {
			throw new RevisionConflictException('The supplied item group UUID already exists with different data');
		}
		if ($this->repository->countGroups($workspaceId) >= ItemGroupTree::MAX_GROUPS) {
			throw new ValidationException('A workspace can have at most ' . ItemGroupTree::MAX_GROUPS . ' item groups');
		}
		$now = $this->timeFactory->getTime();
		$this->repository->insert($workspaceId, $uuid, $parent === null ? null : (int)$parent['id'], $name, $sort, $now);
		$this->journal->record($workspaceId, 'item_group', $uuid, 'upsert', 1, $now);
		return $this->show($context, $uuid);
	}

	/** @return array<string,mixed> */
	public function show(WorkspaceContext $context, string $uuid): array {
		$workspaceId = $context->workspace()->getId();
		$row = $this->find($workspaceId, $uuid);
		$parentUuid = null;
		if ($row['parent_id'] !== null) {
			$parentUuid = (string)($this->repository->findById($workspaceId, (int)$row['parent_id'])['uuid'] ?? '');
		}
		return $this->toApi($row, $row['parent_id'] === null ? [] : [(int)$row['parent_id'] => $parentUuid]);
	}

	/** Rename or re-sort a group. @param array<string,mixed> $input @return array<string,mixed> */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		CatalogInput::known($input, ['name', 'sort'], 'item group update');
		if ($input === []) {
			throw new ValidationException('At least one item group field must be supplied');
		}
		$workspaceId = $context->workspace()->getId();
		$row = $this->find($workspaceId, $uuid);
		$this->assertRevision($row, $expectedRevision);
		$name = array_key_exists('name', $input) ? ItemGroupTree::name($input['name']) : (string)$row['name'];
		$sort = array_key_exists('sort', $input) ? ItemGroupTree::sort($input['sort']) : (int)$row['sort_order'];
		$this->write($workspaceId, $row, $expectedRevision, self::parentId($row), $name, $sort, null, 'upsert');
		return $this->show($context, $uuid);
	}

	/** Move a group under another parent (null: top level). @return array<string,mixed> */
	public function move(WorkspaceContext $context, string $uuid, int $expectedRevision, ?string $parentUuid, ?int $sort = null): array {
		$workspaceId = $context->workspace()->getId();
		$row = $this->find($workspaceId, $uuid);
		$this->assertRevision($row, $expectedRevision);
		$parent = $this->parent($workspaceId, $parentUuid);
		$parentId = $parent === null ? null : (int)$parent['id'];
		ItemGroupTree::assertMove($this->parentMap($workspaceId), (int)$row['id'], $parentId);
		$newSort = $sort === null ? (int)$row['sort_order'] : ItemGroupTree::sort($sort);
		$this->write($workspaceId, $row, $expectedRevision, $parentId, (string)$row['name'], $newSort, null, 'upsert', 'item_group.moved', ['parentUuid' => $parent === null ? null : (string)$parent['uuid']]);
		return $this->show($context, $uuid);
	}

	/**
	 * Delete a group. Its subgroups and items move to its parent first.
	 *
	 * @return array{uuid: string, movedGroups: int, movedItems: int, ungroupedItems: int}
	 */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		$workspaceId = $context->workspace()->getId();
		$row = $this->find($workspaceId, $uuid);
		$this->assertRevision($row, $expectedRevision);
		$parentId = self::parentId($row);
		$parent = $parentId === null ? null : $this->repository->findById($workspaceId, $parentId);
		$now = $this->timeFactory->getTime();

		$movedGroups = 0;
		foreach ($this->repository->listGroups($workspaceId) as $child) {
			if (self::parentId($child) !== (int)$row['id']) {
				continue;
			}
			$this->write($workspaceId, $child, (int)$child['revision'], $parentId, (string)$child['name'], (int)$child['sort_order'], null, 'upsert', 'item_group.moved', ['parentUuid' => $parent === null ? null : (string)$parent['uuid']]);
			$movedGroups++;
		}

		$movedItems = 0;
		$ungrouped = 0;
		foreach ($this->repository->membershipsInGroup($workspaceId, (int)$row['id']) as $membership) {
			$revision = (int)$membership['revision'] + 1;
			if ($parent === null) {
				$this->repository->deleteMembership($workspaceId, (int)$membership['id']);
				$this->journal->record($workspaceId, 'asset_group', (string)$membership['asset_uuid'], 'delete', $revision, $now, 'asset.group_changed', ['groupUuid' => null]);
				$ungrouped++;
			} else {
				$this->repository->moveMembership($workspaceId, (int)$membership['id'], (int)$parent['id'], $revision, $now);
				$this->journal->record($workspaceId, 'asset_group', (string)$membership['asset_uuid'], 'upsert', $revision, $now, 'asset.group_changed', ['groupUuid' => (string)$parent['uuid']]);
				$movedItems++;
			}
		}

		$this->write($workspaceId, $row, $expectedRevision, $parentId, (string)$row['name'], (int)$row['sort_order'], $now, 'delete');
		return ['uuid' => (string)$row['uuid'], 'movedGroups' => $movedGroups, 'movedItems' => $movedItems, 'ungroupedItems' => $ungrouped];
	}

	/**
	 * Put an item into a group, or take it out of its group (null).
	 *
	 * @return array{assetUuid: string, groupUuid: string|null}
	 */
	public function setAssetGroup(WorkspaceContext $context, string $assetUuid, ?string $groupUuid): array {
		$workspaceId = $context->workspace()->getId();
		$asset = $this->assets->find($context, CatalogInput::uuid($assetUuid, 'assetUuid'));
		$group = $groupUuid === null || $groupUuid === '' ? null : $this->find($workspaceId, CatalogInput::uuid($groupUuid, 'groupUuid'));
		$membership = $this->repository->membershipForAsset($workspaceId, (int)$asset->getId());
		$now = $this->timeFactory->getTime();
		$result = ['assetUuid' => $asset->getUuid(), 'groupUuid' => $group === null ? null : (string)$group['uuid']];

		if ($group === null) {
			if ($membership !== null) {
				$this->repository->deleteMembership($workspaceId, (int)$membership['id']);
				$this->journal->record($workspaceId, 'asset_group', $asset->getUuid(), 'delete', (int)$membership['revision'] + 1, $now, 'asset.group_changed', ['groupUuid' => null]);
			}
			return $result;
		}
		if ($membership === null) {
			$this->repository->insertMembership($workspaceId, (int)$asset->getId(), (int)$group['id'], $now);
			$this->journal->record($workspaceId, 'asset_group', $asset->getUuid(), 'upsert', 1, $now, 'asset.group_changed', ['groupUuid' => (string)$group['uuid']]);
		} elseif ((int)$membership['group_id'] !== (int)$group['id']) {
			$revision = (int)$membership['revision'] + 1;
			$this->repository->moveMembership($workspaceId, (int)$membership['id'], (int)$group['id'], $revision, $now);
			$this->journal->record($workspaceId, 'asset_group', $asset->getUuid(), 'upsert', $revision, $now, 'asset.group_changed', ['groupUuid' => (string)$group['uuid']]);
		}
		return $result;
	}

	/** @return array<string,mixed> */
	private function find(int $workspaceId, string $uuid): array {
		$row = $this->repository->findByUuid($workspaceId, CatalogInput::uuid($uuid, 'groupUuid'));
		if ($row === null) {
			throw new NotFoundException('Item group not found');
		}
		return $row;
	}

	/** @return array<string,mixed>|null */
	private function parent(int $workspaceId, mixed $parentUuid): ?array {
		if ($parentUuid === null || $parentUuid === '') {
			return null;
		}
		$row = $this->repository->findByUuid($workspaceId, CatalogInput::uuid($parentUuid, 'parentUuid'));
		if ($row === null) {
			throw new ValidationException('The parent item group does not exist');
		}
		return $row;
	}

	/** @return array<int, int|null> */
	private function parentMap(int $workspaceId): array {
		$map = [];
		foreach ($this->repository->listGroups($workspaceId) as $row) {
			$map[(int)$row['id']] = self::parentId($row);
		}
		return $map;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string, scalar|null> $auditDetails
	 */
	private function write(int $workspaceId, array $row, int $expectedRevision, ?int $parentId, string $name, int $sort, ?int $deletedAt, string $operation, ?string $auditEvent = null, array $auditDetails = []): void {
		$now = $this->timeFactory->getTime();
		if (!$this->repository->update($workspaceId, (int)$row['id'], $expectedRevision, $parentId, $name, $sort, $deletedAt, $now)) {
			throw new RevisionConflictException('The item group has changed since it was last read');
		}
		$this->journal->record($workspaceId, 'item_group', (string)$row['uuid'], $operation, $expectedRevision + 1, $now, $auditEvent, $auditDetails);
	}

	/** @param array<string,mixed> $row */
	private function assertRevision(array $row, int $expectedRevision): void {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}
		if ((int)$row['revision'] !== $expectedRevision) {
			throw new RevisionConflictException('The item group has changed since it was last read');
		}
	}

	/** @param array<string,mixed> $row */
	private static function parentId(array $row): ?int {
		return $row['parent_id'] === null ? null : (int)$row['parent_id'];
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<int,string> $uuidById
	 * @return array<string,mixed>
	 */
	private function toApi(array $row, array $uuidById): array {
		$parentId = self::parentId($row);
		return [
			'uuid' => (string)$row['uuid'],
			'parentUuid' => $parentId === null ? null : ($uuidById[$parentId] ?? null),
			'name' => (string)$row['name'],
			'sort' => (int)$row['sort_order'],
			'revision' => (int)$row['revision'],
			'createdAt' => CatalogInput::formatTimestamp((int)$row['created_at']),
			'updatedAt' => CatalogInput::formatTimestamp((int)$row['updated_at']),
		];
	}
}
