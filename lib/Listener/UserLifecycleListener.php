<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Listener;

use OCA\MaintenanceTracker\Service\UserLifecycleService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\User\Events\UserIdAssignedEvent;
use OCP\User\Events\UserIdUnassignedEvent;

/**
 * @implements IEventListener<Event>
 */
final class UserLifecycleListener implements IEventListener {
	public function __construct(
		private UserLifecycleService $userLifecycleService,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$this->userLifecycleService->purgeUser($event->getUid());

			return;
		}

		if ($event instanceof UserIdUnassignedEvent) {
			$this->userLifecycleService->purgeUser($event->getUserId());

			return;
		}

		if ($event instanceof UserCreatedEvent) {
			$this->userLifecycleService->activateUser($event->getUid());

			return;
		}

		if ($event instanceof UserIdAssignedEvent) {
			$this->userLifecycleService->activateUser($event->getUserId());
		}
	}
}
