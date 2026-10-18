<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\AssetMapper;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;

final class MaintenanceForecastService {
	public function __construct(
		private MaintenanceStatusService $status,
		private ReminderPolicyService $policies,
		private ForecastPolicy $forecastPolicy,
		private AssetMapper $assets,
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

	/** Upper bound of items scanned by the workspace summary. */
	public const SUMMARY_MAX_ASSETS = 500;
	private const SUMMARY_PAGE = 100;
	private const SEVERITY = ['overdue' => 0, 'due' => 1, 'due_soon' => 2, 'blocked' => 3, 'setup_required' => 4, 'not_due' => 5];

	/**
	 * Workspace-wide forecast for the dashboard: one row per scheduled task of
	 * every active item, most urgent first. Inactive and unscheduled tasks are
	 * left out. Scans at most SUMMARY_MAX_ASSETS items.
	 *
	 * @return array<string,mixed>
	 */
	public function summary(WorkspaceContext $context, ?string $asOf = null): array {
		$workspaceId = $context->workspace()->getId();
		$items = [];
		$scanned = 0;
		$truncated = false;
		$after = 0;
		$resolvedAsOf = null;
		do {
			$page = $this->assets->findPageForWorkspace($workspaceId, $after, self::SUMMARY_PAGE);
			foreach ($page as $asset) {
				$after = $asset->getId();
				if ($scanned >= self::SUMMARY_MAX_ASSETS) {
					$truncated = true;
					break 2;
				}
				++$scanned;
				try {
					$forecast = $this->forAsset($context, $asset->getUuid(), $asOf);
				} catch (NotFoundException) {
					continue;
				}
				$resolvedAsOf ??= $forecast['asOf'] ?? null;
				foreach ($forecast['items'] as $item) {
					$state = (string)($item['forecast']['state'] ?? '');
					if (!isset(self::SEVERITY[$state])) {
						continue;
					}
					$trigger = null;
					foreach ($item['rules'] ?? [] as $rule) {
						if ($rule['position'] === ($item['triggerRulePosition'] ?? null)) {
							$trigger = $rule;
						}
					}
					$trigger ??= $item['rules'][0] ?? null;
					$items[] = [
						'asset' => ['uuid' => $asset->getUuid(), 'name' => $asset->getName(), 'category' => $asset->getCategoryKey()],
						'definition' => ['uuid' => $item['definition']['uuid'], 'title' => $item['definition']['title'], 'kind' => $item['definition']['kind']],
						'state' => $state,
						'reason' => $item['forecast']['reason'] ?? null,
						'lastPerformedAt' => $item['lastPerformedAt'] ?? null,
						'dueOn' => $trigger['dueOn'] ?? null,
						'remainingDays' => $trigger['remainingDays'] ?? null,
						'meter' => isset($trigger['meter']) ? [
							'name' => $trigger['meter']['name'],
							'displayUnit' => $trigger['meter']['displayUnit'],
							'canonicalUnit' => $trigger['meter']['canonicalUnit'],
							'remainingCanonicalValue' => $trigger['remainingCanonicalValue'] ?? null,
						] : null,
					];
				}
			}
		} while (count($page) === self::SUMMARY_PAGE);

		usort($items, static fn (array $a, array $b): int => [self::SEVERITY[$a['state']], $a['dueOn'] ?? '9999-12-31', $a['asset']['name'], $a['definition']['title']]
			<=> [self::SEVERITY[$b['state']], $b['dueOn'] ?? '9999-12-31', $b['asset']['name'], $b['definition']['title']]);

		return [
			'workspace' => $context->workspace()->getUuid(),
			'asOf' => $resolvedAsOf,
			'scannedAssets' => $scanned,
			'truncated' => $truncated,
			'policy' => $this->policies->get($context),
			'items' => $items,
		];
	}
}
