<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\AccessDeniedException;
use OCP\IUserSession;

final class CurrentUser {
	public function __construct(
		private IUserSession $userSession,
	) {
	}

	public function uid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new AccessDeniedException('An authenticated Nextcloud user is required');
		}

		return $user->getUID();
	}
}
