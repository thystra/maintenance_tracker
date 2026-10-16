<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use DateTime;
use JsonException;
use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Db\AuditMapper;
use OCA\MaintenanceTracker\Db\WorkspaceMemberMapper;
use OCP\Activity\IManager as IActivityManager;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Projects the append-only audit trail into Nextcloud Activity and
 * notifications. It runs from a background job (and `occ
 * maintenance_tracker:integrations:run`), never inside an app transaction,
 * so a rolled-back write never produces an activity and core Activity or
 * notification storage never runs inside our transaction. A persisted cursor
 * makes delivery at-most-once per audit row; an exclusive lock keeps two
 * runners from publishing the same rows.
 */
final class IntegrationProjector {
	public const CURSOR_KEY = 'audit_projection_cursor';
	private const BATCH = 500;
	private const LOCK = 'maintenance_tracker/integration-projection';

	public function __construct(
		private AuditMapper $audit,
		private WorkspaceMemberMapper $members,
		private TransferRepository $transfers,
		private IntegrationEventMap $map,
		private IActivityManager $activityManager,
		private INotificationManager $notificationManager,
		private IAppConfig $appConfig,
		private ILockingProvider $locking,
		private LoggerInterface $logger,
	) {
	}

	/** @return int number of audit rows processed */
	public function run(int $maxBatches = 20): int {
		try {
			$this->locking->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return 0;
		}
		try {
			$processed = 0;
			for ($batch = 0; $batch < $maxBatches; ++$batch) {
				$cursor = $this->appConfig->getValueInt(Application::APP_ID, self::CURSOR_KEY, 0);
				$rows = $this->audit->findAfter($cursor, self::BATCH);
				if ($rows === []) {
					break;
				}
				foreach ($rows as $row) {
					$this->project($row);
					$cursor = (int)$row['id'];
					$this->appConfig->setValueInt(Application::APP_ID, self::CURSOR_KEY, $cursor);
					++$processed;
				}
				if (count($rows) < self::BATCH) {
					break;
				}
			}
			return $processed;
		} finally {
			$this->locking->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @param array<string,mixed> $row */
	private function project(array $row): void {
		$eventType = (string)$row['event_type'];
		$activity = $this->map->activity($eventType);
		$notification = $this->map->notification($eventType);
		if ($activity === null && $notification['send'] === null && !$notification['closesOffer']) {
			return;
		}
		$workspaceId = (int)$row['workspace_id'];
		$subjectId = (string)$row['subject_id'];
		$transfer = (string)$row['subject_type'] === 'ownership_transfer' ? $this->transfers->findByUuid($subjectId) : null;
		$raw = $this->rawDetails($row);
		$participants = $transfer !== null
			? [(string)$transfer['from_uid'], (string)$transfer['to_uid']]
			: [is_string($raw['fromUid'] ?? null) ? $raw['fromUid'] : '', is_string($raw['toUid'] ?? null) ? $raw['toUid'] : ''];

		try {
			if ($activity !== null) {
				$memberUids = array_map(static fn ($m): string => $m->getUserUid(), $this->members->findForWorkspace($workspaceId));
				$details = $this->map->safeDetails($raw);
				foreach ($this->map->recipients($eventType, $subjectId, $memberUids, $participants) as $uid) {
					$this->publish($row, $activity['type'], $uid, $details, $participants);
				}
			}
			if ($transfer !== null) {
				$this->notify($row, $notification, $transfer);
			}
		} catch (Throwable $exception) {
			// Best effort: a failing integration must not stall the projection.
			$this->logger->warning('Maintenance Tracker integration projection failed for one audit event', [
				'app' => Application::APP_ID,
				'eventType' => $eventType,
				'exception' => $exception::class,
			]);
		}
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,scalar|null> $details
	 * @param list<string> $participants
	 */
	private function publish(array $row, string $type, string $uid, array $details, array $participants): void {
		$actor = (string)$row['actor_uid'];
		$event = $this->activityManager->generateEvent();
		$event->setApp(Application::APP_ID)
			->setType($type)
			->setAffectedUser($uid)
			->setTimestamp((int)$row['created_at'])
			->setSubject((string)$row['event_type'], [
				'workspaceId' => (int)$row['workspace_id'],
				'subjectType' => (string)$row['subject_type'],
				'subjectId' => (string)$row['subject_id'],
				'actor' => $actor,
				'participants' => $participants,
				'details' => $details,
			])
			->setObject('maintenance_tracker_workspace', (int)$row['workspace_id'], (string)$row['subject_type']);
		if ($actor !== 'system' && $actor !== '') {
			$event->setAuthor($actor);
		}
		$this->activityManager->publish($event);
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array{send: ?array{subject: string, recipient: string}, closesOffer: bool} $effect
	 * @param array<string,mixed> $transfer
	 */
	private function notify(array $row, array $effect, array $transfer): void {
		$uuid = (string)$transfer['uuid'];
		if ($effect['closesOffer']) {
			$pending = $this->notificationManager->createNotification();
			$pending->setApp(Application::APP_ID)->setObject('ownership_transfer', $uuid);
			$this->notificationManager->markProcessed($pending);
		}
		if ($effect['send'] === null) {
			return;
		}
		$recipient = $effect['send']['recipient'] === 'to' ? (string)$transfer['to_uid'] : (string)$transfer['from_uid'];
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($recipient)
			->setDateTime(new DateTime('@' . (int)$row['created_at']))
			->setObject($effect['send']['subject'] === 'transfer_offered' ? 'ownership_transfer' : 'ownership_transfer_result', $uuid)
			->setSubject($effect['send']['subject'], [
				'workspaceId' => (int)$transfer['workspace_id'],
				'fromUid' => (string)$transfer['from_uid'],
				'toUid' => (string)$transfer['to_uid'],
			]);
		$this->notificationManager->notify($notification);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function rawDetails(array $row): array {
		$json = $row['details_json'] ?? null;
		if (!is_string($json) || $json === '') {
			return [];
		}
		try {
			$decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return [];
		}
		return is_array($decoded) ? $decoded : [];
	}
}
