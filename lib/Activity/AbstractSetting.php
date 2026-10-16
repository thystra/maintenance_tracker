<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Activity;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * Activity stream settings grouped under Maintenance Tracker. Stream entries
 * are on by default; e-mail digests stay opt-in.
 */
abstract class AbstractSetting extends ActivitySettings {
	public function __construct(
		protected IL10N $l,
	) {
	}

	public function getGroupIdentifier(): string {
		return Application::APP_ID;
	}

	public function getGroupName(): string {
		return $this->l->t('Maintenance Tracker');
	}

	public function canChangeMail(): bool {
		return true;
	}

	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
