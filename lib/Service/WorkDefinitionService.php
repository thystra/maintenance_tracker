<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Component;
use OCA\MaintenanceTracker\Db\Meter;
use OCA\MaintenanceTracker\Db\WorkDefinition;
use OCA\MaintenanceTracker\Db\WorkDefinitionMapper;
use OCA\MaintenanceTracker\Db\WorkGroup;
use OCA\MaintenanceTracker\Db\WorkScheduleRule;
use OCA\MaintenanceTracker\Db\WorkScheduleRuleMapper;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;

final class WorkDefinitionService {
	public function __construct(
		private AssetService $assets,
		private ComponentService $components,
		private WorkGroupService $groups,
		private MeterService $meters,
		private WorkDefinitionMapper $mapper,
		private WorkScheduleRuleMapper $rules,
		private WorkSchedulePolicy $schedulePolicy,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(WorkspaceContext $context, string $assetUuid, ?bool $scheduled = null): array {
		$asset = $this->assets->find($context, $assetUuid);
		return array_map(
			fn (WorkDefinition $definition): array => $this->toApi($context, $definition),
			$this->mapper->findForAsset($context->workspace()->getId(), $asset->getId(), false, $scheduled),
		);
	}

	/** @return array<string,mixed> */
	public function show(WorkspaceContext $context, string $uuid): array {
		return $this->toApi($context, $this->find($context, $uuid));
	}

	public function find(WorkspaceContext $context, string $uuid, bool $includeDeleted = false): WorkDefinition {
		$uuid = $this->uuid($uuid, 'uuid');
		try {
			return $this->mapper->findByUuid($context->workspace()->getId(), $uuid, $includeDeleted);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Work definition not found');
		}
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function create(WorkspaceContext $context, string $assetUuid, array $input): array {
		$this->assertKnownFields($input, [
			'uuid', 'componentUuid', 'groupUuid', 'key', 'title', 'kind',
			'instructions', 'notes', 'status', 'schedule',
		], 'work definition');
		foreach (['key', 'title', 'kind', 'schedule'] as $required) {
			if (!array_key_exists($required, $input)) {
				throw new ValidationException("{$required} is required");
			}
		}

		$asset = $this->assets->find($context, $assetUuid);
		$component = $this->component($context, $asset->getId(), $input['componentUuid'] ?? null);
		$group = $this->group($context, $asset->getId(), $input['groupUuid'] ?? null);
		$key = $this->key($input['key'], 'key');
		$title = $this->text($input['title'], 'title', 255);
		$kind = $this->key($input['kind'], 'kind');
		$instructions = $this->optionalText($input['instructions'] ?? null, 'instructions', 10000);
		$notes = $this->optionalText($input['notes'] ?? null, 'notes', 20000);
		$status = $this->status($input['status'] ?? 'active');
		$schedule = $this->normalizeSchedule($context, $asset->getId(), $input['schedule']);
		$uuid = array_key_exists('uuid', $input)
			? $this->uuid($input['uuid'], 'uuid')
			: $this->uuidGenerator->generate();

		try {
			$existing = $this->mapper->findByUuid($context->workspace()->getId(), $uuid, true);
			if (
				$existing->getDeletedAt() === null
				&& $existing->getAssetId() === $asset->getId()
				&& $existing->getComponentId() === $component?->getId()
				&& $existing->getGroupId() === $group?->getId()
				&& $existing->getDefinitionKey() === $key
				&& $existing->getTitle() === $title
				&& $existing->getKind() === $kind
				&& $existing->getInstructions() === $instructions
				&& $existing->getNotes() === $notes
				&& $existing->getStatus() === $status
				&& $this->scheduleMatches($existing, $schedule)
			) {
				return $this->toApi($context, $existing);
			}
			throw new RevisionConflictException('The supplied work-definition UUID already exists with different data');
		} catch (DoesNotExistException) {
			// UUID is available.
		}

		$this->assertKeyAvailable($context->workspace()->getId(), $asset->getId(), $key, null);
		$now = $this->timeFactory->getTime();
		$definition = new WorkDefinition();
		$definition->setWorkspaceId($context->workspace()->getId());
		$definition->setAssetId($asset->getId());
		$definition->setComponentId($component?->getId());
		$definition->setGroupId($group?->getId());
		$definition->setUuid($uuid);
		$definition->setDefinitionKey($key);
		$definition->setTitle($title);
		$definition->setKind($kind);
		$definition->setInstructions($instructions);
		$definition->setNotes($notes);
		$definition->setStatus($status);
		$definition->setScheduleType($schedule['type']);
		$definition->setScheduleCombination($schedule['combination']);
		$definition->setRevision(1);
		$definition->setCreatedAt($now);
		$definition->setUpdatedAt($now);
		$definition->setDeletedAt(null);

		try {
			/** @var WorkDefinition $inserted */
			$inserted = $this->mapper->insert($definition);
			$this->replaceRules($inserted, $schedule['rules']);
			$this->journal->record($inserted->getWorkspaceId(), 'work_definition', $uuid, 'upsert', 1, $now);
			return $this->toApi($context, $inserted);
		} catch (DatabaseException $exception) {
			if ($exception->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new RevisionConflictException('The supplied work-definition UUID already exists', 0, $exception);
			}
			throw $exception;
		}
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}
		$this->assertKnownFields(
			$input,
			['groupUuid', 'key', 'title', 'kind', 'instructions', 'notes', 'status', 'schedule'],
			'work-definition update',
		);
		$definition = $this->find($context, $uuid);
		if ($definition->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The work definition has changed since it was last read');
		}

		$key = array_key_exists('key', $input)
			? $this->key($input['key'], 'key')
			: $definition->getDefinitionKey();
		$this->assertKeyAvailable($definition->getWorkspaceId(), $definition->getAssetId(), $key, $definition->getId());
		$definition->setDefinitionKey($key);
		if (array_key_exists('groupUuid', $input)) {
			$definition->setGroupId($this->group($context, $definition->getAssetId(), $input['groupUuid'])?->getId());
		}
		if (array_key_exists('title', $input)) {
			$definition->setTitle($this->text($input['title'], 'title', 255));
		}
		if (array_key_exists('kind', $input)) {
			$definition->setKind($this->key($input['kind'], 'kind'));
		}
		if (array_key_exists('instructions', $input)) {
			$definition->setInstructions($this->optionalText($input['instructions'], 'instructions', 10000));
		}
		if (array_key_exists('notes', $input)) {
			$definition->setNotes($this->optionalText($input['notes'], 'notes', 20000));
		}
		if (array_key_exists('status', $input)) {
			$definition->setStatus($this->status($input['status']));
		}

		$schedule = null;
		if (array_key_exists('schedule', $input)) {
			$schedule = $this->normalizeSchedule($context, $definition->getAssetId(), $input['schedule']);
			$definition->setScheduleType($schedule['type']);
			$definition->setScheduleCombination($schedule['combination']);
		}

		$definition->setRevision($expectedRevision + 1);
		$definition->setUpdatedAt($this->timeFactory->getTime());
		if (!$this->mapper->updateWithExpectedRevision($definition, $expectedRevision)) {
			throw new RevisionConflictException('The work definition has changed since it was last read');
		}
		if ($schedule !== null) {
			$this->replaceRules($definition, $schedule['rules']);
		}
		$this->journal->record(
			$definition->getWorkspaceId(),
			'work_definition',
			$definition->getUuid(),
			'upsert',
			$definition->getRevision(),
			$definition->getUpdatedAt(),
		);
		return $this->toApi($context, $definition);
	}

	/** @return array<string,mixed> */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}
		$definition = $this->find($context, $uuid);
		if ($definition->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The work definition has changed since it was last read');
		}
		$now = $this->timeFactory->getTime();
		$definition->setRevision($expectedRevision + 1);
		$definition->setUpdatedAt($now);
		$definition->setDeletedAt($now);
		if (!$this->mapper->updateWithExpectedRevision($definition, $expectedRevision)) {
			throw new RevisionConflictException('The work definition has changed since it was last read');
		}
		$this->journal->record(
			$definition->getWorkspaceId(),
			'work_definition',
			$definition->getUuid(),
			'delete',
			$definition->getRevision(),
			$now,
		);
		return $this->toApi($context, $definition);
	}

	/** @return array<string,mixed> */
	public function toApi(WorkspaceContext $context, WorkDefinition $definition): array {
		$componentUuid = $definition->getComponentId() === null
			? null
			: $this->components->findById($context, $definition->getComponentId(), true)->getUuid();
		$groupUuid = $definition->getGroupId() === null
			? null
			: $this->groups->findById($context, $definition->getGroupId(), true)->getUuid();
		return [
			'uuid' => $definition->getUuid(),
			'componentUuid' => $componentUuid,
			'groupUuid' => $groupUuid,
			'key' => $definition->getDefinitionKey(),
			'title' => $definition->getTitle(),
			'kind' => $definition->getKind(),
			'instructions' => $definition->getInstructions(),
			'notes' => $definition->getNotes(),
			'status' => $definition->getStatus(),
			'schedule' => $this->scheduleToApi($context, $definition),
			'revision' => $definition->getRevision(),
			'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $definition->getCreatedAt()),
			'updatedAt' => gmdate('Y-m-d\TH:i:s\Z', $definition->getUpdatedAt()),
			'deletedAt' => $definition->getDeletedAt() === null ? null : gmdate('Y-m-d\TH:i:s\Z', $definition->getDeletedAt()),
		];
	}

	/** @return array{type:string, combination:?string, rules:list<array<string,mixed>>} */
	private function normalizeSchedule(WorkspaceContext $context, int $assetId, mixed $schedule): array {
		return $this->schedulePolicy->normalize(
			$schedule,
			$assetId,
			function (string $meterUuid) use ($context): array {
				$meter = $this->meters->find($context, $meterUuid);
				return [
					'id' => $meter->getId(),
					'assetId' => $meter->getAssetId(),
					'dimension' => $meter->getDimension(),
				];
			},
		);
	}

	/** @return string|array{combination:string,rules:list<array<string,mixed>>} */
	private function scheduleToApi(WorkspaceContext $context, WorkDefinition $definition): string|array {
		if ($definition->getScheduleType() === 'none') {
			return 'none';
		}
		$items = [];
		foreach ($this->rules->findForDefinition($definition->getWorkspaceId(), $definition->getId()) as $rule) {
			$interval = [
				'value' => $rule->getRuleType() === 'meter' ? $rule->getIntervalValue() : (int)$rule->getIntervalValue(),
				'unit' => $rule->getIntervalUnit(),
			];
			$items[] = match ($rule->getRuleType()) {
				'calendar' => ['type' => 'calendar', 'interval' => $interval],
				'business_days' => [
					'type' => 'business_days',
					'interval' => $interval,
					'weekdays' => $this->schedulePolicy->weekdaysFromMask($rule->getWeekdayMask() ?? 0),
				],
				'meter' => [
					'type' => 'meter',
					'meterUuid' => $this->meters->findById($context, $rule->getMeterId() ?? 0, true)->getUuid(),
					'interval' => $interval,
				],
				default => throw new \LogicException('Unsupported persisted schedule rule'),
			};
		}
		return [
			'combination' => $definition->getScheduleCombination() ?? 'any',
			'rules' => $items,
		];
	}

	/** @param array{type:string, combination:?string, rules:list<array<string,mixed>>} $schedule */
	private function scheduleMatches(WorkDefinition $definition, array $schedule): bool {
		if ($definition->getScheduleType() !== $schedule['type'] || $definition->getScheduleCombination() !== $schedule['combination']) {
			return false;
		}
		$existing = $this->rules->findForDefinition($definition->getWorkspaceId(), $definition->getId());
		if (count($existing) !== count($schedule['rules'])) {
			return false;
		}
		foreach ($existing as $index => $rule) {
			$expected = $schedule['rules'][$index];
			if (
				$rule->getPosition() !== $expected['position']
				|| $rule->getRuleType() !== $expected['type']
				|| $rule->getIntervalValue() !== $expected['intervalValue']
				|| $rule->getIntervalUnit() !== $expected['intervalUnit']
				|| $rule->getCanonicalValue() !== $expected['canonicalValue']
				|| $rule->getMeterId() !== $expected['meterId']
				|| $rule->getWeekdayMask() !== $expected['weekdayMask']
			) {
				return false;
			}
		}
		return true;
	}

	/** @param list<array<string,mixed>> $normalizedRules */
	private function replaceRules(WorkDefinition $definition, array $normalizedRules): void {
		$this->rules->deleteForDefinition($definition->getWorkspaceId(), $definition->getId());
		foreach ($normalizedRules as $normalized) {
			$rule = new WorkScheduleRule();
			$rule->setWorkspaceId($definition->getWorkspaceId());
			$rule->setDefinitionId($definition->getId());
			$rule->setPosition($normalized['position']);
			$rule->setRuleType($normalized['type']);
			$rule->setIntervalValue($normalized['intervalValue']);
			$rule->setIntervalUnit($normalized['intervalUnit']);
			$rule->setCanonicalValue($normalized['canonicalValue']);
			$rule->setMeterId($normalized['meterId']);
			$rule->setWeekdayMask($normalized['weekdayMask']);
			$this->rules->insert($rule);
		}
	}

	private function component(WorkspaceContext $context, int $assetId, mixed $uuid): ?Component {
		if ($uuid === null || $uuid === '') {
			return null;
		}
		$component = $this->components->find($context, $this->uuid($uuid, 'componentUuid'));
		if ($component->getAssetId() !== $assetId) {
			throw new ValidationException('componentUuid must belong to the work-definition asset');
		}
		return $component;
	}

	private function group(WorkspaceContext $context, int $assetId, mixed $uuid): ?WorkGroup {
		if ($uuid === null || $uuid === '') {
			return null;
		}
		$group = $this->groups->find($context, $this->uuid($uuid, 'groupUuid'));
		if ($group->getAssetId() !== $assetId) {
			throw new ValidationException('groupUuid must belong to the work-definition asset');
		}
		return $group;
	}

	private function assertKeyAvailable(int $workspaceId, int $assetId, string $key, ?int $excludeId): void {
		foreach ($this->mapper->findByAssetAndKey($workspaceId, $assetId, $key) as $existing) {
			if ($excludeId === null || $existing->getId() !== $excludeId) {
				throw new ValidationException('An active work definition with this key already exists for the asset');
			}
		}
	}

	private function status(mixed $value): string {
		if (!is_string($value) || !in_array($value, ['active', 'suppressed', 'retired'], true)) {
			throw new ValidationException('Unsupported work-definition status');
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

	/** @param list<string> $allowed */
	private function assertKnownFields(array $input, array $allowed, string $label): void {
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) {
			throw new ValidationException('Unknown ' . $label . ' fields: ' . implode(', ', $unknown));
		}
	}
}
