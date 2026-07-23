<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Model;

use OCA\MaintenanceTracker\Db\Workspace;

final readonly class WorkspaceContext {
	public function __construct(
		private Workspace $workspace,
		private string $role,
	) {
	}

	public function workspace(): Workspace {
		return $this->workspace;
	}

	public function role(): string {
		return $this->role;
	}
}
