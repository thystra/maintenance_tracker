<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Central cost ledger. Every member can read, Owner/Manager/Contributor can
 * add entries, and only Owner/Manager can correct or archive them. Workspace
 * currency/tax settings are an Owner-only workspace setting.
 */
final class CostService {
	private const CREATE_FIELDS = ['uuid', 'incurredOn', 'lineType', 'category', 'description', 'amount', 'currency', 'activityUuid', 'vendorUuid', 'partUuid', 'notes'];
	private const UPDATE_FIELDS = ['incurredOn', 'category', 'description', 'amount', 'currency', 'vendorUuid', 'partUuid', 'notes'];

	public function __construct(
		private CostRepository $repository,
		private PartCatalogRepository $catalog,
		private AssetService $assets,
		private ActivityService $activities,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return array{defaultCurrency:string,taxEnabled:bool,multiCurrency:bool,revision:int} */
	public function settings(WorkspaceContext $context): array {
		$row = $this->repository->findSettings($context->workspace()->getId());
		if ($row === null) {
			return CostPolicy::defaults();
		}
		return [
			'defaultCurrency' => (string)$row['default_currency'],
			'taxEnabled' => self::bool($row['tax_enabled']),
			'multiCurrency' => self::bool($row['multi_currency']),
			'revision' => (int)$row['revision'],
		];
	}

	/** @return array<string,mixed> */
	public function settingsToApi(WorkspaceContext $context): array {
		$s = $this->settings($context);
		return $s + ['source' => $s['revision'] === 0 ? 'default' : 'workspace', 'currenciesInUse' => $this->repository->activeCurrencies($context->workspace()->getId())];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function updateSettings(WorkspaceContext $context, int $expectedRevision, array $input): array {
		CatalogInput::known($input, ['defaultCurrency', 'taxEnabled', 'multiCurrency'], 'cost settings');
		$current = $this->settings($context);
		if ($expectedRevision !== $current['revision']) {
			throw new RevisionConflictException('The cost settings revision does not match');
		}
		$currency = array_key_exists('defaultCurrency', $input) ? CurrencyCatalog::normalize($input['defaultCurrency'], 'defaultCurrency') : $current['defaultCurrency'];
		$tax = array_key_exists('taxEnabled', $input) ? CatalogInput::bool($input['taxEnabled'], 'taxEnabled') : $current['taxEnabled'];
		$multi = array_key_exists('multiCurrency', $input) ? CatalogInput::bool($input['multiCurrency'], 'multiCurrency') : $current['multiCurrency'];
		$workspaceId = $context->workspace()->getId();
		if (!$multi) {
			$foreign = array_values(array_diff($this->repository->activeCurrencies($workspaceId), [$currency]));
			if ($foreign !== []) {
				throw new ValidationException('Single-currency mode requires every active cost entry to use ' . $currency . '; entries exist in ' . implode(', ', $foreign));
			}
		}
		$now = $this->timeFactory->getTime();
		if ($current['revision'] === 0) {
			$this->repository->insertSettings($workspaceId, $currency, $tax, $multi, $now);
		} elseif (!$this->repository->updateSettings($workspaceId, $expectedRevision, $currency, $tax, $multi, $now)) {
			throw new RevisionConflictException('The cost settings revision does not match');
		}
		$this->journal->record($workspaceId, 'cost_settings', $context->workspace()->getUuid(), 'upsert', $expectedRevision + 1, $now, 'cost_settings.updated', [
			'defaultCurrency' => $currency,
			'taxEnabled' => $tax,
			'multiCurrency' => $multi,
		]);
		return $this->settingsToApi($context);
	}

	/** @return array<string,mixed> */
	public function listForAsset(WorkspaceContext $context, string $assetUuid): array {
		$asset = $this->assets->find($context, $assetUuid);
		$rows = $this->repository->findActive($context->workspace()->getId(), $asset->getId());
		$items = array_map(fn (array $row): array => $this->toApi($row), $rows);
		return ['assetUuid' => $asset->getUuid(), 'items' => $items, 'totals' => CostPolicy::totals($items), 'settings' => $this->settings($context)];
	}

	/** @return array<string,mixed> */
	public function workspaceTotals(WorkspaceContext $context): array {
		$items = array_map(fn (array $row): array => $this->toApi($row), $this->repository->findActive($context->workspace()->getId(), null));
		return ['totals' => CostPolicy::totals($items), 'settings' => $this->settings($context)];
	}

	/** @return array<string,mixed> */
	public function show(WorkspaceContext $context, string $uuid): array {
		return $this->toApi($this->findRow($context, $uuid, true));
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function create(WorkspaceContext $context, string $assetUuid, array $input): array {
		CatalogInput::known($input, self::CREATE_FIELDS, 'cost');
		foreach (['uuid', 'incurredOn', 'description', 'amount'] as $field) {
			if (!array_key_exists($field, $input)) {
				throw new ValidationException("{$field} is required");
			}
		}
		$asset = $this->assets->find($context, $assetUuid);
		$workspaceId = $context->workspace()->getId();
		$settings = $this->settings($context);
		$uuid = CatalogInput::uuid($input['uuid'], 'uuid');
		$class = CostPolicy::classify($input['lineType'] ?? null, $input['category'] ?? null, $settings);
		$currency = CostPolicy::currency($input['currency'] ?? null, $settings);
		$row = [
			'workspace_id' => $workspaceId,
			'asset_id' => $asset->getId(),
			'uuid' => $uuid,
			'activity_id' => $this->activityId($context, $asset->getId(), $input['activityUuid'] ?? null),
			'vendor_id' => $this->vendorId($workspaceId, $input['vendorUuid'] ?? null),
			'part_id' => $this->partId($workspaceId, $input['partUuid'] ?? null),
			'line_type' => $class['lineType'],
			'category' => $class['category'],
			'description' => CatalogInput::text($input['description'], 'description', 255),
			'amount_minor' => CostPolicy::amountMinor($input['amount'], $currency),
			'currency' => $currency,
			'incurred_on' => CatalogInput::date($input['incurredOn'], 'incurredOn'),
			'notes' => CatalogInput::optionalText($input['notes'] ?? null, 'notes', 2000, true),
		];

		$existing = $this->repository->findByUuid($workspaceId, $uuid, true);
		if ($existing !== null) {
			if ($existing['deleted_at'] === null && (int)$existing['revision'] === 1 && $this->sameFacts($existing, $row)) {
				return $this->toApi($existing);
			}
			throw new RevisionConflictException('The supplied cost UUID already exists with different data');
		}
		if ($this->repository->uuidExists($uuid)) {
			throw new RevisionConflictException('The supplied cost UUID already exists with different data');
		}
		$now = $this->timeFactory->getTime();
		$this->repository->insert($row + ['revision' => 1, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null]);
		$this->journal->record($workspaceId, 'cost', $uuid, 'upsert', 1, $now);
		return $this->toApi($this->findRow($context, $uuid));
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		CatalogInput::known($input, self::UPDATE_FIELDS, 'cost update');
		$row = $this->findRow($context, $uuid);
		$this->assertRevision($row, $expectedRevision);
		$workspaceId = $context->workspace()->getId();
		$settings = $this->settings($context);
		$changes = [];
		$currency = (string)$row['currency'];
		if (array_key_exists('currency', $input)) {
			$currency = CostPolicy::currency($input['currency'], $settings);
			$changes['currency'] = $currency;
		}
		if (array_key_exists('amount', $input)) {
			$changes['amount_minor'] = CostPolicy::amountMinor($input['amount'], $currency);
		} elseif ($currency !== $row['currency']) {
			throw new ValidationException('Changing currency requires restating amount; no exchange conversion is performed');
		}
		if (array_key_exists('category', $input)) {
			$changes['category'] = CostPolicy::classify($row['line_type'], $input['category'], array_merge($settings, ['taxEnabled' => true]))['category'];
		}
		if (array_key_exists('description', $input)) {
			$changes['description'] = CatalogInput::text($input['description'], 'description', 255);
		}
		if (array_key_exists('incurredOn', $input)) {
			$changes['incurred_on'] = CatalogInput::date($input['incurredOn'], 'incurredOn');
		}
		if (array_key_exists('notes', $input)) {
			$changes['notes'] = CatalogInput::optionalText($input['notes'], 'notes', 2000, true);
		}
		if (array_key_exists('vendorUuid', $input)) {
			$changes['vendor_id'] = $this->vendorId($workspaceId, $input['vendorUuid']);
		}
		if (array_key_exists('partUuid', $input)) {
			$changes['part_id'] = $this->partId($workspaceId, $input['partUuid']);
		}
		return $this->write($context, $row, $expectedRevision, $changes, 'upsert');
	}

	/** @return array<string,mixed> */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		$row = $this->findRow($context, $uuid);
		$this->assertRevision($row, $expectedRevision);
		return $this->write($context, $row, $expectedRevision, ['deleted_at' => $this->timeFactory->getTime()], 'delete');
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	public function toApi(array $row): array {
		$currency = (string)$row['currency'];
		$minor = (int)$row['amount_minor'];
		return [
			'uuid' => (string)$row['uuid'],
			'assetUuid' => (string)$row['asset_uuid'],
			'activityUuid' => $row['activity_uuid'] === null ? null : (string)$row['activity_uuid'],
			'vendorUuid' => $row['vendor_uuid'] === null ? null : (string)$row['vendor_uuid'],
			'vendorName' => $row['vendor_name'] === null ? null : (string)$row['vendor_name'],
			'partUuid' => $row['part_uuid'] === null ? null : (string)$row['part_uuid'],
			'lineType' => (string)$row['line_type'],
			'category' => (string)$row['category'],
			'description' => (string)$row['description'],
			'amountMinor' => $minor,
			'amount' => CostPolicy::formatAmount($minor, $currency),
			'currency' => $currency,
			'exponent' => CurrencyCatalog::exponent($currency),
			'incurredOn' => (string)$row['incurred_on'],
			'notes' => $row['notes'] === null ? null : (string)$row['notes'],
			'revision' => (int)$row['revision'],
			'createdAt' => CatalogInput::formatTimestamp((int)$row['created_at']),
			'updatedAt' => CatalogInput::formatTimestamp((int)$row['updated_at']),
			'deletedAt' => $row['deleted_at'] === null ? null : CatalogInput::formatTimestamp((int)$row['deleted_at']),
		];
	}

	/** @return array<string,mixed> */
	private function findRow(WorkspaceContext $context, string $uuid, bool $includeArchived = false): array {
		$row = $this->repository->findByUuid($context->workspace()->getId(), CatalogInput::uuid($uuid, 'uuid'), $includeArchived);
		if ($row === null) {
			throw new NotFoundException('Cost entry not found');
		}
		return $row;
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $changes @return array<string,mixed> */
	private function write(WorkspaceContext $context, array $row, int $expectedRevision, array $changes, string $operation): array {
		$workspaceId = $context->workspace()->getId();
		$now = $this->timeFactory->getTime();
		$changes += ['revision' => $expectedRevision + 1, 'updated_at' => $now];
		if (!$this->repository->update($workspaceId, (int)$row['id'], $expectedRevision, $changes)) {
			throw new RevisionConflictException('The cost entry revision does not match');
		}
		$this->journal->record($workspaceId, 'cost', (string)$row['uuid'], $operation, $expectedRevision + 1, $now);
		return $this->toApi($this->findRow($context, (string)$row['uuid'], true));
	}

	/** @param array<string,mixed> $row */
	private function assertRevision(array $row, int $expectedRevision): void {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be a positive integer');
		}
		if ((int)$row['revision'] !== $expectedRevision) {
			throw new RevisionConflictException('The cost entry revision does not match');
		}
	}

	private function activityId(WorkspaceContext $context, int $assetId, mixed $uuid): ?int {
		$uuid = CatalogInput::optionalUuid($uuid, 'activityUuid');
		if ($uuid === null) {
			return null;
		}
		$activity = $this->activities->find($context, $uuid);
		if ($activity->getAssetId() !== $assetId) {
			throw new ValidationException('activityUuid must belong to the same asset');
		}
		return $activity->getId();
	}

	private function vendorId(int $workspaceId, mixed $uuid): ?int {
		$uuid = CatalogInput::optionalUuid($uuid, 'vendorUuid');
		if ($uuid === null) {
			return null;
		}
		$vendor = $this->catalog->findVendorByUuid($workspaceId, $uuid);
		if ($vendor === null) {
			throw new ValidationException('vendorUuid references an unknown or archived vendor');
		}
		return (int)$vendor['id'];
	}

	private function partId(int $workspaceId, mixed $uuid): ?int {
		$uuid = CatalogInput::optionalUuid($uuid, 'partUuid');
		if ($uuid === null) {
			return null;
		}
		$part = $this->catalog->findPartByUuid($workspaceId, $uuid);
		if ($part === null) {
			throw new ValidationException('partUuid references an unknown or archived part');
		}
		return (int)$part['id'];
	}

	/** @param array<string,mixed> $existing @param array<string,mixed> $row */
	private function sameFacts(array $existing, array $row): bool {
		foreach ($row as $column => $value) {
			$stored = $existing[$column];
			if ($value === null ? $stored !== null : (string)$stored !== (string)$value) {
				return false;
			}
		}
		return true;
	}

	private static function bool(mixed $value): bool {
		return in_array($value, [true, 1, '1', 't', 'true'], true);
	}
}
