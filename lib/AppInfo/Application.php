<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\AppInfo;

use OCA\MaintenanceTracker\Capability;
use OCA\MaintenanceTracker\Listener\UserLifecycleListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\User\Events\UserIdAssignedEvent;
use OCP\User\Events\UserIdUnassignedEvent;

final class Application extends App implements IBootstrap {
	public const APP_ID = 'maintenance_tracker';
	public const APP_VERSION = '0.1.4';
	public const API_VERSION = '0.1';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capability::class);
		$context->registerEventListener(
			UserDeletedEvent::class,
			UserLifecycleListener::class,
		);
		$context->registerEventListener(
			UserCreatedEvent::class,
			UserLifecycleListener::class,
		);
		$context->registerEventListener(
			UserIdUnassignedEvent::class,
			UserLifecycleListener::class,
		);
		$context->registerEventListener(
			UserIdAssignedEvent::class,
			UserLifecycleListener::class,
		);
	}

	public function boot(IBootContext $context): void {
		// Keep the request bootstrap empty and inexpensive.
	}
}
