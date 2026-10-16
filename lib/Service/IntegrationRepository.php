<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCP\DB\Exception as DatabaseException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Delivery bookkeeping for reminders and calendar projection. Rows record
 * only what was delivered (never due dates as truth or due states), so
 * repeated background runs stay idempotent.
 */
final class IntegrationRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** Active, owned workspaces in id order. @return list<array{id:int,uuid:string}> */
	public function workspacesAfter(int $afterId, int $limit): array {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'uuid')->from('maint_spaces')
			->where($q->expr()->gt('id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'))
			->andWhere($q->expr()->isNull('orphaned_at'))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		$result = $q->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['id' => (int)$row['id'], 'uuid' => (string)$row['uuid']];
		}
		$result->closeCursor();
		return $rows;
	}

	public function wasNotified(int $workspaceId, string $uid, string $definitionUuid, string $baselineUuid, string $level): bool {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('maint_notify_log')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('user_uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('definition_uuid', $q->createNamedParameter($definitionUuid)))
			->andWhere($q->expr()->eq('baseline_uuid', $q->createNamedParameter($baselineUuid)))
			->andWhere($q->expr()->eq('level', $q->createNamedParameter($level)))
			->setMaxResults(1);
		$result = $q->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		return $found;
	}

	/** Returns false when another runner already recorded the delivery. */
	public function recordNotified(int $workspaceId, string $uid, string $definitionUuid, string $baselineUuid, string $level, int $now): bool {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_notify_log')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'user_uid' => $q->createNamedParameter($uid),
			'definition_uuid' => $q->createNamedParameter($definitionUuid),
			'baseline_uuid' => $q->createNamedParameter($baselineUuid),
			'level' => $q->createNamedParameter($level),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		]);
		try {
			$q->executeStatement();
			return true;
		} catch (DatabaseException $exception) {
			if ($exception->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $exception;
		}
	}

	/** Definitions with an uncleared reminder in this workspace. @return list<string> */
	public function openReminderDefinitions(int $workspaceId): array {
		$q = $this->db->getQueryBuilder();
		$q->selectDistinct('definition_uuid')->from('maint_notify_log')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('cleared_at'));
		$result = $q->executeQuery();
		$uuids = [];
		while (($value = $result->fetchOne()) !== false) {
			$uuids[] = (string)$value;
		}
		$result->closeCursor();
		return $uuids;
	}

	public function clearReminders(int $workspaceId, string $definitionUuid, int $now, ?string $uid = null): void {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_notify_log')
			->set('cleared_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('definition_uuid', $q->createNamedParameter($definitionUuid)))
			->andWhere($q->expr()->isNull('cleared_at'));
		if ($uid !== null) {
			$q->andWhere($q->expr()->eq('user_uid', $q->createNamedParameter($uid)));
		}
		$q->executeStatement();
	}

	public function hasCalendarEvent(int $workspaceId, string $uid, string $calendarUri, string $definitionUuid, string $baselineUuid): bool {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('maint_cal_events')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('user_uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('calendar_uri', $q->createNamedParameter($calendarUri)))
			->andWhere($q->expr()->eq('definition_uuid', $q->createNamedParameter($definitionUuid)))
			->andWhere($q->expr()->eq('baseline_uuid', $q->createNamedParameter($baselineUuid)))
			->setMaxResults(1);
		$result = $q->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		return $found;
	}

	public function recordCalendarEvent(int $workspaceId, string $uid, string $calendarUri, string $definitionUuid, string $baselineUuid, string $eventUid, string $dueOn, int $now): void {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_cal_events')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'user_uid' => $q->createNamedParameter($uid),
			'calendar_uri' => $q->createNamedParameter($calendarUri),
			'definition_uuid' => $q->createNamedParameter($definitionUuid),
			'baseline_uuid' => $q->createNamedParameter($baselineUuid),
			'event_uid' => $q->createNamedParameter($eventUid),
			'due_on' => $q->createNamedParameter($dueOn),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		]);
		try {
			$q->executeStatement();
		} catch (DatabaseException $exception) {
			if ($exception->getReason() !== DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $exception;
			}
		}
	}
}
