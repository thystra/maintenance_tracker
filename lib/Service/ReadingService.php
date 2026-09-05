<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use DateTimeImmutable;
use OCA\MaintenanceTracker\Db\Meter;
use OCA\MaintenanceTracker\Db\Reading;
use OCA\MaintenanceTracker\Db\ReadingMapper;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;

final class ReadingService {
	public function __construct(
		private MeterService $meters,
		private ReadingMapper $mapper,
		private MeterValueConverter $values,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(WorkspaceContext $context, string $meterUuid): array {
		$meter = $this->meters->find($context, $meterUuid, true);
		$rows = $this->mapper->findForMeter($context->workspace()->getId(), $meter->getId());
		$byId = [];
		$supersededBy = [];
		foreach ($rows as $row) {
			$byId[$row->getId()] = $row->getUuid();
			if ($row->getSupersedesId() !== null) {
				$supersededBy[$row->getSupersedesId()] = $row->getUuid();
			}
		}

		return array_map(
			fn (Reading $row): array => $this->toApi(
				$row,
				$row->getSupersedesId() === null ? null : ($byId[$row->getSupersedesId()] ?? null),
				$supersededBy[$row->getId()] ?? null,
			),
			$rows,
		);
	}

	/** @param array<string, mixed> $input @return array<string, mixed> */
	public function create(WorkspaceContext $context, string $meterUuid, array $input): array {
		$meter = $this->meters->find($context, $meterUuid);
		$values = $this->validateInput($meter, $input, true, null);
		return $this->insert($context, $meter, $values, null, 'reading.created', []);
	}

	/** @param array<string, mixed> $input @return array<string, mixed> */
	public function correct(WorkspaceContext $context, string $readingUuid, array $input): array {
		$original = $this->find($context, $readingUuid);
		$meter = $this->meters->findById($context, $original->getMeterId(), true);
		if ($this->mapper->findSuperseding($context->workspace()->getId(), $original->getId()) !== null) {
			$requestedUuid = $input['uuid'] ?? null;
			if (is_string($requestedUuid)) {
				try {
					$existing = $this->mapper->findByUuid($context->workspace()->getId(), strtolower(trim($requestedUuid)));
					if ($existing->getSupersedesId() === $original->getId()) {
						return $this->toApiResolved($context->workspace()->getId(), $existing);
					}
				} catch (DoesNotExistException) {
					// Fall through to the conflict below.
				}
			}
			throw new RevisionConflictException('The reading has already been corrected');
		}

		$values = $this->validateInput($meter, $input, false, $original);
		return $this->insert(
			$context,
			$meter,
			$values,
			$original,
			'reading.corrected',
			['supersedesReadingUuid' => $original->getUuid()],
		);
	}

	public function find(WorkspaceContext $context, string $uuid): Reading {
		$uuid = $this->uuid($uuid, 'uuid');
		try {
			return $this->mapper->findByUuid($context->workspace()->getId(), $uuid);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Reading not found');
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{uuid:string,observedAt:int,canonicalValue:int,originalValue:string,originalUnit:string,sourceType:string,sourceRef:?string,notes:?string}
	 */
	private function validateInput(Meter $meter, array $input, bool $requireObservedAt, ?Reading $corrected): array {
		$this->assertKnownFields($input, ['uuid', 'observedAt', 'value', 'unit', 'source', 'notes'], 'reading');
		if (!array_key_exists('value', $input)) {
			throw new ValidationException('value is required');
		}
		if ($requireObservedAt && !array_key_exists('observedAt', $input)) {
			throw new ValidationException('observedAt is required');
		}
		$observedAt = array_key_exists('observedAt', $input)
			? $this->timestamp($input['observedAt'])
			: $corrected?->getObservedAt();
		if ($observedAt === null) {
			throw new ValidationException('observedAt is required');
		}
		$converted = $this->values->toCanonical(
			$meter->getDimension(),
			$input['value'],
			$input['unit'] ?? $meter->getDisplayUnit(),
		);
		$source = $this->source($input['source'] ?? null);

		return [
			'uuid' => array_key_exists('uuid', $input) ? $this->uuid($input['uuid'], 'uuid') : $this->uuidGenerator->generate(),
			'observedAt' => $observedAt,
			'canonicalValue' => $converted['canonicalValue'],
			'originalValue' => $converted['originalValue'],
			'originalUnit' => $converted['originalUnit'],
			'sourceType' => $source['type'],
			'sourceRef' => $source['reference'],
			'notes' => $this->optionalText($input['notes'] ?? null, 'notes', 20000),
		];
	}

	/**
	 * @param array{uuid:string,observedAt:int,canonicalValue:int,originalValue:string,originalUnit:string,sourceType:string,sourceRef:?string,notes:?string} $values
	 * @param array<string, scalar|null> $auditDetails
	 * @return array<string, mixed>
	 */
	private function insert(
		WorkspaceContext $context,
		Meter $meter,
		array $values,
		?Reading $supersedes,
		string $auditEvent,
		array $auditDetails,
	): array {
		try {
			$existing = $this->mapper->findByUuid($context->workspace()->getId(), $values['uuid']);
			if ($this->matches($existing, $meter->getId(), $values, $supersedes?->getId())) {
				return $this->toApiResolved($context->workspace()->getId(), $existing);
			}
			throw new RevisionConflictException('The supplied reading UUID already exists with different data');
		} catch (DoesNotExistException) {
			// UUID is available.
		}

		if ($meter->getMonotonic()) {
			$this->assertMonotonic(
				$meter,
				$values['observedAt'],
				$values['canonicalValue'],
				$supersedes?->getId(),
			);
		}
		$now = $this->timeFactory->getTime();
		$reading = new Reading();
		$reading->setWorkspaceId($context->workspace()->getId());
		$reading->setMeterId($meter->getId());
		$reading->setUuid($values['uuid']);
		$reading->setObservedAt($values['observedAt']);
		$reading->setCanonicalValue($values['canonicalValue']);
		$reading->setOriginalValue($values['originalValue']);
		$reading->setOriginalUnit($values['originalUnit']);
		$reading->setSourceType($values['sourceType']);
		$reading->setSourceRef($values['sourceRef']);
		$reading->setNotes($values['notes']);
		$reading->setSupersedesId($supersedes?->getId());
		$reading->setCreatedAt($now);

		try {
			/** @var Reading $inserted */
			$inserted = $this->mapper->append($reading);
			$this->journal->record(
				$inserted->getWorkspaceId(),
				'reading',
				$inserted->getUuid(),
				'upsert',
				1,
				$now,
				$auditEvent,
				$auditDetails,
			);
			return $this->toApi($inserted, $supersedes?->getUuid(), null);
		} catch (DatabaseException $exception) {
			if ($exception->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new RevisionConflictException('The reading UUID or correction target already exists', 0, $exception);
			}
			throw $exception;
		}
	}

	private function assertMonotonic(Meter $meter, int $observedAt, int $value, ?int $excludeReadingId): void {
		$predecessor = $this->mapper->findEffectivePredecessor(
			$meter->getWorkspaceId(),
			$meter->getId(),
			$observedAt,
			$excludeReadingId,
		);
		if ($predecessor !== null && $value < $predecessor->getCanonicalValue()) {
			throw new ValidationException('Reading would decrease a monotonic meter');
		}
		$successor = $this->mapper->findEffectiveSuccessor(
			$meter->getWorkspaceId(),
			$meter->getId(),
			$observedAt,
			$excludeReadingId,
		);
		if ($successor !== null && $value > $successor->getCanonicalValue()) {
			throw new ValidationException('Reading would exceed a later value on a monotonic meter');
		}
	}

	/** @return array<string, mixed> */
	private function toApiResolved(int $workspaceId, Reading $reading): array {
		$supersedesUuid = null;
		if ($reading->getSupersedesId() !== null) {
			try {
				$supersedesUuid = $this->mapper->findById($workspaceId, $reading->getSupersedesId())->getUuid();
			} catch (DoesNotExistException) {
				// Account cleanup is serialized; a missing predecessor means corrupt history.
				throw new NotFoundException('Superseded reading not found');
			}
		}
		$superseding = $this->mapper->findSuperseding($workspaceId, $reading->getId());

		return $this->toApi($reading, $supersedesUuid, $superseding?->getUuid());
	}

	/** @return array<string, mixed> */
	private function toApi(Reading $reading, ?string $supersedesUuid, ?string $supersededByUuid): array {
		return [
			'uuid' => $reading->getUuid(),
			'observedAt' => gmdate('Y-m-d\TH:i:s\Z', $reading->getObservedAt()),
			'canonicalValue' => $reading->getCanonicalValue(),
			'originalValue' => $reading->getOriginalValue(),
			'originalUnit' => $reading->getOriginalUnit(),
			'source' => [
				'type' => $reading->getSourceType(),
				'reference' => $reading->getSourceRef(),
			],
			'notes' => $reading->getNotes(),
			'supersedesUuid' => $supersedesUuid,
			'supersededByUuid' => $supersededByUuid,
			'effective' => $supersededByUuid === null,
			'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $reading->getCreatedAt()),
		];
	}

	/** @param array{uuid:string,observedAt:int,canonicalValue:int,originalValue:string,originalUnit:string,sourceType:string,sourceRef:?string,notes:?string} $values */
	private function matches(Reading $reading, int $meterId, array $values, ?int $supersedesId): bool {
		return $reading->getMeterId() === $meterId
			&& $reading->getObservedAt() === $values['observedAt']
			&& $reading->getCanonicalValue() === $values['canonicalValue']
			&& $reading->getOriginalValue() === $values['originalValue']
			&& $reading->getOriginalUnit() === $values['originalUnit']
			&& $reading->getSourceType() === $values['sourceType']
			&& $reading->getSourceRef() === $values['sourceRef']
			&& $reading->getNotes() === $values['notes']
			&& $reading->getSupersedesId() === $supersedesId;
	}

	/** @return array{type:string,reference:?string} */
	private function source(mixed $value): array {
		if ($value === null) {
			return ['type' => 'manual', 'reference' => null];
		}
		if (!is_array($value)) {
			throw new ValidationException('source must be an object');
		}
		$this->assertKnownFields($value, ['type', 'reference'], 'reading source');
		$type = $value['type'] ?? 'manual';
		if (!is_string($type)) {
			throw new ValidationException('source.type must be a string');
		}
		$type = strtolower(trim($type));
		if (preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $type) !== 1 || strlen($type) > 32) {
			throw new ValidationException('source.type must be a lowercase key up to 32 characters');
		}
		return [
			'type' => $type,
			'reference' => $this->optionalText($value['reference'] ?? null, 'source.reference', 2000),
		];
	}

	private function timestamp(mixed $value): int {
		if (!is_string($value)) {
			throw new ValidationException('observedAt must be an ISO-8601 timestamp string');
		}
		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
			throw new ValidationException('observedAt must include seconds and a timezone');
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $value));
		$errors = DateTimeImmutable::getLastErrors();
		if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
			throw new ValidationException('observedAt is not a valid timestamp');
		}
		$timestamp = $date->getTimestamp();
		if ($timestamp < 0) {
			throw new ValidationException('observedAt must not be before 1970-01-01');
		}
		return $timestamp;
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

	private function optionalText(mixed $value, string $field, int $max): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be a string");
		}
		$value = trim($value);
		if (mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
			throw new ValidationException("{$field} contains unsupported content");
		}
		return $value === '' ? null : $value;
	}

	/** @param array<string, mixed> $input @param list<string> $allowed */
	private function assertKnownFields(array $input, array $allowed, string $label): void {
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) {
			throw new ValidationException('Unknown ' . $label . ' fields: ' . implode(', ', $unknown));
		}
	}
}
