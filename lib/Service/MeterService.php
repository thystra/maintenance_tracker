<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Component;
use OCA\MaintenanceTracker\Db\Meter;
use OCA\MaintenanceTracker\Db\MeterMapper;
use OCA\MaintenanceTracker\Db\ReadingMapper;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;

final class MeterService {
	public function __construct(
		private AssetService $assets,
		private ComponentService $components,
		private MeterMapper $mapper,
		private ReadingMapper $readings,
		private MeterValueConverter $values,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(WorkspaceContext $context, string $assetUuid): array {
		$asset = $this->assets->find($context, $assetUuid);
		return array_map(
			fn (Meter $meter): array => $this->toApi($context, $meter),
			$this->mapper->findForAsset($context->workspace()->getId(), $asset->getId()),
		);
	}

	/** @return array<string, mixed> */
	public function show(WorkspaceContext $context, string $uuid): array {
		return $this->toApi($context, $this->find($context, $uuid));
	}

	public function findById(WorkspaceContext $context, int $id, bool $includeDeleted = false): Meter {
		try {
			return $this->mapper->findById($context->workspace()->getId(), $id, $includeDeleted);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Meter not found');
		}
	}

	public function find(WorkspaceContext $context, string $uuid, bool $includeDeleted = false): Meter {
		$uuid = $this->uuid($uuid, 'uuid');
		try {
			return $this->mapper->findByUuid($context->workspace()->getId(), $uuid, $includeDeleted);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Meter not found');
		}
	}

	/** @param array<string, mixed> $input @return array<string, mixed> */
	public function create(WorkspaceContext $context, string $assetUuid, array $input): array {
		$this->assertKnownFields($input, [
			'uuid', 'componentUuid', 'key', 'name', 'dimension', 'displayUnit', 'monotonic',
		], 'meter');
		foreach (['key', 'name', 'dimension', 'displayUnit'] as $required) {
			if (!array_key_exists($required, $input)) {
				throw new ValidationException("{$required} is required");
			}
		}

		$asset = $this->assets->find($context, $assetUuid);
		$component = $this->component($context, $asset->getId(), $input['componentUuid'] ?? null);
		$key = $this->key($input['key'], 'key');
		$name = $this->text($input['name'], 'name', 255);
		$dimension = $this->dimension($input['dimension']);
		$displayUnit = $this->values->validateDisplayUnit($dimension, $input['displayUnit']);
		$monotonic = array_key_exists('monotonic', $input)
			? $this->boolean($input['monotonic'], 'monotonic')
			: true;
		$uuid = array_key_exists('uuid', $input)
			? $this->uuid($input['uuid'], 'uuid')
			: $this->uuidGenerator->generate();

		try {
			$existing = $this->mapper->findByUuid($context->workspace()->getId(), $uuid, true);
			if ($existing->getDeletedAt() === null
				&& $this->matchesCreate($existing, $asset->getId(), $component?->getId(), $key, $name, $dimension, $displayUnit, $monotonic)) {
				return $this->toApi($context, $existing);
			}
			throw new RevisionConflictException('The supplied meter UUID already exists with different data');
		} catch (DoesNotExistException) {
			// UUID is available.
		}

		$this->assertKeyAvailable($context->workspace()->getId(), $asset->getId(), $component?->getId(), $key, null);
		$now = $this->timeFactory->getTime();
		$meter = new Meter();
		$meter->setWorkspaceId($context->workspace()->getId());
		$meter->setAssetId($asset->getId());
		$meter->setComponentId($component?->getId());
		$meter->setUuid($uuid);
		$meter->setMeterKey($key);
		$meter->setName($name);
		$meter->setDimension($dimension);
		$meter->setCanonicalUnit($this->values->canonicalUnit($dimension));
		$meter->setDisplayUnit($displayUnit);
		$meter->setMonotonic($monotonic);
		$meter->setRevision(1);
		$meter->setCreatedAt($now);
		$meter->setUpdatedAt($now);
		$meter->setDeletedAt(null);

		try {
			/** @var Meter $inserted */
			$inserted = $this->mapper->insert($meter);
			$this->journal->record($inserted->getWorkspaceId(), 'meter', $uuid, 'upsert', 1, $now);
			return $this->toApi($context, $inserted);
		} catch (DatabaseException $exception) {
			if ($exception->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new RevisionConflictException('The supplied meter UUID already exists', 0, $exception);
			}
			throw $exception;
		}
	}

	/** @param array<string, mixed> $input @return array<string, mixed> */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}
		$this->assertKnownFields($input, ['key', 'name', 'displayUnit', 'monotonic'], 'meter update');
		$meter = $this->find($context, $uuid);
		if ($meter->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The meter has changed since it was last read');
		}

		$key = array_key_exists('key', $input) ? $this->key($input['key'], 'key') : $meter->getMeterKey();
		$name = array_key_exists('name', $input) ? $this->text($input['name'], 'name', 255) : $meter->getName();
		$displayUnit = array_key_exists('displayUnit', $input)
			? $this->values->validateDisplayUnit($meter->getDimension(), $input['displayUnit'])
			: $meter->getDisplayUnit();
		$monotonic = array_key_exists('monotonic', $input)
			? $this->boolean($input['monotonic'], 'monotonic')
			: $meter->getMonotonic();
		if (!$meter->getMonotonic() && $monotonic) {
			$this->assertHistoryCanBeMonotonic($meter);
		}

		$this->assertKeyAvailable(
			$meter->getWorkspaceId(),
			$meter->getAssetId(),
			$meter->getComponentId(),
			$key,
			$meter->getId(),
		);
		$meter->setMeterKey($key);
		$meter->setName($name);
		$meter->setDisplayUnit($displayUnit);
		$meter->setMonotonic($monotonic);
		$meter->setRevision($expectedRevision + 1);
		$meter->setUpdatedAt($this->timeFactory->getTime());
		$this->persistRevision($meter, $expectedRevision, 'upsert');

		return $this->toApi($context, $meter);
	}

	/** @return array<string, mixed> */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}
		$meter = $this->find($context, $uuid);
		if ($meter->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The meter has changed since it was last read');
		}
		$now = $this->timeFactory->getTime();
		$meter->setRevision($expectedRevision + 1);
		$meter->setUpdatedAt($now);
		$meter->setDeletedAt($now);
		$this->persistRevision($meter, $expectedRevision, 'delete');

		return $this->toApi($context, $meter);
	}

	/** @return array<string, mixed> */
	public function toApi(WorkspaceContext $context, Meter $meter): array {
		$componentUuid = null;
		if ($meter->getComponentId() !== null) {
			$componentUuid = $this->components
				->findById($context, $meter->getComponentId(), true)
				->getUuid();
		}

		return [
			'uuid' => $meter->getUuid(),
			'componentUuid' => $componentUuid,
			'key' => $meter->getMeterKey(),
			'name' => $meter->getName(),
			'dimension' => $meter->getDimension(),
			'canonicalUnit' => $meter->getCanonicalUnit(),
			'displayUnit' => $meter->getDisplayUnit(),
			'monotonic' => $meter->getMonotonic(),
			'revision' => $meter->getRevision(),
			'createdAt' => $this->formatTimestamp($meter->getCreatedAt()),
			'updatedAt' => $this->formatTimestamp($meter->getUpdatedAt()),
			'deletedAt' => $meter->getDeletedAt() === null ? null : $this->formatTimestamp($meter->getDeletedAt()),
		];
	}

	private function component(WorkspaceContext $context, int $assetId, mixed $uuid): ?Component {
		if ($uuid === null || $uuid === '') {
			return null;
		}
		$component = $this->components->find($context, $this->uuid($uuid, 'componentUuid'));
		if ($component->getAssetId() !== $assetId) {
			throw new ValidationException('componentUuid must belong to the meter asset');
		}
		return $component;
	}

	private function assertHistoryCanBeMonotonic(Meter $meter): void {
		$rows = $this->readings->findForMeter($meter->getWorkspaceId(), $meter->getId());
		$superseded = [];
		foreach ($rows as $row) {
			if ($row->getSupersedesId() !== null) {
				$superseded[$row->getSupersedesId()] = true;
			}
		}

		$previous = null;
		foreach ($rows as $row) {
			if (isset($superseded[$row->getId()])) {
				continue;
			}
			if ($previous !== null) {
				if ($row->getCanonicalValue() < $previous->getCanonicalValue()) {
					throw new ValidationException('Existing readings are not monotonic');
				}
				if ($row->getObservedAt() === $previous->getObservedAt()
					&& $row->getCanonicalValue() !== $previous->getCanonicalValue()) {
					throw new ValidationException('Existing same-time readings disagree on a monotonic meter');
				}
			}
			$previous = $row;
		}
	}

	private function assertKeyAvailable(int $workspaceId, int $assetId, ?int $componentId, string $key, ?int $excludeId): void {
		foreach ($this->mapper->findByTargetAndKey($workspaceId, $assetId, $componentId, $key) as $existing) {
			if ($excludeId === null || $existing->getId() !== $excludeId) {
				throw new ValidationException('An active meter with this key already exists for the target');
			}
		}
	}

	private function persistRevision(Meter $meter, int $expectedRevision, string $operation): void {
		if (!$this->mapper->updateWithExpectedRevision($meter, $expectedRevision)) {
			throw new RevisionConflictException('The meter has changed since it was last read');
		}
		$this->journal->record(
			$meter->getWorkspaceId(),
			'meter',
			$meter->getUuid(),
			$operation,
			$meter->getRevision(),
			$meter->getUpdatedAt(),
		);
	}

	private function matchesCreate(Meter $meter, int $assetId, ?int $componentId, string $key, string $name, string $dimension, string $displayUnit, bool $monotonic): bool {
		return $meter->getAssetId() === $assetId
			&& $meter->getComponentId() === $componentId
			&& $meter->getMeterKey() === $key
			&& $meter->getName() === $name
			&& $meter->getDimension() === $dimension
			&& $meter->getDisplayUnit() === $displayUnit
			&& $meter->getMonotonic() === $monotonic;
	}

	private function dimension(mixed $value): string {
		if (!is_string($value)) {
			throw new ValidationException('dimension must be a string');
		}
		$value = strtolower(trim($value));
		if (!in_array($value, $this->values->dimensions(), true)) {
			throw new ValidationException('Unsupported meter dimension');
		}
		return $value;
	}

	private function key(mixed $value, string $field): string {
		$value = $this->text($value, $field, 64);
		if (preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $value) !== 1) {
			throw new ValidationException("{$field} must be a lowercase key");
		}
		return $value;
	}

	private function uuid(mixed $value, string $field): string {
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be a UUID string");
		}
		$value = strtolower(trim($value));
		if (!UuidGenerator::isValid($value)) {
			throw new ValidationException("{$field} must be an RFC 4122 version 4 UUID");
		}
		return $value;
	}

	private function boolean(mixed $value, string $field): bool {
		if (!is_bool($value)) {
			throw new ValidationException("{$field} must be a boolean");
		}
		return $value;
	}

	private function text(mixed $value, string $field, int $max): string {
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be a string");
		}
		$value = trim($value);
		if ($value === '') {
			throw new ValidationException("{$field} cannot be empty");
		}
		if (mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
			throw new ValidationException("{$field} contains unsupported content");
		}
		return $value;
	}

	/** @param array<string, mixed> $input @param list<string> $allowed */
	private function assertKnownFields(array $input, array $allowed, string $label): void {
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) {
			throw new ValidationException('Unknown ' . $label . ' fields: ' . implode(', ', $unknown));
		}
	}

	private function formatTimestamp(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
	}
}
