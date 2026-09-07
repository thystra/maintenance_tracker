<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use DateTimeImmutable;
use OCA\MaintenanceTracker\Db\Activity;
use OCA\MaintenanceTracker\Db\ActivityItemMapper;
use OCA\MaintenanceTracker\Db\ActivityMapper;
use OCA\MaintenanceTracker\Db\ActivityMeter;
use OCA\MaintenanceTracker\Db\ActivityMeterMapper;
use OCA\MaintenanceTracker\Db\Reading;
use OCA\MaintenanceTracker\Db\ReadingMapper;
use OCA\MaintenanceTracker\Db\WorkDefinition;
use OCA\MaintenanceTracker\Db\WorkDefinitionMapper;
use OCA\MaintenanceTracker\Db\WorkScheduleRule;
use OCA\MaintenanceTracker\Db\WorkScheduleRuleMapper;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Derived maintenance status projection. Nothing in this service persists due
 * state; schedules, activity history, and effective meter readings remain the
 * authoritative inputs.
 */
final class MaintenanceStatusService {
	private const STATE_PRIORITY = [
		'overdue' => 0,
		'due' => 1,
		'unknown' => 2,
		'baseline_required' => 3,
		'upcoming' => 4,
		'unscheduled' => 5,
		'inactive' => 6,
	];

	public function __construct(
		private AssetService $assets,
		private WorkDefinitionMapper $definitions,
		private WorkDefinitionService $definitionService,
		private WorkScheduleRuleMapper $scheduleRules,
		private ActivityMapper $activities,
		private ActivityItemMapper $activityItems,
		private ActivityMeterMapper $activityMeters,
		private ReadingMapper $readings,
		private MeterService $meters,
		private DueStatePolicy $policy,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return array{workspace:string,assetUuid:string,asOf:string,items:list<array<string,mixed>>} */
	public function forAsset(WorkspaceContext $context, string $assetUuid, ?string $asOf = null): array {
		$asset = $this->assets->find($context, $assetUuid);
		$asOfTimestamp = $this->asOf($asOf);
		$definitions = $this->definitions->findForAsset(
			$context->workspace()->getId(),
			$asset->getId(),
		);
		$completionByDefinition = $this->latestCompletions(
			$context,
			$asset->getId(),
			$asOfTimestamp,
		);

		$items = array_map(
			fn (WorkDefinition $definition): array => $this->forDefinition(
				$context,
				$definition,
				$completionByDefinition[$definition->getId()] ?? null,
				$asOfTimestamp,
			),
			$definitions,
		);
		usort($items, function (array $left, array $right): int {
			$priority = self::STATE_PRIORITY[$left['state']] <=> self::STATE_PRIORITY[$right['state']];
			return $priority !== 0 ? $priority : strcasecmp((string)$left['definition']['title'], (string)$right['definition']['title']);
		});

		return [
			'workspace' => $context->workspace()->getUuid(),
			'assetUuid' => $asset->getUuid(),
			'asOf' => $this->formatTimestamp($asOfTimestamp),
			'items' => $items,
		];
	}

	/** @return array<string,mixed> */
	private function forDefinition(
		WorkspaceContext $context,
		WorkDefinition $definition,
		?Activity $completion,
		int $asOf,
	): array {
		$definitionApi = $this->definitionService->toApi($context, $definition);
		if ($definition->getStatus() !== 'active') {
			return $this->projection($definitionApi, 'inactive', 'definition_not_active', null, []);
		}
		if ($definition->getScheduleType() === 'none') {
			return $this->projection($definitionApi, 'unscheduled', null, $completion, []);
		}
		if ($completion === null) {
			return $this->projection($definitionApi, 'baseline_required', 'no_completed_activity', null, []);
		}

		$rules = [];
		$activityMeterById = [];
		foreach ($this->activityMeters->findForActivity($completion->getWorkspaceId(), $completion->getId()) as $snapshot) {
			$activityMeterById[$snapshot->getMeterId()] = $snapshot;
		}
		foreach ($this->scheduleRules->findForDefinition($definition->getWorkspaceId(), $definition->getId()) as $rule) {
			$rules[] = $this->ruleState($context, $rule, $completion, $activityMeterById, $asOf);
		}
		if ($rules === []) {
			return $this->projection($definitionApi, 'unknown', 'schedule_rules_missing', $completion, []);
		}

		$state = $this->aggregateState($rules);
		$trigger = $this->triggerRulePosition($rules, $state);

		return [
			...$this->projection($definitionApi, $state, $state === 'unknown' ? 'rule_data_incomplete' : null, $completion, $rules),
			'triggerRulePosition' => $trigger,
		];
	}

	/**
	 * @param array<int,ActivityMeter> $activityMeterById
	 * @return array<string,mixed>
	 */
	private function ruleState(
		WorkspaceContext $context,
		WorkScheduleRule $rule,
		Activity $completion,
		array $activityMeterById,
		int $asOf,
	): array {
		$base = [
			'position' => $rule->getPosition(),
			'type' => $rule->getRuleType(),
			'state' => 'unknown',
			'reason' => null,
		];
		if ($rule->getRuleType() === 'calendar') {
			$result = $this->policy->calendar(
				$completion->getPerformedAt(),
				$asOf,
				(int)$rule->getIntervalValue(),
				$rule->getIntervalUnit(),
			);
			return [
				...$base,
				...$result,
				'interval' => ['value' => (int)$rule->getIntervalValue(), 'unit' => $rule->getIntervalUnit()],
			];
		}
		if ($rule->getRuleType() === 'business_days') {
			$result = $this->policy->businessDays(
				$completion->getPerformedAt(),
				$asOf,
				(int)$rule->getIntervalValue(),
				$rule->getWeekdayMask() ?? 0,
			);
			return [
				...$base,
				...$result,
				'interval' => ['value' => (int)$rule->getIntervalValue(), 'unit' => 'business_day'],
			];
		}
		if ($rule->getRuleType() !== 'meter' || $rule->getMeterId() === null || $rule->getCanonicalValue() === null) {
			return [...$base, 'reason' => 'unsupported_schedule_rule'];
		}

		$meter = $this->meters->findById($context, $rule->getMeterId(), true);
		$snapshot = $activityMeterById[$meter->getId()] ?? null;
		$baseline = $snapshot instanceof ActivityMeter
			? $snapshot->getCanonicalValue()
			: $this->readings->findEffectivePredecessor(
				$context->workspace()->getId(),
				$meter->getId(),
				$completion->getPerformedAt(),
				null,
			)?->getCanonicalValue();
		if ($baseline === null) {
			return [
				...$base,
				'reason' => 'meter_baseline_missing',
				'meter' => $this->meterReference($meter->getUuid(), $meter->getName(), $meter->getCanonicalUnit(), $meter->getDisplayUnit()),
			];
		}
		$current = $this->readings->findEffectivePredecessor(
			$context->workspace()->getId(),
			$meter->getId(),
			$asOf,
			null,
		);
		if ($current === null) {
			return [
				...$base,
				'reason' => 'meter_current_missing',
				'meter' => $this->meterReference($meter->getUuid(), $meter->getName(), $meter->getCanonicalUnit(), $meter->getDisplayUnit()),
				'baselineCanonicalValue' => $baseline,
			];
		}
		if ($current->getCanonicalValue() < $baseline) {
			return [
				...$base,
				'reason' => 'meter_decreased_since_service',
				'meter' => $this->meterReference($meter->getUuid(), $meter->getName(), $meter->getCanonicalUnit(), $meter->getDisplayUnit()),
				'baselineCanonicalValue' => $baseline,
				'current' => $this->readingReference($current),
			];
		}
		try {
			$result = $this->policy->meter($baseline, $current->getCanonicalValue(), $rule->getCanonicalValue());
		} catch (\InvalidArgumentException) {
			return [
				...$base,
				'reason' => 'meter_due_value_out_of_range',
				'meter' => $this->meterReference($meter->getUuid(), $meter->getName(), $meter->getCanonicalUnit(), $meter->getDisplayUnit()),
				'baselineCanonicalValue' => $baseline,
				'current' => $this->readingReference($current),
			];
		}

		return [
			...$base,
			...$result,
			'interval' => [
				'value' => $rule->getIntervalValue(),
				'unit' => $rule->getIntervalUnit(),
				'canonicalValue' => $rule->getCanonicalValue(),
			],
			'meter' => $this->meterReference($meter->getUuid(), $meter->getName(), $meter->getCanonicalUnit(), $meter->getDisplayUnit()),
			'baselineCanonicalValue' => $baseline,
			'current' => $this->readingReference($current),
		];
	}

	/** @return array<int,Activity> */
	private function latestCompletions(WorkspaceContext $context, int $assetId, int $asOf): array {
		$byDefinition = [];
		foreach ($this->activities->findForAsset($context->workspace()->getId(), $assetId) as $activity) {
			if ($activity->getPerformedAt() > $asOf) {
				continue;
			}
			foreach ($this->activityItems->findForActivity($activity->getWorkspaceId(), $activity->getId()) as $item) {
				$definitionId = $item->getDefinitionId();
				if ($definitionId !== null && !isset($byDefinition[$definitionId])) {
					$byDefinition[$definitionId] = $activity;
				}
			}
		}
		return $byDefinition;
	}

	/** @param list<array<string,mixed>> $rules */
	private function aggregateState(array $rules): string {
		$states = array_column($rules, 'state');
		if (in_array('overdue', $states, true)) {
			return 'overdue';
		}
		if (in_array('due', $states, true)) {
			return 'due';
		}
		if (in_array('unknown', $states, true)) {
			return 'unknown';
		}
		return 'upcoming';
	}

	/** @param list<array<string,mixed>> $rules */
	private function triggerRulePosition(array $rules, string $state): ?int {
		if (!in_array($state, ['due', 'overdue'], true)) {
			return null;
		}
		foreach ($rules as $rule) {
			if ($rule['state'] === $state) {
				return (int)$rule['position'];
			}
		}
		return null;
	}

	/** @param array<string,mixed> $definition @param list<array<string,mixed>> $rules @return array<string,mixed> */
	private function projection(array $definition, string $state, ?string $reason, ?Activity $completion, array $rules): array {
		return [
			'definition' => $definition,
			'state' => $state,
			'reason' => $reason,
			'lastPerformedAt' => $completion === null ? null : $this->formatTimestamp($completion->getPerformedAt()),
			'lastActivityUuid' => $completion?->getUuid(),
			'rules' => $rules,
			'triggerRulePosition' => null,
		];
	}

	/** @return array{uuid:string,name:string,canonicalUnit:string,displayUnit:string} */
	private function meterReference(string $uuid, string $name, string $canonicalUnit, string $displayUnit): array {
		return compact('uuid', 'name', 'canonicalUnit', 'displayUnit');
	}

	/** @return array{uuid:string,observedAt:string,canonicalValue:int,originalValue:string,originalUnit:string} */
	private function readingReference(Reading $reading): array {
		return [
			'uuid' => $reading->getUuid(),
			'observedAt' => $this->formatTimestamp($reading->getObservedAt()),
			'canonicalValue' => $reading->getCanonicalValue(),
			'originalValue' => $reading->getOriginalValue(),
			'originalUnit' => $reading->getOriginalUnit(),
		];
	}

	private function asOf(?string $value): int {
		if ($value === null || trim($value) === '') {
			return $this->timeFactory->getTime();
		}
		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
			throw new ValidationException('asOf must include seconds and a timezone');
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $value));
		$errors = DateTimeImmutable::getLastErrors();
		if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->getTimestamp() < 0) {
			throw new ValidationException('asOf is not a valid timestamp');
		}
		return $date->getTimestamp();
	}

	private function formatTimestamp(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
	}
}
