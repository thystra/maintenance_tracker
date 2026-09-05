<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCP\Capabilities\ICapability;

final class Capability implements ICapability {
	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getCapabilities(): array {
		return [
			Application::APP_ID => [
				'appVersion' => Application::APP_VERSION,
				'apiVersion' => Application::API_VERSION,
				'apiStability' => 'experimental',
				'features' => [
					'private-workspace',
					'assets',
					'custom-categories',
					'asset-classes',
					'component-instances',
					'structured-specifications',
					'typed-asset-relationships',
					'effective-dated-assignments',
					'workspace-write-serialization',
					'capability-authorization',
					'workspace-membership',
					'append-only-audit',
					'meters-readings',
					'client-generated-uuid',
					'optimistic-revisions',
					'cursor-pagination',
					'change-journal-foundation',
				],
			],
		];
	}
}
