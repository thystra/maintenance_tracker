<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use DateTime;
use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Db\AssetMapper;
use OCA\MaintenanceTracker\Db\WorkspaceMapper;
use OCA\MaintenanceTracker\Db\WorkspaceMemberMapper;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICreateFromString;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background delivery of due/overdue reminders and calendar projection.
 *
 * Attention levels come from MaintenanceForecastService (the v0.1.8 policy
 * layer over the derived v0.1.7 status); nothing here recomputes due truth
 * or persists due state. Each member is notified once per (task, completion
 * baseline, level); a new completion or a task leaving the attention window
 * withdraws the notification. Calendar events are written once per (task,
 * baseline) into the member's selected calendar. All Notifications and
 * CalDAV calls run outside any app database transaction.
 */
final class ReminderService {
	private const WORKSPACE_PAGE = 100;
	private const ASSET_PAGE = 200;

	/** @var array<string, (ICalendar&ICreateFromString)|false> */
	private array $calendarCache = [];

	public function __construct(
		private IntegrationRepository $repository,
		private IntegrationSettingsService $settings,
		private ReminderDeliveryPolicy $policy,
		private CalendarEventBuilder $events,
		private MaintenanceForecastService $forecasts,
		private WorkspaceMapper $workspaces,
		private WorkspaceMemberMapper $members,
		private AssetMapper $assets,
		private INotificationManager $notificationManager,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{workspaces: int, notifications: int, withdrawn: int, calendarEvents: int} */
	public function run(): array {
		$this->calendarCache = [];
		$stats = ['workspaces' => 0, 'notifications' => 0, 'withdrawn' => 0, 'calendarEvents' => 0];
		$afterId = 0;
		do {
			$page = $this->repository->workspacesAfter($afterId, self::WORKSPACE_PAGE);
			foreach ($page as $row) {
				$afterId = $row['id'];
				try {
					$this->runWorkspace($row['id'], $stats);
					++$stats['workspaces'];
				} catch (Throwable $exception) {
					$this->logger->warning('Maintenance Tracker reminder run failed for one workspace', [
						'app' => Application::APP_ID,
						'exception' => $exception::class,
					]);
				}
			}
		} while (count($page) === self::WORKSPACE_PAGE);
		return $stats;
	}

	/** @param array{workspaces: int, notifications: int, withdrawn: int, calendarEvents: int} $stats */
	private function runWorkspace(int $workspaceId, array &$stats): void {
		try {
			$workspace = $this->workspaces->findById($workspaceId);
		} catch (DoesNotExistException) {
			return;
		}
		$memberUids = array_map(static fn ($m): string => $m->getUserUid(), $this->members->findForWorkspace($workspaceId));
		if ($memberUids === []) {
			return;
		}
		$reminderUids = array_values(array_filter($memberUids, fn (string $uid): bool => $this->settings->remindersEnabled($uid)));
		$calendarUsers = [];
		foreach ($memberUids as $uid) {
			$uri = $this->settings->calendarUri($uid);
			if ($uri !== null) {
				$calendarUsers[$uid] = $uri;
			}
		}

		// System read: a least-privilege viewer context over a workspace that
		// the job enumerated itself; nothing is written through it.
		$context = new WorkspaceContext($workspace, 'viewer');
		$now = $this->timeFactory->getTime();
		$today = gmdate('Y-m-d', $now);
		$attention = [];
		$afterAssetId = 0;
		do {
			$assets = $this->assets->findPageForWorkspace($workspaceId, $afterAssetId, self::ASSET_PAGE);
			foreach ($assets as $asset) {
				$afterAssetId = $asset->getId();
				try {
					$forecast = $this->forecasts->forAsset($context, $asset->getUuid());
				} catch (Throwable) {
					continue;
				}
				foreach ($forecast['items'] as $item) {
					$definitionUuid = $this->policy->definitionUuid($item);
					$baseline = $this->policy->baseline($item);
					if ($definitionUuid === null || $baseline === null) {
						continue;
					}
					$level = $this->policy->level($item);
					if ($level !== null) {
						$attention[$definitionUuid] = true;
						foreach ($reminderUids as $uid) {
							if ($this->remind($workspaceId, $uid, $asset->getUuid(), $definitionUuid, $baseline, $level, $now)) {
								++$stats['notifications'];
							}
						}
					}
					$dueOn = $this->policy->calendarDueOn($item);
					if ($dueOn === null || !$this->policy->withinCalendarWindow($dueOn, $today)) {
						continue;
					}
					foreach ($calendarUsers as $uid => $calendarUri) {
						if ($this->project($workspace->getId(), $workspace->getUuid(), $uid, $calendarUri, $definitionUuid, $baseline, $dueOn, $now)) {
							++$stats['calendarEvents'];
						}
					}
				}
			}
		} while (count($assets) === self::ASSET_PAGE);

		foreach ($this->repository->openReminderDefinitions($workspaceId) as $definitionUuid) {
			if (!isset($attention[$definitionUuid])) {
				$this->withdraw($definitionUuid, null);
				$this->repository->clearReminders($workspaceId, $definitionUuid, $now);
				++$stats['withdrawn'];
			}
		}
	}

	private function remind(int $workspaceId, string $uid, string $assetUuid, string $definitionUuid, string $baseline, string $level, int $now): bool {
		if ($this->repository->wasNotified($workspaceId, $uid, $definitionUuid, $baseline, $level)
			|| !$this->repository->recordNotified($workspaceId, $uid, $definitionUuid, $baseline, $level, $now)) {
			return false;
		}
		// Replace any earlier level (or baseline) for this task.
		$this->withdraw($definitionUuid, $uid);
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($uid)
			->setDateTime(new DateTime('@' . $now))
			->setObject('work_definition', $definitionUuid)
			->setSubject($this->policy->notificationSubject($level), [
				'workspaceId' => $workspaceId,
				'assetUuid' => $assetUuid,
				'baselineUuid' => $baseline,
			]);
		$this->notificationManager->notify($notification);
		return true;
	}

	private function withdraw(string $definitionUuid, ?string $uid): void {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)->setObject('work_definition', $definitionUuid);
		if ($uid !== null) {
			$notification->setUser($uid);
		}
		$this->notificationManager->markProcessed($notification);
	}

	private function project(int $workspaceId, string $workspaceUuid, string $uid, string $calendarUri, string $definitionUuid, string $baseline, string $dueOn, int $now): bool {
		if ($this->repository->hasCalendarEvent($workspaceId, $uid, $calendarUri, $definitionUuid, $baseline)) {
			return false;
		}
		$calendar = $this->calendar($uid, $calendarUri);
		if ($calendar === null) {
			return false;
		}
		$eventUid = $this->events->uid($workspaceUuid, $uid, $calendarUri, $definitionUuid, $baseline);
		try {
			if ($calendar->search('', [], ['uid' => $eventUid], 1) === []) {
				$calendar->createFromString(
					$this->events->objectName($eventUid),
					$this->events->build($eventUid, $dueOn, 'Maintenance due', $this->urlGenerator->linkToRouteAbsolute('maintenance_tracker.page.index'), $now),
				);
			}
		} catch (Throwable $exception) {
			$this->logger->warning('Maintenance Tracker could not write a projected calendar event', [
				'app' => Application::APP_ID,
				'exception' => $exception::class,
			]);
			return false;
		}
		$this->repository->recordCalendarEvent($workspaceId, $uid, $calendarUri, $definitionUuid, $baseline, $eventUid, $dueOn, $now);
		return true;
	}

	/** @return (ICalendar&ICreateFromString)|null */
	private function calendar(string $uid, string $uri): ?ICalendar {
		$key = $uid . "\n" . $uri;
		if (!array_key_exists($key, $this->calendarCache)) {
			$this->calendarCache[$key] = $this->settings->writableCalendar($uid, $uri) ?? false;
		}
		return $this->calendarCache[$key] === false ? null : $this->calendarCache[$key];
	}
}
