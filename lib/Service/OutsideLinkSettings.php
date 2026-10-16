<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCP\IAppConfig;

/**
 * Instance-wide administrator switch for outside links. When disabled, no new
 * link is stored (manual, profile, or fitment offers) and existing links are
 * hidden from every API response. Stored rows are kept so re-enabling restores
 * them without data loss.
 */
final class OutsideLinkSettings {
	public const KEY = 'outside_links_enabled';
	public const DISABLED_MESSAGE = 'Outside links are disabled by the Nextcloud administrator';

	public function __construct(private IAppConfig $appConfig) {
	}

	public function enabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY, true);
	}

	public function setEnabled(bool $enabled): void {
		$this->appConfig->setValueBool(Application::APP_ID, self::KEY, $enabled);
	}

	public function assertEnabled(): void {
		if (!$this->enabled()) {
			throw new ValidationException(self::DISABLED_MESSAGE);
		}
	}
}
