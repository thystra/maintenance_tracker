<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Asset;
use OCA\MaintenanceTracker\Db\AssetMapper;
use OCA\MaintenanceTracker\Db\Relationship;
use OCA\MaintenanceTracker\Db\RelationshipMapper;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;
use Throwable;

final class RelationshipService {
	public function __construct(
		private AssetService $assets,
		private AssetMapper $assetMapper,
		private RelationshipMapper $mapper,
		private RelationshipTypeCatalog $types,
		private RelationshipInputValidator $validator,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(WorkspaceContext $context): array {
		return array_map(
			fn (Relationship $relationship): array => $this->toApi($context, $relationship),
			$this->mapper->findForWorkspace($context->workspace()->getId()),
		);
	}

	/** @return array<string, mixed> */
	public function show(WorkspaceContext $context, string $uuid): array {
		return $this->toApi($context, $this->find($context, $uuid));
	}

	/** @param array<string, mixed> $input */
	public function create(WorkspaceContext $context, array $input): array {
		$allowed = ['uuid', 'sourceAssetUuid', 'targetAssetUuid', 'type', 'context', 'isDefault', 'notes'];
		$this->assertKnownFields($input, $allowed, 'relationship');
		foreach (['sourceAssetUuid', 'targetAssetUuid', 'type'] as $required) {
			if (!array_key_exists($required, $input)) {
				throw new ValidationException("{$required} is required");
			}
		}

		$source = $this->assets->find($context, $this->validator->uuid($input['sourceAssetUuid'], 'sourceAssetUuid'));
		$target = $this->assets->find($context, $this->validator->uuid($input['targetAssetUuid'], 'targetAssetUuid'));
		$this->assertDistinctAssets($source, $target);
		$typeKey = $this->validator->key($input['type'], 'type');
		$this->types->assertCompatible($typeKey, $source->getAssetClass(), $target->getAssetClass());
		$contextKey = $this->validator->optionalKey($input['context'] ?? null, 'context');
		$isDefault = array_key_exists('isDefault', $input)
			? $this->validator->boolean($input['isDefault'], 'isDefault')
			: false;
		$notes = $this->validator->optionalText($input['notes'] ?? null, 'notes');
		$uuid = array_key_exists('uuid', $input)
			? $this->validator->uuid($input['uuid'], 'uuid')
			: $this->uuidGenerator->generate();

		try {
			$existing = $this->mapper->findByUuid($context->workspace()->getId(), $uuid, true);
			if ($existing->getDeletedAt() === null
				&& $this->matchesCreate($existing, $source, $target, $typeKey, $contextKey, $isDefault, $notes)) {
				return $this->toApi($context, $existing);
			}
			throw new RevisionConflictException('The supplied relationship UUID already exists with different data');
		} catch (DoesNotExistException) {
			// UUID is available in this workspace. The database unique key remains the global guard.
		}

		$this->assertDefaultAvailable(
			$context->workspace()->getId(),
			$source->getId(),
			$typeKey,
			$contextKey,
			$isDefault,
			null,
		);

		$now = $this->timeFactory->getTime();
		$relationship = new Relationship();
		$relationship->setWorkspaceId($context->workspace()->getId());
		$relationship->setUuid($uuid);
		$relationship->setSourceAssetId($source->getId());
		$relationship->setTargetAssetId($target->getId());
		$relationship->setTypeKey($typeKey);
		$relationship->setContextKey($contextKey);
		$relationship->setIsDefault($isDefault);
		$relationship->setNotes($notes);
		$relationship->setRevision(1);
		$relationship->setCreatedAt($now);
		$relationship->setUpdatedAt($now);
		$relationship->setDeletedAt(null);

		try {
			/** @var Relationship $inserted */
			$inserted = $this->mapper->insert($relationship);
			$this->journal->record($inserted->getWorkspaceId(), 'relationship', $uuid, 'upsert', 1, $now);
			return $this->toApi($context, $inserted);
		} catch (DatabaseException $exception) {
			if ($exception->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new RevisionConflictException('The supplied relationship UUID already exists', 0, $exception);
			}
			throw $exception;
		}
	}

	/** @param array<string, mixed> $input */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}
		$this->assertKnownFields($input, ['context', 'isDefault', 'notes'], 'relationship update');
		$relationship = $this->find($context, $uuid);
		if ($relationship->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The relationship has changed since it was last read');
		}

		$contextKey = array_key_exists('context', $input)
			? $this->validator->optionalKey($input['context'], 'context')
			: $relationship->getContextKey();
		$isDefault = array_key_exists('isDefault', $input)
			? $this->validator->boolean($input['isDefault'], 'isDefault')
			: $relationship->getIsDefault();
		$notes = array_key_exists('notes', $input)
			? $this->validator->optionalText($input['notes'], 'notes')
			: $relationship->getNotes();

		$this->assertDefaultAvailable(
			$relationship->getWorkspaceId(),
			$relationship->getSourceAssetId(),
			$relationship->getTypeKey(),
			$contextKey,
			$isDefault,
			$relationship->getId(),
		);

		$relationship->setContextKey($contextKey);
		$relationship->setIsDefault($isDefault);
		$relationship->setNotes($notes);
		$relationship->setRevision($expectedRevision + 1);
		$relationship->setUpdatedAt($this->timeFactory->getTime());
		$this->persistRevision($relationship, $expectedRevision, 'upsert');

		return $this->toApi($context, $relationship);
	}

	/** @return array<string, mixed> */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}
		$relationship = $this->find($context, $uuid);
		if ($relationship->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The relationship has changed since it was last read');
		}
		$now = $this->timeFactory->getTime();
		$relationship->setDeletedAt($now);
		$relationship->setUpdatedAt($now);
		$relationship->setRevision($expectedRevision + 1);
		$this->persistRevision($relationship, $expectedRevision, 'delete');

		return $this->toApi($context, $relationship);
	}

	private function find(WorkspaceContext $context, string $uuid): Relationship {
		try {
			return $this->mapper->findByUuid(
				$context->workspace()->getId(),
				$this->validator->uuid($uuid, 'uuid'),
			);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Relationship not found');
		}
	}

	private function persistRevision(Relationship $relationship, int $expectedRevision, string $operation): void {
		if (!$this->mapper->updateWithExpectedRevision($relationship, $expectedRevision)) {
			throw new RevisionConflictException('The relationship has changed since it was last read');
		}
		$this->journal->record(
			$relationship->getWorkspaceId(),
			'relationship',
			$relationship->getUuid(),
			$operation,
			$relationship->getRevision(),
			$relationship->getUpdatedAt(),
		);
	}

	private function assertDefaultAvailable(
		int $workspaceId,
		int $sourceAssetId,
		string $typeKey,
		?string $contextKey,
		bool $isDefault,
		?int $excludeId,
	): void {
		if (!$isDefault) {
			return;
		}
		foreach ($this->mapper->findDefaultsForSource($workspaceId, $sourceAssetId, $typeKey, $contextKey) as $existing) {
			if ($excludeId === null || $existing->getId() !== $excludeId) {
				throw new ValidationException('A default relationship already exists for this source, type, and context');
			}
		}
	}

	/** @return array<string, mixed> */
	private function toApi(WorkspaceContext $context, Relationship $relationship): array {
		$definition = $this->types->definition($relationship->getTypeKey());
		$source = $this->findAssetForHistory($context, $relationship->getSourceAssetId());
		$target = $this->findAssetForHistory($context, $relationship->getTargetAssetId());
		return [
			'uuid' => $relationship->getUuid(),
			'type' => $relationship->getTypeKey(),
			'label' => $definition['label'],
			'inverseType' => $definition['inverseKey'],
			'inverseLabel' => $definition['inverseLabel'],
			'sourceAsset' => $this->assetReference($source),
			'targetAsset' => $this->assetReference($target),
			'context' => $relationship->getContextKey(),
			'isDefault' => $relationship->getIsDefault(),
			'notes' => $relationship->getNotes(),
			'revision' => $relationship->getRevision(),
			'createdAt' => $this->formatTimestamp($relationship->getCreatedAt()),
			'updatedAt' => $this->formatTimestamp($relationship->getUpdatedAt()),
			'deletedAt' => $relationship->getDeletedAt() === null ? null : $this->formatTimestamp($relationship->getDeletedAt()),
		];
	}

	private function findAssetForHistory(WorkspaceContext $context, int $assetId): Asset {
		try {
			return $this->assetMapper->findById($context->workspace()->getId(), $assetId, true);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Relationship endpoint asset no longer exists');
		}
	}

	/** @return array{uuid:string,name:string,assetClass:string,archived:bool} */
	private function assetReference(Asset $asset): array {
		return [
			'uuid' => $asset->getUuid(),
			'name' => $asset->getName(),
			'assetClass' => $asset->getAssetClass(),
			'archived' => $asset->getDeletedAt() !== null,
		];
	}

	private function assertDistinctAssets(Asset $source, Asset $target): void {
		if ($source->getId() === $target->getId()) {
			throw new ValidationException('A relationship must connect two different assets');
		}
	}

	/** @param array<string, mixed> $input @param list<string> $allowed */
	private function assertKnownFields(array $input, array $allowed, string $label): void {
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) {
			throw new ValidationException('Unknown ' . $label . ' fields: ' . implode(', ', $unknown));
		}
	}

	private function matchesCreate(
		Relationship $relationship,
		Asset $source,
		Asset $target,
		string $typeKey,
		?string $contextKey,
		bool $isDefault,
		?string $notes,
	): bool {
		return $relationship->getSourceAssetId() === $source->getId()
			&& $relationship->getTargetAssetId() === $target->getId()
			&& $relationship->getTypeKey() === $typeKey
			&& $relationship->getContextKey() === $contextKey
			&& $relationship->getIsDefault() === $isDefault
			&& $relationship->getNotes() === $notes;
	}

	private function formatTimestamp(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
	}
}
