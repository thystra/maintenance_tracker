<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Model\WorkspaceContext;

final class MaintenanceForecastService {
	public function __construct(
		private MaintenanceStatusService $status,
		private ReminderPolicyService $policies,
		private ForecastPolicy $forecastPolicy,
	) {
	}

	/** @return array<string,mixed> */
	public function forAsset(WorkspaceContext $context, string $assetUuid, ?string $asOf = null): array {
		$status = $this->status->forAsset($context, $assetUuid, $asOf);
		$policy = $this->policies->get($context);
		$status['policy'] = $policy;
		$status['items'] = array_map(
			fn (array $item): array => [
				...$item,
				'forecast' => $this->forecastPolicy->classify($item, $policy),
			],
			$status['items'],
		);
		return $status;
	}
}
