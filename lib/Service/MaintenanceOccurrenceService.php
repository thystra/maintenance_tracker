<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\MaintenanceOccurrence;
use OCA\MaintenanceTracker\Db\MaintenanceOccurrenceMapper;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;

final class MaintenanceOccurrenceService {
	public function __construct(
		private AssetService $assets,
		private WorkDefinitionService $definitions,
		private MaintenanceOccurrenceMapper $mapper,
		private MaintenanceForecastService $forecast,
		private UuidGenerator $uuidGenerator,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return array<string,mixed> */
	public function list(WorkspaceContext $context, string $assetUuid, bool $includeClosed = false, ?string $asOf = null): array {
		$asset = $this->assets->find($context, $assetUuid);
		$forecast = $this->forecast->forAsset($context, $assetUuid, $asOf);
		$byDefinition = [];
		foreach ($forecast['items'] as $item) {
			$uuid = $item['definition']['uuid'] ?? null;
			if (is_string($uuid)) {
				$byDefinition[$uuid] = $item;
			}
		}
		$items = array_map(
			fn (MaintenanceOccurrence $occurrence): array => $this->toApi(
				$occurrence,
				$byDefinition[$occurrence->getDefinitionUuid()] ?? null,
			),
			$this->mapper->findForAsset($context->workspace()->getId(), $asset->getId(), $includeClosed),
		);
		return [
			'workspace' => $context->workspace()->getUuid(),
			'assetUuid' => $asset->getUuid(),
			'asOf' => $forecast['asOf'],
			'policy' => $forecast['policy'],
			'items' => $items,
		];
	}

	/**
	 * Reconcile the materialized queue against the current derived forecast.
	 * No due date, threshold, or due-state value is copied into the occurrence row.
	 *
	 * @return array<string,mixed>
	 */
	public function reconcile(WorkspaceContext $context, string $assetUuid, ?string $asOf = null): array {
		$asset = $this->assets->find($context, $assetUuid);
		$forecast = $this->forecast->forAsset($context, $assetUuid, $asOf);
		$byDefinition = [];
		foreach ($forecast['items'] as $item) {
			$uuid = $item['definition']['uuid'] ?? null;
			if (is_string($uuid)) {
				$byDefinition[$uuid] = $item;
			}
		}

		$open = $this->mapper->findForAsset($context->workspace()->getId(), $asset->getId(), false);
		$openByDefinition = [];
		foreach ($open as $occurrence) {
			$current = $byDefinition[$occurrence->getDefinitionUuid()] ?? null;
			$reason = $this->closeReason($occurrence, $current);
			if ($reason !== null) {
				$this->close($occurrence, $reason);
				continue;
			}
			$openByDefinition[$occurrence->getDefinitionUuid()] = $occurrence;
		}

		foreach ($byDefinition as $definitionUuid => $item) {
			$materialize = (bool)($item['forecast']['materialize'] ?? false);
			if (!$materialize || isset($openByDefinition[$definitionUuid])) {
				continue;
			}
			$definition = $this->definitions->find($context, $definitionUuid);
			$now = $this->timeFactory->getTime();
			$occurrence = new MaintenanceOccurrence();
			$occurrence->setWorkspaceId($context->workspace()->getId());
			$occurrence->setAssetId($asset->getId());
			$occurrence->setDefinitionId($definition->getId());
			$occurrence->setDefinitionUuid($definition->getUuid());
			$occurrence->setUuid($this->uuidGenerator->generate());
			$occurrence->setBaselineActivityUuid(is_string($item['lastActivityUuid'] ?? null) ? $item['lastActivityUuid'] : null);
			$occurrence->setOpenMarker('open');
			$occurrence->setOpenedAt($now);
			$occurrence->setClosedAt(null);
			$occurrence->setClosedReason(null);
			$occurrence->setRevision(1);
			$occurrence->setCreatedAt($now);
			$occurrence->setUpdatedAt($now);
			try {
				/** @var MaintenanceOccurrence $inserted */
				$inserted = $this->mapper->insert($occurrence);
				$openByDefinition[$definitionUuid] = $inserted;
			} catch (DatabaseException $exception) {
				if ($exception->getReason() !== DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $exception;
				}
				throw new RevisionConflictException('An open occurrence was materialized concurrently', 0, $exception);
			}
		}

		return $this->list($context, $assetUuid, false, $asOf);
	}

	/** @param array<string,mixed>|null $current */
	private function closeReason(MaintenanceOccurrence $occurrence, ?array $current): ?string {
		if ($current === null) {
			return 'definition_unavailable';
		}
		$currentBaseline = is_string($current['lastActivityUuid'] ?? null) ? $current['lastActivityUuid'] : null;
		if ($currentBaseline !== null && $currentBaseline !== $occurrence->getBaselineActivityUuid()) {
			return 'completed';
		}
		if (!(bool)($current['forecast']['materialize'] ?? false)) {
			return 'not_actionable';
		}
		return null;
	}

	private function close(MaintenanceOccurrence $occurrence, string $reason): void {
		$expected = $occurrence->getRevision();
		$now = $this->timeFactory->getTime();
		$occurrence->setOpenMarker(null);
		$occurrence->setClosedAt($now);
		$occurrence->setClosedReason($reason);
		$occurrence->setRevision($expected + 1);
		$occurrence->setUpdatedAt($now);
		if (!$this->mapper->updateWithExpectedRevision($occurrence, $expected)) {
			throw new RevisionConflictException('The maintenance occurrence changed during reconciliation');
		}
	}

	/** @param array<string,mixed>|null $current @return array<string,mixed> */
	private function toApi(MaintenanceOccurrence $occurrence, ?array $current): array {
		return [
			'uuid' => $occurrence->getUuid(),
			'definitionUuid' => $occurrence->getDefinitionUuid(),
			'baselineActivityUuid' => $occurrence->getBaselineActivityUuid(),
			'open' => $occurrence->getOpenMarker() === 'open',
			'openedAt' => $this->formatTimestamp($occurrence->getOpenedAt()),
			'closedAt' => $occurrence->getClosedAt() === null ? null : $this->formatTimestamp($occurrence->getClosedAt()),
			'closedReason' => $occurrence->getClosedReason(),
			'revision' => $occurrence->getRevision(),
			'current' => $current,
		];
	}

	private function formatTimestamp(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
	}
}
