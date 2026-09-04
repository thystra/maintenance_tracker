<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Asset;
use OCA\MaintenanceTracker\Db\AssetMapper;
use OCA\MaintenanceTracker\Db\Assignment;
use OCA\MaintenanceTracker\Db\AssignmentMapper;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;

final class AssignmentService {
	public function __construct(
		private AssetService $assets,
		private AssetMapper $assetMapper,
		private AssignmentMapper $mapper,
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
			fn (Assignment $assignment): array => $this->toApi($context, $assignment),
			$this->mapper->findForWorkspace($context->workspace()->getId()),
		);
	}

	/** @return array<string, mixed> */
	public function show(WorkspaceContext $context, string $uuid): array {
		return $this->toApi($context, $this->find($context, $uuid));
	}

	/** @param array<string, mixed> $input @return array<string, mixed> */
	public function create(WorkspaceContext $context, array $input): array {
		$allowed = [
			'uuid', 'sourceAssetUuid', 'targetAssetUuid', 'type', 'context',
			'isPrimary', 'effectiveFrom', 'effectiveUntil', 'notes',
		];
		$this->assertKnownFields($input, $allowed, 'assignment');
		foreach (['sourceAssetUuid', 'targetAssetUuid', 'type', 'effectiveFrom'] as $required) {
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
		$isPrimary = array_key_exists('isPrimary', $input)
			? $this->validator->boolean($input['isPrimary'], 'isPrimary')
			: false;
		$effectiveFrom = $this->validator->date($input['effectiveFrom'], 'effectiveFrom');
		$effectiveUntil = $this->validator->optionalDate($input['effectiveUntil'] ?? null, 'effectiveUntil');
		$this->validator->assertDateRange($effectiveFrom, $effectiveUntil);
		$notes = $this->validator->optionalText($input['notes'] ?? null, 'notes');
		$uuid = array_key_exists('uuid', $input)
			? $this->validator->uuid($input['uuid'], 'uuid')
			: $this->uuidGenerator->generate();

		try {
			$existing = $this->mapper->findByUuid($context->workspace()->getId(), $uuid, true);
			if ($existing->getDeletedAt() === null
				&& $this->matchesCreate($existing, $source, $target, $typeKey, $contextKey, $isPrimary, $effectiveFrom, $effectiveUntil, $notes)) {
				return $this->toApi($context, $existing);
			}
			throw new RevisionConflictException('The supplied assignment UUID already exists with different data');
		} catch (DoesNotExistException) {
			// UUID is available in this workspace. The database unique key remains the global guard.
		}

		$this->assertPrimaryAvailable(
			$context->workspace()->getId(),
			$source->getId(),
			$typeKey,
			$contextKey,
			$isPrimary,
			$effectiveFrom,
			$effectiveUntil,
			null,
		);

		$now = $this->timeFactory->getTime();
		$assignment = new Assignment();
		$assignment->setWorkspaceId($context->workspace()->getId());
		$assignment->setUuid($uuid);
		$assignment->setSourceAssetId($source->getId());
		$assignment->setTargetAssetId($target->getId());
		$assignment->setTypeKey($typeKey);
		$assignment->setContextKey($contextKey);
		$assignment->setIsPrimary($isPrimary);
		$assignment->setEffectiveFrom($effectiveFrom);
		$assignment->setEffectiveUntil($effectiveUntil);
		$assignment->setNotes($notes);
		$assignment->setRevision(1);
		$assignment->setCreatedAt($now);
		$assignment->setUpdatedAt($now);
		$assignment->setDeletedAt(null);

		try {
			/** @var Assignment $inserted */
			$inserted = $this->mapper->insert($assignment);
			$this->journal->record($inserted->getWorkspaceId(), 'assignment', $uuid, 'upsert', 1, $now);
			return $this->toApi($context, $inserted);
		} catch (DatabaseException $exception) {
			if ($exception->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new RevisionConflictException('The supplied assignment UUID already exists', 0, $exception);
			}
			throw $exception;
		}
	}

	/** @param array<string, mixed> $input @return array<string, mixed> */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}
		$this->assertKnownFields($input, ['context', 'isPrimary', 'effectiveFrom', 'effectiveUntil', 'notes'], 'assignment update');
		$assignment = $this->find($context, $uuid);
		if ($assignment->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The assignment has changed since it was last read');
		}

		$contextKey = array_key_exists('context', $input)
			? $this->validator->optionalKey($input['context'], 'context')
			: $assignment->getContextKey();
		$isPrimary = array_key_exists('isPrimary', $input)
			? $this->validator->boolean($input['isPrimary'], 'isPrimary')
			: $assignment->getIsPrimary();
		$effectiveFrom = array_key_exists('effectiveFrom', $input)
			? $this->validator->date($input['effectiveFrom'], 'effectiveFrom')
			: $assignment->getEffectiveFrom();
		$effectiveUntil = array_key_exists('effectiveUntil', $input)
			? $this->validator->optionalDate($input['effectiveUntil'], 'effectiveUntil')
			: $assignment->getEffectiveUntil();
		$this->validator->assertDateRange($effectiveFrom, $effectiveUntil);
		$notes = array_key_exists('notes', $input)
			? $this->validator->optionalText($input['notes'], 'notes')
			: $assignment->getNotes();

		$this->assertPrimaryAvailable(
			$assignment->getWorkspaceId(),
			$assignment->getSourceAssetId(),
			$assignment->getTypeKey(),
			$contextKey,
			$isPrimary,
			$effectiveFrom,
			$effectiveUntil,
			$assignment->getId(),
		);

		$assignment->setContextKey($contextKey);
		$assignment->setIsPrimary($isPrimary);
		$assignment->setEffectiveFrom($effectiveFrom);
		$assignment->setEffectiveUntil($effectiveUntil);
		$assignment->setNotes($notes);
		$assignment->setRevision($expectedRevision + 1);
		$assignment->setUpdatedAt($this->timeFactory->getTime());
		$this->persistRevision($assignment, $expectedRevision, 'upsert');

		return $this->toApi($context, $assignment);
	}

	/** @return array<string, mixed> */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}
		$assignment = $this->find($context, $uuid);
		if ($assignment->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The assignment has changed since it was last read');
		}
		$now = $this->timeFactory->getTime();
		$assignment->setDeletedAt($now);
		$assignment->setUpdatedAt($now);
		$assignment->setRevision($expectedRevision + 1);
		$this->persistRevision($assignment, $expectedRevision, 'delete');
		return $this->toApi($context, $assignment);
	}

	private function find(WorkspaceContext $context, string $uuid): Assignment {
		try {
			return $this->mapper->findByUuid(
				$context->workspace()->getId(),
				$this->validator->uuid($uuid, 'uuid'),
			);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Assignment not found');
		}
	}

	private function persistRevision(Assignment $assignment, int $expectedRevision, string $operation): void {
		if (!$this->mapper->updateWithExpectedRevision($assignment, $expectedRevision)) {
			throw new RevisionConflictException('The assignment has changed since it was last read');
		}
		$this->journal->record(
			$assignment->getWorkspaceId(),
			'assignment',
			$assignment->getUuid(),
			$operation,
			$assignment->getRevision(),
			$assignment->getUpdatedAt(),
		);
	}

	private function assertPrimaryAvailable(
		int $workspaceId,
		int $sourceAssetId,
		string $typeKey,
		?string $contextKey,
		bool $isPrimary,
		string $effectiveFrom,
		?string $effectiveUntil,
		?int $excludeId,
	): void {
		if (!$isPrimary) {
			return;
		}
		foreach ($this->mapper->findPrimariesForSource($workspaceId, $sourceAssetId, $typeKey, $contextKey) as $existing) {
			if ($excludeId !== null && $existing->getId() === $excludeId) {
				continue;
			}
			if ($this->rangesOverlap(
				$effectiveFrom,
				$effectiveUntil,
				$existing->getEffectiveFrom(),
				$existing->getEffectiveUntil(),
			)) {
				throw new ValidationException('A primary assignment already overlaps this source, type, and context');
			}
		}
	}

	private function rangesOverlap(string $leftFrom, ?string $leftUntil, string $rightFrom, ?string $rightUntil): bool {
		return ($leftUntil === null || $rightFrom <= $leftUntil)
			&& ($rightUntil === null || $leftFrom <= $rightUntil);
	}

	/** @return array<string, mixed> */
	private function toApi(WorkspaceContext $context, Assignment $assignment): array {
		$definition = $this->types->definition($assignment->getTypeKey());
		$source = $this->findAssetForHistory($context, $assignment->getSourceAssetId());
		$target = $this->findAssetForHistory($context, $assignment->getTargetAssetId());
		return [
			'uuid' => $assignment->getUuid(),
			'type' => $assignment->getTypeKey(),
			'label' => $definition['label'],
			'inverseType' => $definition['inverseKey'],
			'inverseLabel' => $definition['inverseLabel'],
			'sourceAsset' => $this->assetReference($source),
			'targetAsset' => $this->assetReference($target),
			'context' => $assignment->getContextKey(),
			'isPrimary' => $assignment->getIsPrimary(),
			'effectiveFrom' => $assignment->getEffectiveFrom(),
			'effectiveUntil' => $assignment->getEffectiveUntil(),
			'notes' => $assignment->getNotes(),
			'revision' => $assignment->getRevision(),
			'createdAt' => $this->formatTimestamp($assignment->getCreatedAt()),
			'updatedAt' => $this->formatTimestamp($assignment->getUpdatedAt()),
			'deletedAt' => $assignment->getDeletedAt() === null ? null : $this->formatTimestamp($assignment->getDeletedAt()),
		];
	}

	private function findAssetForHistory(WorkspaceContext $context, int $assetId): Asset {
		try {
			return $this->assetMapper->findById($context->workspace()->getId(), $assetId, true);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Assignment endpoint asset no longer exists');
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
			throw new ValidationException('An assignment must connect two different assets');
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
		Assignment $assignment,
		Asset $source,
		Asset $target,
		string $typeKey,
		?string $contextKey,
		bool $isPrimary,
		string $effectiveFrom,
		?string $effectiveUntil,
		?string $notes,
	): bool {
		return $assignment->getSourceAssetId() === $source->getId()
			&& $assignment->getTargetAssetId() === $target->getId()
			&& $assignment->getTypeKey() === $typeKey
			&& $assignment->getContextKey() === $contextKey
			&& $assignment->getIsPrimary() === $isPrimary
			&& $assignment->getEffectiveFrom() === $effectiveFrom
			&& $assignment->getEffectiveUntil() === $effectiveUntil
			&& $assignment->getNotes() === $notes;
	}

	private function formatTimestamp(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
	}
}
