<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\ReminderPolicy;
use OCA\MaintenanceTracker\Db\ReminderPolicyMapper;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;

final class ReminderPolicyService {
	public const DEFAULT_CALENDAR_LEAD_DAYS = 14;
	public const DEFAULT_METER_LEAD_PERCENT = 10;

	public function __construct(
		private ReminderPolicyMapper $mapper,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return array{calendarLeadDays:int,meterLeadPercent:int,revision:int,source:string} */
	public function get(WorkspaceContext $context): array {
		try {
			return $this->toApi($this->mapper->findForWorkspace($context->workspace()->getId()), 'workspace');
		} catch (DoesNotExistException) {
			return [
				'calendarLeadDays' => self::DEFAULT_CALENDAR_LEAD_DAYS,
				'meterLeadPercent' => self::DEFAULT_METER_LEAD_PERCENT,
				'revision' => 0,
				'source' => 'default',
			];
		}
	}

	/** @param array<string,mixed> $input @return array{calendarLeadDays:int,meterLeadPercent:int,revision:int,source:string} */
	public function update(WorkspaceContext $context, int $expectedRevision, array $input): array {
		if ($expectedRevision < 0) {
			throw new ValidationException('expectedRevision cannot be negative');
		}
		$unknown = array_diff(array_keys($input), ['calendarLeadDays', 'meterLeadPercent']);
		if ($unknown !== []) {
			throw new ValidationException('Unknown reminder policy fields: ' . implode(', ', $unknown));
		}
		if ($input === []) {
			throw new ValidationException('At least one reminder policy field is required');
		}

		try {
			$policy = $this->mapper->findForWorkspace($context->workspace()->getId());
			if ($expectedRevision === 0 || $policy->getRevision() !== $expectedRevision) {
				throw new RevisionConflictException('The reminder policy has changed since it was last read');
			}
			$calendarLeadDays = array_key_exists('calendarLeadDays', $input)
				? $this->boundedInteger($input['calendarLeadDays'], 'calendarLeadDays', 0, 3650)
				: $policy->getCalendarLeadDays();
			$meterLeadPercent = array_key_exists('meterLeadPercent', $input)
				? $this->boundedInteger($input['meterLeadPercent'], 'meterLeadPercent', 0, 100)
				: $policy->getMeterLeadPercent();
			$now = $this->timeFactory->getTime();
			$policy->setCalendarLeadDays($calendarLeadDays);
			$policy->setMeterLeadPercent($meterLeadPercent);
			$policy->setRevision($expectedRevision + 1);
			$policy->setUpdatedAt($now);
			if (!$this->mapper->updateWithExpectedRevision($policy, $expectedRevision)) {
				throw new RevisionConflictException('The reminder policy has changed since it was last read');
			}
			$this->journal->record(
				$context->workspace()->getId(),
				'reminder_policy',
				$context->workspace()->getUuid(),
				'upsert',
				$policy->getRevision(),
				$now,
				'reminder_policy.updated',
			);
			return $this->toApi($policy, 'workspace');
		} catch (DoesNotExistException) {
			if ($expectedRevision !== 0) {
				throw new RevisionConflictException('The reminder policy has changed since it was last read');
			}
			$calendarLeadDays = array_key_exists('calendarLeadDays', $input)
				? $this->boundedInteger($input['calendarLeadDays'], 'calendarLeadDays', 0, 3650)
				: self::DEFAULT_CALENDAR_LEAD_DAYS;
			$meterLeadPercent = array_key_exists('meterLeadPercent', $input)
				? $this->boundedInteger($input['meterLeadPercent'], 'meterLeadPercent', 0, 100)
				: self::DEFAULT_METER_LEAD_PERCENT;
			$now = $this->timeFactory->getTime();
			$policy = new ReminderPolicy();
			$policy->setWorkspaceId($context->workspace()->getId());
			$policy->setCalendarLeadDays($calendarLeadDays);
			$policy->setMeterLeadPercent($meterLeadPercent);
			$policy->setRevision(1);
			$policy->setCreatedAt($now);
			$policy->setUpdatedAt($now);
			try {
				/** @var ReminderPolicy $inserted */
				$inserted = $this->mapper->insert($policy);
			} catch (DatabaseException $exception) {
				if ($exception->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw new RevisionConflictException('The reminder policy has changed since it was last read', 0, $exception);
				}
				throw $exception;
			}
			$this->journal->record(
				$context->workspace()->getId(),
				'reminder_policy',
				$context->workspace()->getUuid(),
				'upsert',
				1,
				$now,
				'reminder_policy.created',
			);
			return $this->toApi($inserted, 'workspace');
		}
	}

	private function boundedInteger(mixed $value, string $field, int $min, int $max): int {
		if (!is_int($value) || $value < $min || $value > $max) {
			throw new ValidationException("{$field} must be an integer between {$min} and {$max}");
		}
		return $value;
	}

	/** @return array{calendarLeadDays:int,meterLeadPercent:int,revision:int,source:string} */
	private function toApi(ReminderPolicy $policy, string $source): array {
		return [
			'calendarLeadDays' => $policy->getCalendarLeadDays(),
			'meterLeadPercent' => $policy->getMeterLeadPercent(),
			'revision' => $policy->getRevision(),
			'source' => $source,
		];
	}
}
