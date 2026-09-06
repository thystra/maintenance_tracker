<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\WorkDefinitionMapper;
use OCA\MaintenanceTracker\Db\WorkGroup;
use OCA\MaintenanceTracker\Db\WorkGroupMapper;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;

final class WorkGroupService {
	public function __construct(
		private AssetService $assets,
		private WorkGroupMapper $mapper,
		private WorkDefinitionMapper $definitions,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(WorkspaceContext $context, string $assetUuid): array {
		$asset = $this->assets->find($context, $assetUuid);
		return array_map(
			fn (WorkGroup $group): array => $this->toApi($group),
			$this->mapper->findForAsset($context->workspace()->getId(), $asset->getId()),
		);
	}

	public function find(WorkspaceContext $context, string $uuid, bool $includeDeleted = false): WorkGroup {
		$uuid = $this->uuid($uuid, 'uuid');
		try {
			return $this->mapper->findByUuid($context->workspace()->getId(), $uuid, $includeDeleted);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Work group not found');
		}
	}

	public function findById(WorkspaceContext $context, int $id, bool $includeDeleted = false): WorkGroup {
		try {
			return $this->mapper->findById($context->workspace()->getId(), $id, $includeDeleted);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Work group not found');
		}
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function create(WorkspaceContext $context, string $assetUuid, array $input): array {
		$this->assertKnownFields($input, ['uuid', 'key', 'name', 'description', 'sortOrder'], 'work group');
		foreach (['key', 'name'] as $required) {
			if (!array_key_exists($required, $input)) {
				throw new ValidationException("{$required} is required");
			}
		}

		$asset = $this->assets->find($context, $assetUuid);
		$key = $this->key($input['key'], 'key');
		$name = $this->text($input['name'], 'name', 255);
		$description = $this->optionalText($input['description'] ?? null, 'description', 4000);
		$sortOrder = $this->sortOrder($input['sortOrder'] ?? 0);
		$uuid = array_key_exists('uuid', $input)
			? $this->uuid($input['uuid'], 'uuid')
			: $this->uuidGenerator->generate();

		try {
			$existing = $this->mapper->findByUuid($context->workspace()->getId(), $uuid, true);
			if (
				$existing->getDeletedAt() === null
				&& $existing->getAssetId() === $asset->getId()
				&& $existing->getGroupKey() === $key
				&& $existing->getName() === $name
				&& $existing->getDescription() === $description
				&& $existing->getSortOrder() === $sortOrder
			) {
				return $this->toApi($existing);
			}
			throw new RevisionConflictException('The supplied work-group UUID already exists with different data');
		} catch (DoesNotExistException) {
			// UUID is available.
		}

		$this->assertKeyAvailable($context->workspace()->getId(), $asset->getId(), $key, null);
		$now = $this->timeFactory->getTime();
		$group = new WorkGroup();
		$group->setWorkspaceId($context->workspace()->getId());
		$group->setAssetId($asset->getId());
		$group->setUuid($uuid);
		$group->setGroupKey($key);
		$group->setName($name);
		$group->setDescription($description);
		$group->setSortOrder($sortOrder);
		$group->setRevision(1);
		$group->setCreatedAt($now);
		$group->setUpdatedAt($now);
		$group->setDeletedAt(null);

		try {
			/** @var WorkGroup $inserted */
			$inserted = $this->mapper->insert($group);
			$this->journal->record($inserted->getWorkspaceId(), 'work_group', $uuid, 'upsert', 1, $now);
			return $this->toApi($inserted);
		} catch (DatabaseException $exception) {
			if ($exception->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new RevisionConflictException('The supplied work-group UUID already exists', 0, $exception);
			}
			throw $exception;
		}
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}
		$this->assertKnownFields($input, ['key', 'name', 'description', 'sortOrder'], 'work-group update');
		$group = $this->find($context, $uuid);
		if ($group->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The work group has changed since it was last read');
		}

		$key = array_key_exists('key', $input) ? $this->key($input['key'], 'key') : $group->getGroupKey();
		$this->assertKeyAvailable($group->getWorkspaceId(), $group->getAssetId(), $key, $group->getId());
		$group->setGroupKey($key);
		if (array_key_exists('name', $input)) {
			$group->setName($this->text($input['name'], 'name', 255));
		}
		if (array_key_exists('description', $input)) {
			$group->setDescription($this->optionalText($input['description'], 'description', 4000));
		}
		if (array_key_exists('sortOrder', $input)) {
			$group->setSortOrder($this->sortOrder($input['sortOrder']));
		}
		$group->setRevision($expectedRevision + 1);
		$group->setUpdatedAt($this->timeFactory->getTime());
		$this->persistRevision($group, $expectedRevision, 'upsert');
		return $this->toApi($group);
	}

	/** @return array<string,mixed> */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}
		$group = $this->find($context, $uuid);
		if ($group->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The work group has changed since it was last read');
		}
		if ($this->definitions->countActiveForGroup($group->getWorkspaceId(), $group->getId()) > 0) {
			throw new ValidationException('Work group is referenced by active work definitions');
		}

		$now = $this->timeFactory->getTime();
		$group->setRevision($expectedRevision + 1);
		$group->setUpdatedAt($now);
		$group->setDeletedAt($now);
		$this->persistRevision($group, $expectedRevision, 'delete');
		return $this->toApi($group);
	}

	/** @return array<string,mixed> */
	public function toApi(WorkGroup $group): array {
		return [
			'uuid' => $group->getUuid(),
			'key' => $group->getGroupKey(),
			'name' => $group->getName(),
			'description' => $group->getDescription(),
			'sortOrder' => $group->getSortOrder(),
			'revision' => $group->getRevision(),
			'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $group->getCreatedAt()),
			'updatedAt' => gmdate('Y-m-d\TH:i:s\Z', $group->getUpdatedAt()),
			'deletedAt' => $group->getDeletedAt() === null ? null : gmdate('Y-m-d\TH:i:s\Z', $group->getDeletedAt()),
		];
	}

	private function persistRevision(WorkGroup $group, int $expectedRevision, string $operation): void {
		if (!$this->mapper->updateWithExpectedRevision($group, $expectedRevision)) {
			throw new RevisionConflictException('The work group has changed since it was last read');
		}
		$this->journal->record(
			$group->getWorkspaceId(),
			'work_group',
			$group->getUuid(),
			$operation,
			$group->getRevision(),
			$group->getUpdatedAt(),
		);
	}

	private function assertKeyAvailable(int $workspaceId, int $assetId, string $key, ?int $excludeId): void {
		foreach ($this->mapper->findByAssetAndKey($workspaceId, $assetId, $key) as $existing) {
			if ($excludeId === null || $existing->getId() !== $excludeId) {
				throw new ValidationException('An active work group with this key already exists for the asset');
			}
		}
	}

	private function key(mixed $value, string $field): string {
		$value = $this->text($value, $field, 64);
		if (preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $value) !== 1) {
			throw new ValidationException("{$field} must be a lowercase key");
		}
		return $value;
	}

	private function uuid(mixed $value, string $field): string {
		if (!is_string($value) || !UuidGenerator::isValid(strtolower(trim($value)))) {
			throw new ValidationException("{$field} must be an RFC 4122 version 4 UUID");
		}
		return strtolower(trim($value));
	}

	private function text(mixed $value, string $field, int $maximum): string {
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be a string");
		}
		$value = trim($value);
		if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
			throw new ValidationException("{$field} contains unsupported content");
		}
		return $value;
	}

	private function optionalText(mixed $value, string $field, int $maximum): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		return $this->text($value, $field, $maximum);
	}

	private function sortOrder(mixed $value): int {
		if (!is_int($value) || $value < -1000000 || $value > 1000000) {
			throw new ValidationException('sortOrder must be an integer between -1000000 and 1000000');
		}
		return $value;
	}

	/** @param list<string> $allowed */
	private function assertKnownFields(array $input, array $allowed, string $label): void {
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) {
			throw new ValidationException('Unknown ' . $label . ' fields: ' . implode(', ', $unknown));
		}
	}
}
