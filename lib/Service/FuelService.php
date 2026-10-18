<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use DateTimeImmutable;
use OCA\MaintenanceTracker\Db\Asset;
use OCA\MaintenanceTracker\Db\Meter;
use OCA\MaintenanceTracker\Db\MeterMapper;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Fuel log: per-item fuel settings, operating regimes and fuel tickets.
 *
 * A ticket's price lives in the cost ledger (maint_costs, category "fuel");
 * the ticket only links to it. The odometer creates a meter reading on the
 * item's odometer (source type "fuel") or links an existing reading, so the
 * fuel log feeds distance-based maintenance schedules. No Files calls happen
 * here: receipts are evidence linked to the ticket (target type "fuel") and
 * go through EvidenceService, outside these transactions.
 */
final class FuelService {
	public const MAX_REGIMES = 50;
	public const TICKET_FIELDS = ['uuid', 'filledAt', 'odometer', 'odometerUnit', 'readingUuid', 'meterUuid', 'volume', 'volumeUnit', 'fullFill', 'missedPrevious', 'fuelType', 'station', 'regimeUuid', 'totalCost', 'currency', 'notes'];
	private const TICKET_UPDATE_FIELDS = ['filledAt', 'odometer', 'odometerUnit', 'volume', 'volumeUnit', 'fullFill', 'missedPrevious', 'fuelType', 'station', 'regimeUuid', 'totalCost', 'currency', 'notes'];

	public function __construct(
		private FuelRepository $repository,
		private AssetService $assets,
		private MeterMapper $meters,
		private MeterService $meterService,
		private ReadingService $readings,
		private CostService $costs,
		private CostRepository $costRows,
		private MeterValueConverter $values,
		private UuidGenerator $uuids,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	// ---------------------------------------------------------------- settings

	/** @return array<string,mixed> */
	public function settings(WorkspaceContext $context, string $assetUuid): array {
		return $this->settingsFor($context, $this->assets->find($context, $assetUuid));
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function updateSettings(WorkspaceContext $context, string $assetUuid, int $expectedRevision, array $input): array {
		CatalogInput::known($input, ['fuelTab', 'distanceUnit', 'volumeUnit', 'economyUnit', 'fuelType'], 'fuel settings');
		$asset = $this->assets->find($context, $assetUuid);
		$workspaceId = $context->workspace()->getId();
		$current = $this->settingsFor($context, $asset);
		if ($expectedRevision !== $current['revision']) {
			throw new RevisionConflictException('The fuel settings revision does not match');
		}
		$values = [
			'fuel_tab' => array_key_exists('fuelTab', $input) ? self::fuelTab($input['fuelTab']) : $current['fuelTab'],
			'distance_unit' => array_key_exists('distanceUnit', $input) ? FuelEconomy::distanceUnit($input['distanceUnit']) : $current['distanceUnit'],
			'volume_unit' => array_key_exists('volumeUnit', $input) ? FuelEconomy::volumeUnit($input['volumeUnit']) : $current['volumeUnit'],
			'economy_unit' => array_key_exists('economyUnit', $input) ? FuelEconomy::economyUnit($input['economyUnit']) : $current['economyUnit'],
			'fuel_type' => array_key_exists('fuelType', $input) ? FuelEconomy::fuelType($input['fuelType']) : $current['fuelType'],
		];
		$now = $this->timeFactory->getTime();
		$row = $this->repository->findVehicle($workspaceId, $asset->getId());
		if ($row === null) {
			$this->repository->insertVehicle($values + ['workspace_id' => $workspaceId, 'asset_id' => $asset->getId(), 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
		} elseif (!$this->repository->updateVehicle($workspaceId, (int)$row['id'], $expectedRevision, $values + ['revision' => $expectedRevision + 1, 'updated_at' => $now])) {
			throw new RevisionConflictException('The fuel settings revision does not match');
		}
		$this->journal->record($workspaceId, 'fuel_settings', $asset->getUuid(), 'upsert', $expectedRevision + 1, $now, 'fuel_settings.updated', [
			'fuelTab' => $values['fuel_tab'],
			'distanceUnit' => $values['distance_unit'],
			'volumeUnit' => $values['volume_unit'],
			'economyUnit' => $values['economy_unit'],
		]);
		return $this->settingsFor($context, $asset);
	}

	// ---------------------------------------------------------------- regimes

	/** @return list<array<string,mixed>> */
	public function regimes(WorkspaceContext $context, string $assetUuid): array {
		$asset = $this->assets->find($context, $assetUuid);
		return array_map(fn (array $r): array => $this->regimeToApi($r, $asset->getUuid()), $this->repository->contexts($context->workspace()->getId(), $asset->getId()));
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function createRegime(WorkspaceContext $context, string $assetUuid, array $input): array {
		CatalogInput::known($input, ['uuid', 'name', 'isDefault', 'sort'], 'regime');
		$asset = $this->assets->find($context, $assetUuid);
		$workspaceId = $context->workspace()->getId();
		$uuid = array_key_exists('uuid', $input) ? CatalogInput::uuid($input['uuid'], 'uuid') : $this->uuids->generate();
		$name = CatalogInput::text($input['name'] ?? null, 'name', 120);
		$sort = ItemGroupTree::sort($input['sort'] ?? 0);
		$existing = $this->repository->findContext($workspaceId, $uuid, true);
		if ($existing !== null) {
			if ($existing['deleted_at'] === null && (int)$existing['asset_id'] === $asset->getId() && (string)$existing['name'] === $name) {
				return $this->regimeToApi($existing, $asset->getUuid());
			}
			throw new RevisionConflictException('The supplied regime UUID already exists with different data');
		}
		if ($this->repository->uuidExists('maint_contexts', $uuid)) {
			throw new RevisionConflictException('The supplied regime UUID already exists with different data');
		}
		$current = $this->repository->contexts($workspaceId, $asset->getId());
		if (count($current) >= self::MAX_REGIMES) {
			throw new ValidationException('An item can have at most ' . self::MAX_REGIMES . ' regimes');
		}
		$isDefault = $current === [] || (array_key_exists('isDefault', $input) && CatalogInput::bool($input['isDefault'], 'isDefault'));
		$now = $this->timeFactory->getTime();
		$this->repository->insertContext([
			'workspace_id' => $workspaceId, 'asset_id' => $asset->getId(), 'uuid' => $uuid, 'name' => $name,
			'is_default' => $isDefault, 'sort_order' => $sort, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
		]);
		$row = $this->repository->findContext($workspaceId, $uuid) ?? throw new NotFoundException('Regime not found');
		$this->journal->record($workspaceId, 'fuel_regime', $uuid, 'upsert', 1, $now);
		if ($isDefault) {
			$this->clearOtherDefaults($workspaceId, $asset->getId(), (int)$row['id'], $now);
		}
		return $this->regimeToApi($row, $asset->getUuid());
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function updateRegime(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		CatalogInput::known($input, ['name', 'isDefault', 'sort'], 'regime update');
		$workspaceId = $context->workspace()->getId();
		$row = $this->regimeRow($workspaceId, $uuid);
		self::assertRevision($row, $expectedRevision, 'regime');
		$changes = [];
		if (array_key_exists('name', $input)) {
			$changes['name'] = CatalogInput::text($input['name'], 'name', 120);
		}
		if (array_key_exists('sort', $input)) {
			$changes['sort_order'] = ItemGroupTree::sort($input['sort']);
		}
		$makeDefault = false;
		if (array_key_exists('isDefault', $input)) {
			$flag = CatalogInput::bool($input['isDefault'], 'isDefault');
			if (!$flag && self::bool($row['is_default'])) {
				throw new ValidationException('Choose another regime as the default instead');
			}
			$makeDefault = $flag && !self::bool($row['is_default']);
			if ($makeDefault) {
				$changes['is_default'] = true;
			}
		}
		$now = $this->timeFactory->getTime();
		$this->writeRegime($workspaceId, $row, $expectedRevision, $changes, 'upsert', $now);
		if ($makeDefault) {
			$this->clearOtherDefaults($workspaceId, (int)$row['asset_id'], (int)$row['id'], $now);
		}
		return $this->regimeToApi($this->regimeRow($workspaceId, $uuid), $this->assetUuid($context, (int)$row['asset_id']));
	}

	/**
	 * Archive a regime. Tickets keep pointing at it (their history stays
	 * true); a deleted default hands the default to the first remaining regime.
	 *
	 * @return array<string,mixed>
	 */
	public function archiveRegime(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		$workspaceId = $context->workspace()->getId();
		$row = $this->regimeRow($workspaceId, $uuid);
		self::assertRevision($row, $expectedRevision, 'regime');
		$now = $this->timeFactory->getTime();
		$this->writeRegime($workspaceId, $row, $expectedRevision, ['deleted_at' => $now, 'is_default' => false], 'delete', $now);
		if (self::bool($row['is_default'])) {
			$next = $this->repository->contexts($workspaceId, (int)$row['asset_id'])[0] ?? null;
			if ($next !== null) {
				$this->writeRegime($workspaceId, $next, (int)$next['revision'], ['is_default' => true], 'upsert', $now);
			}
		}
		return ['uuid' => (string)$row['uuid'], 'tickets' => $this->repository->countTicketsForContext($workspaceId, (int)$row['id'])];
	}

	// ---------------------------------------------------------------- tickets

	/**
	 * Tickets of an item, newest first, each closing fill annotated with the
	 * economy of the segment it closes.
	 *
	 * @return array<string,mixed>
	 */
	public function list(WorkspaceContext $context, string $assetUuid): array {
		$asset = $this->assets->find($context, $assetUuid);
		$settings = $this->settingsFor($context, $asset);
		$rows = $this->repository->tickets($context->workspace()->getId(), $asset->getId());
		$computed = FuelEconomy::segments(array_map(self::fill(...), $rows));
		$byClosing = [];
		foreach ($computed['segments'] as $segment) {
			$byClosing[$segment['toUuid']] = $segment;
		}
		$items = [];
		foreach (array_reverse($rows) as $row) {
			$segment = $byClosing[(string)$row['uuid']] ?? null;
			$items[] = $this->ticketToApi($row) + ['segment' => $segment === null ? null : self::segmentToApi($segment, $settings)];
		}
		return [
			'assetUuid' => $asset->getUuid(),
			'items' => $items,
			'openFills' => $computed['openFills'],
			'settings' => $settings,
			'regimes' => array_map(fn (array $r): array => $this->regimeToApi($r, $asset->getUuid()), $this->repository->contexts($context->workspace()->getId(), $asset->getId())),
		];
	}

	/** @return array<string,mixed> */
	public function show(WorkspaceContext $context, string $uuid): array {
		return $this->ticketToApi($this->ticketRow($context->workspace()->getId(), $uuid, true));
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function create(WorkspaceContext $context, string $assetUuid, array $input): array {
		CatalogInput::known($input, self::TICKET_FIELDS, 'fuel ticket');
		foreach (['uuid', 'filledAt', 'volume'] as $field) {
			if (!array_key_exists($field, $input)) {
				throw new ValidationException("{$field} is required");
			}
		}
		if (!array_key_exists('odometer', $input) && !array_key_exists('readingUuid', $input)) {
			throw new ValidationException('odometer or readingUuid is required');
		}
		$asset = $this->assets->find($context, $assetUuid);
		$workspaceId = $context->workspace()->getId();
		$settings = $this->settingsFor($context, $asset);
		$uuid = CatalogInput::uuid($input['uuid'], 'uuid');
		$filledAt = self::timestamp($input['filledAt']);
		$volumeUnit = array_key_exists('volumeUnit', $input) ? FuelEconomy::volumeUnit($input['volumeUnit']) : $settings['volumeUnit'];
		$volume = FuelEconomy::volume($input['volume'], $volumeUnit);
		$row = [
			'workspace_id' => $workspaceId,
			'asset_id' => $asset->getId(),
			'uuid' => $uuid,
			'filled_at' => $filledAt,
			'volume_value' => $volume['value'],
			'volume_unit' => $volumeUnit,
			'volume_ul' => $volume['ul'],
			'full_fill' => array_key_exists('fullFill', $input) ? CatalogInput::bool($input['fullFill'], 'fullFill') : true,
			'missed_previous' => array_key_exists('missedPrevious', $input) ? CatalogInput::bool($input['missedPrevious'], 'missedPrevious') : false,
			'fuel_type' => array_key_exists('fuelType', $input) ? FuelEconomy::fuelType($input['fuelType']) : $settings['fuelType'],
			'station' => CatalogInput::optionalText($input['station'] ?? null, 'station', 255),
			'context_id' => array_key_exists('regimeUuid', $input) ? $this->regimeId($workspaceId, $asset->getId(), $input['regimeUuid']) : $this->defaultRegimeId($workspaceId, $asset->getId()),
			'notes' => CatalogInput::optionalText($input['notes'] ?? null, 'notes', 2000, true),
		];

		$existing = $this->repository->findTicket($workspaceId, $uuid, true);
		if ($existing !== null) {
			if ($existing['deleted_at'] === null && (int)$existing['revision'] === 1 && self::sameFacts($existing, $row)) {
				return $this->ticketToApi($existing);
			}
			throw new RevisionConflictException('The supplied fuel ticket UUID already exists with different data');
		}
		if ($this->repository->uuidExists('maint_fuel', $uuid)) {
			throw new RevisionConflictException('The supplied fuel ticket UUID already exists with different data');
		}

		$row += $this->odometer($context, $asset, $settings, $uuid, $filledAt, $input);
		$total = $input['totalCost'] ?? null;
		$row['cost_id'] = ($total === null || $total === '') ? null : $this->createCost($context, $asset, $row, $total, $input['currency'] ?? null);
		$now = $this->timeFactory->getTime();
		$this->repository->insertTicket($row + ['revision' => 1, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null]);
		$this->journal->record($workspaceId, 'fuel', $uuid, 'upsert', 1, $now);
		return $this->ticketToApi($this->ticketRow($workspaceId, $uuid));
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		CatalogInput::known($input, self::TICKET_UPDATE_FIELDS, 'fuel ticket update');
		$workspaceId = $context->workspace()->getId();
		$row = $this->ticketRow($workspaceId, $uuid);
		self::assertRevision($row, $expectedRevision, 'fuel ticket');
		$asset = $this->assets->findById($context, (int)$row['asset_id']);
		$changes = [];
		if (array_key_exists('filledAt', $input)) {
			$changes['filled_at'] = self::timestamp($input['filledAt']);
		}
		if (array_key_exists('volume', $input) || array_key_exists('volumeUnit', $input)) {
			$unit = array_key_exists('volumeUnit', $input) ? FuelEconomy::volumeUnit($input['volumeUnit']) : (string)$row['volume_unit'];
			$volume = FuelEconomy::volume($input['volume'] ?? (string)$row['volume_value'], $unit);
			$changes += ['volume_value' => $volume['value'], 'volume_unit' => $unit, 'volume_ul' => $volume['ul']];
		}
		foreach (['fullFill' => 'full_fill', 'missedPrevious' => 'missed_previous'] as $field => $column) {
			if (array_key_exists($field, $input)) {
				$changes[$column] = CatalogInput::bool($input[$field], $field);
			}
		}
		if (array_key_exists('fuelType', $input)) {
			$changes['fuel_type'] = FuelEconomy::fuelType($input['fuelType']);
		}
		if (array_key_exists('station', $input)) {
			$changes['station'] = CatalogInput::optionalText($input['station'], 'station', 255);
		}
		if (array_key_exists('notes', $input)) {
			$changes['notes'] = CatalogInput::optionalText($input['notes'], 'notes', 2000, true);
		}
		if (array_key_exists('regimeUuid', $input)) {
			$changes['context_id'] = $this->regimeId($workspaceId, $asset->getId(), $input['regimeUuid']);
		}
		$filledAt = $changes['filled_at'] ?? (int)$row['filled_at'];
		if (array_key_exists('odometer', $input) || array_key_exists('odometerUnit', $input) || array_key_exists('filledAt', $input)) {
			$changes += $this->correctOdometer($context, $asset, $row, $filledAt, $input);
		}
		$merged = array_merge($row, $changes);
		if (array_key_exists('totalCost', $input) || array_key_exists('currency', $input) || isset($changes['filled_at']) || isset($changes['volume_value'])) {
			$changes['cost_id'] = $this->syncCost($context, $asset, $row, $merged, $input);
		}
		$now = $this->timeFactory->getTime();
		$changes += ['revision' => $expectedRevision + 1, 'updated_at' => $now];
		if (!$this->repository->updateTicket($workspaceId, (int)$row['id'], $expectedRevision, $changes)) {
			throw new RevisionConflictException('The fuel ticket revision does not match');
		}
		$this->journal->record($workspaceId, 'fuel', (string)$row['uuid'], 'upsert', $expectedRevision + 1, $now);
		return $this->ticketToApi($this->ticketRow($workspaceId, $uuid));
	}

	/**
	 * Archive a ticket and its linked cost entry. The odometer reading stays:
	 * readings are append-only history and may feed schedules.
	 *
	 * @return array<string,mixed>
	 */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		$workspaceId = $context->workspace()->getId();
		$row = $this->ticketRow($workspaceId, $uuid);
		self::assertRevision($row, $expectedRevision, 'fuel ticket');
		if ($row['cost_uuid'] !== null && $row['cost_deleted_at'] === null) {
			$this->costs->archive($context, (string)$row['cost_uuid'], (int)$row['cost_revision']);
		}
		$now = $this->timeFactory->getTime();
		if (!$this->repository->updateTicket($workspaceId, (int)$row['id'], $expectedRevision, ['deleted_at' => $now, 'revision' => $expectedRevision + 1, 'updated_at' => $now])) {
			throw new RevisionConflictException('The fuel ticket revision does not match');
		}
		$this->journal->record($workspaceId, 'fuel', (string)$row['uuid'], 'delete', $expectedRevision + 1, $now);
		return $this->ticketToApi($this->ticketRow($workspaceId, $uuid, true));
	}

	// ---------------------------------------------------------------- statistics

	/** @return array<string,mixed> */
	public function stats(WorkspaceContext $context, string $assetUuid, string $period, ?string $from, ?string $to, ?string $unit): array {
		$asset = $this->assets->find($context, $assetUuid);
		$settings = $this->settingsFor($context, $asset);
		$economyUnit = $unit === null || $unit === '' ? $settings['economyUnit'] : FuelEconomy::economyUnit($unit);
		[$start, $end] = FuelEconomy::period($period, $from, $to, $this->timeFactory->getTime());
		$workspaceId = $context->workspace()->getId();
		$fills = array_map(self::fill(...), $this->repository->tickets($workspaceId, $asset->getId()));
		$computed = FuelEconomy::segments($fills);
		$names = [];
		foreach ($this->repository->contexts($workspaceId, $asset->getId()) as $regime) {
			$names[(string)$regime['uuid']] = (string)$regime['name'];
		}
		foreach ($this->repository->tickets($workspaceId, $asset->getId()) as $row) {
			if ($row['context_uuid'] !== null) {
				$names[(string)$row['context_uuid']] ??= (string)$row['context_name'];
			}
		}
		$stats = FuelEconomy::stats($computed['segments'], $fills, $start, $end, $economyUnit, $settings['distanceUnit'], $settings['volumeUnit'], $names);
		$points = [];
		foreach ($computed['segments'] as $segment) {
			if ($segment['valid'] && $segment['endAt'] >= $start && $segment['endAt'] <= $end) {
				$points[] = [
					'date' => gmdate('Y-m-d', $segment['endAt']),
					'economy' => FuelEconomy::economy($segment['distanceMm'], $segment['volumeUl'], $economyUnit),
					'regimeUuid' => $segment['regime'],
					'mixed' => $segment['mixed'],
				];
			}
		}
		return ['assetUuid' => $asset->getUuid(), 'period' => $period] + $stats + [
			'chart' => array_slice($points, -60),
			'invalidSegments' => count(array_filter($computed['segments'], static fn (array $s): bool => !$s['valid'])),
			'openFills' => $computed['openFills'],
		];
	}

	/**
	 * Recent fuel economy per item for the dashboard: items with fuel tickets,
	 * most recently filled first.
	 *
	 * @return array{items:list<array<string,mixed>>}
	 */
	public function summary(WorkspaceContext $context, int $limit = 5): array {
		$workspaceId = $context->workspace()->getId();
		$byAsset = [];
		foreach ($this->repository->tickets($workspaceId, null) as $row) {
			$byAsset[(int)$row['asset_id']][] = $row;
		}
		$now = $this->timeFactory->getTime();
		$items = [];
		foreach ($byAsset as $assetId => $rows) {
			$asset = $this->assets->findById($context, $assetId);
			$settings = $this->settingsFor($context, $asset);
			$fills = array_map(self::fill(...), $rows);
			$segments = FuelEconomy::segments($fills)['segments'];
			$valid = array_values(array_filter($segments, static fn (array $s): bool => $s['valid']));
			$last = $valid === [] ? null : $valid[count($valid) - 1];
			$recent = FuelEconomy::stats($segments, $fills, $now - 90 * 86400, $now, $settings['economyUnit'], $settings['distanceUnit'], $settings['volumeUnit'], []);
			$items[] = [
				'assetUuid' => $asset->getUuid(),
				'name' => $asset->getName(),
				'lastFilledAt' => CatalogInput::formatTimestamp((int)$rows[count($rows) - 1]['filled_at']),
				'economyUnit' => $settings['economyUnit'],
				'lastEconomy' => $last === null ? null : FuelEconomy::economy($last['distanceMm'], $last['volumeUl'], $settings['economyUnit']),
				'economy90d' => $recent['overall']['economy'],
				'segments90d' => $recent['overall']['segments'],
			];
		}
		usort($items, static fn (array $a, array $b): int => strcmp($b['lastFilledAt'], $a['lastFilledAt']));
		return ['items' => array_slice($items, 0, $limit)];
	}

	// ---------------------------------------------------------------- helpers

	/** @return array<string,mixed> */
	private function settingsFor(WorkspaceContext $context, Asset $asset): array {
		$row = $this->repository->findVehicle($context->workspace()->getId(), $asset->getId());
		$odometer = $this->odometerMeter($context->workspace()->getId(), $asset->getId());
		$metric = $odometer !== null && $odometer->getDisplayUnit() === 'km';
		$mode = $row === null ? 'auto' : (string)$row['fuel_tab'];
		$vehicle = $asset->getAssetClass() === 'vehicle' || $asset->getCategoryKey() === 'vehicle';
		$reason = match (true) {
			$mode === 'on' => 'enabled',
			$mode === 'off' => 'disabled',
			$vehicle => 'vehicle',
			$odometer !== null => 'odometer',
			default => null,
		};
		return [
			'assetUuid' => $asset->getUuid(),
			'fuelTab' => $mode,
			'shown' => $mode === 'on' || ($mode === 'auto' && $reason !== null),
			'reason' => $reason,
			'distanceUnit' => $row === null ? ($metric ? 'km' : 'mi') : (string)$row['distance_unit'],
			'volumeUnit' => $row === null ? ($metric ? 'l' : 'gal') : (string)$row['volume_unit'],
			'economyUnit' => $row === null ? ($metric ? 'l_100km' : 'mpg') : (string)$row['economy_unit'],
			'fuelType' => $row === null || $row['fuel_type'] === null ? null : (string)$row['fuel_type'],
			'odometerMeter' => $odometer === null ? null : ['uuid' => $odometer->getUuid(), 'name' => $odometer->getName(), 'displayUnit' => $odometer->getDisplayUnit()],
			'revision' => $row === null ? 0 : (int)$row['revision'],
		];
	}

	/** The item's odometer: the active distance meter keyed "odometer", else the first distance meter. */
	private function odometerMeter(int $workspaceId, int $assetId): ?Meter {
		$first = null;
		foreach ($this->meters->findForAsset($workspaceId, $assetId) as $meter) {
			if ($meter->getDimension() !== 'distance') {
				continue;
			}
			if ($meter->getMeterKey() === 'odometer') {
				return $meter;
			}
			$first ??= $meter;
		}
		return $first;
	}

	/**
	 * Resolve the odometer of a new ticket: link an existing reading, or
	 * record a new reading on the odometer meter, or (no meter) keep the value
	 * on the ticket only.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	private function odometer(WorkspaceContext $context, Asset $asset, array $settings, string $ticketUuid, int $filledAt, array $input): array {
		$workspaceId = $context->workspace()->getId();
		if (($input['readingUuid'] ?? null) !== null) {
			$reading = $this->readings->find($context, (string)CatalogInput::uuid($input['readingUuid'], 'readingUuid'));
			$meter = $this->meterService->findById($context, $reading->getMeterId());
			if ($meter->getAssetId() !== $asset->getId() || $meter->getDimension() !== 'distance') {
				throw new ValidationException('readingUuid must be a distance reading of the same item');
			}
			return [
				'odometer_value' => $reading->getOriginalValue(), 'odometer_unit' => $reading->getOriginalUnit(), 'odometer_mm' => $reading->getCanonicalValue(),
				'meter_id' => $meter->getId(), 'reading_id' => $reading->getId(),
			];
		}
		$unit = array_key_exists('odometerUnit', $input) ? FuelEconomy::distanceUnit($input['odometerUnit'], 'odometerUnit') : $settings['distanceUnit'];
		$meter = null;
		if (($input['meterUuid'] ?? null) !== null) {
			$meter = $this->meterService->find($context, (string)CatalogInput::uuid($input['meterUuid'], 'meterUuid'));
			if ($meter->getAssetId() !== $asset->getId() || $meter->getDimension() !== 'distance') {
				throw new ValidationException('meterUuid must be a distance meter of the same item');
			}
		} elseif (!array_key_exists('meterUuid', $input)) {
			$meter = $this->odometerMeter($workspaceId, $asset->getId());
		}
		$value = $this->values->toCanonical('distance', $input['odometer'] ?? null, $unit);
		if ($meter === null) {
			return ['odometer_value' => $value['originalValue'], 'odometer_unit' => $value['originalUnit'], 'odometer_mm' => $value['canonicalValue'], 'meter_id' => null, 'reading_id' => null];
		}
		$reading = $this->readings->create($context, $meter->getUuid(), [
			'uuid' => $this->uuids->generate(),
			'observedAt' => gmdate('Y-m-d\TH:i:s\Z', $filledAt),
			'value' => $value['originalValue'],
			'unit' => $value['originalUnit'],
			'source' => ['type' => 'fuel', 'reference' => $ticketUuid],
		]);
		return [
			'odometer_value' => $value['originalValue'], 'odometer_unit' => $value['originalUnit'], 'odometer_mm' => $value['canonicalValue'],
			'meter_id' => $meter->getId(), 'reading_id' => $this->readings->find($context, (string)$reading['uuid'])->getId(),
		];
	}

	/**
	 * Apply an odometer or time change. A reading this ticket created is
	 * corrected (readings are append-only); a linked reading recorded
	 * elsewhere is left alone and the ticket stops pointing at it.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	private function correctOdometer(WorkspaceContext $context, Asset $asset, array $row, int $filledAt, array $input): array {
		$unit = array_key_exists('odometerUnit', $input) ? FuelEconomy::distanceUnit($input['odometerUnit'], 'odometerUnit') : (string)$row['odometer_unit'];
		$value = $this->values->toCanonical('distance', $input['odometer'] ?? (string)$row['odometer_value'], $unit);
		$changes = ['odometer_value' => $value['originalValue'], 'odometer_unit' => $value['originalUnit'], 'odometer_mm' => $value['canonicalValue']];
		if ($row['reading_uuid'] === null) {
			return $changes;
		}
		$reading = $this->readings->find($context, (string)$row['reading_uuid']);
		$unchanged = $reading->getCanonicalValue() === $value['canonicalValue'] && $reading->getObservedAt() === $filledAt;
		if ($unchanged) {
			return $changes;
		}
		if ($reading->getSourceType() !== 'fuel' || $reading->getSourceRef() !== (string)$row['uuid']) {
			return $changes + ['reading_id' => null, 'meter_id' => null];
		}
		$corrected = $this->readings->correct($context, $reading->getUuid(), [
			'uuid' => $this->uuids->generate(),
			'observedAt' => gmdate('Y-m-d\TH:i:s\Z', $filledAt),
			'value' => $value['originalValue'],
			'unit' => $value['originalUnit'],
			'source' => ['type' => 'fuel', 'reference' => (string)$row['uuid']],
		]);
		return $changes + ['reading_id' => $this->readings->find($context, (string)$corrected['uuid'])->getId()];
	}

	/** @param array<string,mixed> $row */
	private function createCost(WorkspaceContext $context, Asset $asset, array $row, mixed $total, mixed $currency): int {
		$cost = $this->costs->create($context, $asset->getUuid(), [
			'uuid' => $this->uuids->generate(),
			'incurredOn' => gmdate('Y-m-d', (int)$row['filled_at']),
			'category' => 'fuel',
			'description' => self::costDescription($row),
			'amount' => self::amount($total),
			'currency' => $currency,
		]);
		$costRow = $this->costRows->findByUuid($context->workspace()->getId(), (string)$cost['uuid']) ?? throw new NotFoundException('Cost entry not found');
		return (int)$costRow['id'];
	}

	/**
	 * Keep the linked cost entry in step with the ticket.
	 *
	 * @param array<string,mixed> $row current ticket row (with cost join)
	 * @param array<string,mixed> $merged ticket values after this update
	 * @param array<string,mixed> $input
	 */
	private function syncCost(WorkspaceContext $context, Asset $asset, array $row, array $merged, array $input): ?int {
		$active = $row['cost_uuid'] !== null && $row['cost_deleted_at'] === null;
		$hasTotal = array_key_exists('totalCost', $input);
		if ($hasTotal && ($input['totalCost'] === null || $input['totalCost'] === '')) {
			if ($active) {
				$this->costs->archive($context, (string)$row['cost_uuid'], (int)$row['cost_revision']);
			}
			return null;
		}
		if (!$active) {
			return $hasTotal ? $this->createCost($context, $asset, $merged, $input['totalCost'], $input['currency'] ?? null) : null;
		}
		$changes = ['incurredOn' => gmdate('Y-m-d', (int)$merged['filled_at']), 'description' => self::costDescription($merged)];
		if ($hasTotal) {
			$changes['amount'] = self::amount($input['totalCost']);
		}
		if (($input['currency'] ?? null) !== null) {
			$changes['currency'] = $input['currency'];
			$changes['amount'] ??= CostPolicy::formatAmount((int)$row['cost_minor'], (string)$row['cost_currency']);
		}
		$this->costs->update($context, (string)$row['cost_uuid'], (int)$row['cost_revision'], $changes);
		return (int)$row['cost_id'];
	}

	private static function amount(mixed $total): string|int {
		if (!is_string($total) && !is_int($total)) {
			throw new ValidationException('totalCost must be a decimal string');
		}
		return $total;
	}

	/** @param array<string,mixed> $row */
	private static function costDescription(array $row): string {
		return 'Fuel ' . $row['volume_value'] . ' ' . ($row['volume_unit'] === 'l' ? 'L' : 'gal');
	}

	private function regimeId(int $workspaceId, int $assetId, mixed $uuid): ?int {
		$uuid = CatalogInput::optionalUuid($uuid, 'regimeUuid');
		if ($uuid === null) {
			return null;
		}
		$row = $this->repository->findContext($workspaceId, $uuid);
		if ($row === null || (int)$row['asset_id'] !== $assetId) {
			throw new ValidationException('regimeUuid must be an active regime of the same item');
		}
		return (int)$row['id'];
	}

	private function defaultRegimeId(int $workspaceId, int $assetId): ?int {
		foreach ($this->repository->contexts($workspaceId, $assetId) as $row) {
			if (self::bool($row['is_default'])) {
				return (int)$row['id'];
			}
		}
		return null;
	}

	private function clearOtherDefaults(int $workspaceId, int $assetId, int $exceptId, int $now): void {
		foreach ($this->repository->contexts($workspaceId, $assetId) as $other) {
			if ((int)$other['id'] !== $exceptId && self::bool($other['is_default'])) {
				$this->writeRegime($workspaceId, $other, (int)$other['revision'], ['is_default' => false], 'upsert', $now);
			}
		}
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $changes */
	private function writeRegime(int $workspaceId, array $row, int $expectedRevision, array $changes, string $operation, int $now): void {
		if (!$this->repository->updateContext($workspaceId, (int)$row['id'], $expectedRevision, $changes + ['revision' => $expectedRevision + 1, 'updated_at' => $now])) {
			throw new RevisionConflictException('The regime revision does not match');
		}
		$this->journal->record($workspaceId, 'fuel_regime', (string)$row['uuid'], $operation, $expectedRevision + 1, $now);
	}

	/** @return array<string,mixed> */
	private function regimeRow(int $workspaceId, string $uuid): array {
		return $this->repository->findContext($workspaceId, CatalogInput::uuid($uuid, 'uuid')) ?? throw new NotFoundException('Regime not found');
	}

	/** @return array<string,mixed> */
	private function ticketRow(int $workspaceId, string $uuid, bool $includeArchived = false): array {
		return $this->repository->findTicket($workspaceId, CatalogInput::uuid($uuid, 'uuid'), $includeArchived) ?? throw new NotFoundException('Fuel ticket not found');
	}

	private function assetUuid(WorkspaceContext $context, int $assetId): string {
		return $this->assets->findById($context, $assetId)->getUuid();
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function regimeToApi(array $row, string $assetUuid): array {
		return [
			'uuid' => (string)$row['uuid'],
			'assetUuid' => $assetUuid,
			'name' => (string)$row['name'],
			'isDefault' => self::bool($row['is_default']),
			'sort' => (int)$row['sort_order'],
			'revision' => (int)$row['revision'],
		];
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function ticketToApi(array $row): array {
		$cost = $row['cost_uuid'] !== null && $row['cost_deleted_at'] === null;
		return [
			'uuid' => (string)$row['uuid'],
			'assetUuid' => (string)$row['asset_uuid'],
			'filledAt' => CatalogInput::formatTimestamp((int)$row['filled_at']),
			'odometer' => (string)$row['odometer_value'],
			'odometerUnit' => (string)$row['odometer_unit'],
			'odometerMm' => (int)$row['odometer_mm'],
			'meterUuid' => $row['meter_uuid'] === null ? null : (string)$row['meter_uuid'],
			'readingUuid' => $row['reading_uuid'] === null ? null : (string)$row['reading_uuid'],
			'volume' => (string)$row['volume_value'],
			'volumeUnit' => (string)$row['volume_unit'],
			'volumeUl' => (int)$row['volume_ul'],
			'fullFill' => self::bool($row['full_fill']),
			'missedPrevious' => self::bool($row['missed_previous']),
			'fuelType' => $row['fuel_type'] === null ? null : (string)$row['fuel_type'],
			'station' => $row['station'] === null ? null : (string)$row['station'],
			'regimeUuid' => $row['context_uuid'] === null ? null : (string)$row['context_uuid'],
			'regimeName' => $row['context_name'] === null ? null : (string)$row['context_name'],
			'costUuid' => $cost ? (string)$row['cost_uuid'] : null,
			'totalCost' => $cost ? CostPolicy::formatAmount((int)$row['cost_minor'], (string)$row['cost_currency']) : null,
			'currency' => $cost ? (string)$row['cost_currency'] : null,
			'notes' => $row['notes'] === null ? null : (string)$row['notes'],
			'revision' => (int)$row['revision'],
			'createdAt' => CatalogInput::formatTimestamp((int)$row['created_at']),
			'updatedAt' => CatalogInput::formatTimestamp((int)$row['updated_at']),
			'deletedAt' => $row['deleted_at'] === null ? null : CatalogInput::formatTimestamp((int)$row['deleted_at']),
		];
	}

	/**
	 * @param array<string,mixed> $segment
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private static function segmentToApi(array $segment, array $settings): array {
		return [
			'valid' => $segment['valid'],
			'invalidReason' => $segment['invalidReason'],
			'mixed' => $segment['mixed'],
			'regimeUuid' => $segment['regime'],
			'distance' => FuelEconomy::distanceIn($segment['distanceMm'], $settings['distanceUnit']),
			'volume' => FuelEconomy::volumeIn($segment['volumeUl'], $settings['volumeUnit']),
			'economy' => $segment['valid'] ? FuelEconomy::economy($segment['distanceMm'], $segment['volumeUl'], $settings['economyUnit']) : null,
			'fills' => count($segment['fills']),
		];
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{uuid:string,filledAt:int,odometerMm:int,volumeUl:int,full:bool,missed:bool,regime:?string,costMinor:?int,currency:?string}
	 */
	private static function fill(array $row): array {
		$cost = $row['cost_uuid'] !== null && $row['cost_deleted_at'] === null;
		return [
			'uuid' => (string)$row['uuid'],
			'filledAt' => (int)$row['filled_at'],
			'odometerMm' => (int)$row['odometer_mm'],
			'volumeUl' => (int)$row['volume_ul'],
			'full' => self::bool($row['full_fill']),
			'missed' => self::bool($row['missed_previous']),
			'regime' => $row['context_uuid'] === null ? null : (string)$row['context_uuid'],
			'costMinor' => $cost ? (int)$row['cost_minor'] : null,
			'currency' => $cost ? (string)$row['cost_currency'] : null,
		];
	}

	private static function fuelTab(mixed $mode): string {
		if (!is_string($mode) || !in_array($mode, ['auto', 'on', 'off'], true)) {
			throw new ValidationException('fuelTab must be auto, on or off');
		}
		return $mode;
	}

	/** @param array<string,mixed> $row */
	private static function assertRevision(array $row, int $expectedRevision, string $label): void {
		if ((int)$row['revision'] !== $expectedRevision) {
			throw new RevisionConflictException("The {$label} revision does not match");
		}
	}

	/** @param array<string,mixed> $existing @param array<string,mixed> $row */
	private static function sameFacts(array $existing, array $row): bool {
		foreach ($row as $column => $value) {
			$stored = $existing[$column] ?? null;
			if (is_bool($value)) {
				if (self::bool($stored) !== $value) {
					return false;
				}
			} elseif ($value === null ? $stored !== null : (string)$stored !== (string)$value) {
				return false;
			}
		}
		return true;
	}

	private static function timestamp(mixed $value): int {
		if (!is_string($value)) {
			throw new ValidationException('filledAt must be an ISO-8601 timestamp with a timezone');
		}
		// Seconds are optional (datetime-local inputs omit them).
		$value = (string)preg_replace('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})(Z|[+-]\d{2}:\d{2})$/D', '$1:00$2', trim($value));
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
			throw new ValidationException('filledAt must be an ISO-8601 timestamp with a timezone');
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $value));
		$errors = DateTimeImmutable::getLastErrors();
		if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->getTimestamp() < 0) {
			throw new ValidationException('filledAt is not a valid timestamp');
		}
		return $date->getTimestamp();
	}

	private static function bool(mixed $value): bool {
		return in_array($value, [true, 1, '1', 't', 'true'], true);
	}
}
