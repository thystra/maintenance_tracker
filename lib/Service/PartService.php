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
 * Canonical workspace part catalog. Part identity is manufacturer + part number
 * under the same conservative normalization used by fitment packs, so manual,
 * fitment-imported, and profile-installed parts converge on one record.
 */
final class PartService {
	private const EXTRA_FIELDS = ['notes', 'vendorUuid', 'unitPrice', 'currency'];

	public function __construct(
		private PartCatalogRepository $repository,
		private PartCompatibilityRepository $compatibility,
		private AssetService $assets,
		private FitmentPackValidator $identity,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	public function identityHash(string $manufacturer, string $partNumber): string {
		return $this->identity->partIdentityHash($manufacturer, $partNumber);
	}

	/** @return list<array<string,mixed>> */
	public function list(WorkspaceContext $context, bool $includeArchived = false): array {
		$workspaceId = $context->workspace()->getId();
		$fits = $this->compatibility->fitsByPart($workspaceId);
		$vendors = $this->vendorNames($workspaceId);
		return array_map(fn (array $row): array => $this->toApi($row, $fits[(int)$row['id']] ?? [], $vendors), $this->repository->listParts($workspaceId, $includeArchived));
	}

	/** @return array<string,mixed> */
	public function show(WorkspaceContext $context, string $uuid): array {
		return $this->detail($context, $this->findRow($context, $uuid, true));
	}

	/**
	 * Record that a part fits a whole item (manual compatibility). Profile and
	 * fitment compatibility is managed by those features and not changed here.
	 *
	 * @return array<string,mixed>
	 */
	public function addFit(WorkspaceContext $context, string $uuid, int $expectedRevision, string $assetUuid): array {
		$row = $this->findRow($context, $uuid);
		$this->assertRevision($row, $expectedRevision);
		$asset = $this->assets->find($context, $assetUuid);
		$workspaceId = $context->workspace()->getId();
		if (!$this->compatibility->hasManualFit($workspaceId, (int)$row['id'], $asset->getId())) {
			$this->compatibility->insert($workspaceId, $asset->getId(), (int)$row['id'], 'asset', $asset->getUuid(), null, $this->timeFactory->getTime());
		}
		$this->write($context, $row, $expectedRevision, (string)$row['description'], null, 'upsert');
		return $this->show($context, $uuid);
	}

	/** @return array<string,mixed> */
	public function removeFit(WorkspaceContext $context, string $uuid, int $expectedRevision, string $assetUuid): array {
		$row = $this->findRow($context, $uuid);
		$this->assertRevision($row, $expectedRevision);
		$asset = $this->assets->find($context, $assetUuid);
		$this->compatibility->deleteManualFit($context->workspace()->getId(), (int)$row['id'], $asset->getId());
		$this->write($context, $row, $expectedRevision, (string)$row['description'], null, 'upsert');
		return $this->show($context, $uuid);
	}

	/** @return array<string,mixed> */
	public function findRow(WorkspaceContext $context, string $uuid, bool $includeArchived = false): array {
		$row = $this->repository->findPartByUuid($context->workspace()->getId(), CatalogInput::uuid($uuid, 'partUuid'), $includeArchived);
		if ($row === null) {
			throw new NotFoundException('Part not found');
		}
		return $row;
	}

	/**
	 * Create a canonical part, or return the existing identity. An archived part
	 * with the same identity is restored instead of duplicated.
	 *
	 * @param array<string,mixed> $input
	 * @return array{part: array<string,mixed>, created: bool}
	 */
	public function create(WorkspaceContext $context, array $input): array {
		CatalogInput::known($input, ['uuid', 'manufacturer', 'partNumber', 'description', ...self::EXTRA_FIELDS], 'part');
		$manufacturer = CatalogInput::text($input['manufacturer'] ?? null, 'manufacturer', 255);
		$partNumber = CatalogInput::text($input['partNumber'] ?? null, 'partNumber', 128);
		$description = CatalogInput::text($input['description'] ?? null, 'description', 1000);
		$uuid = array_key_exists('uuid', $input) ? CatalogInput::uuid($input['uuid'], 'uuid') : $this->uuidGenerator->generate();
		$extra = $this->extras($context, $input, []);
		$row = $this->ensure($context, $uuid, $manufacturer, $partNumber, $description, $extra);
		// An existing identity is returned unchanged (created=false); edit it instead.
		return ['part' => $this->detail($context, $row['row']), 'created' => $row['created']];
	}

	/**
	 * Resolve a canonical part by identity, creating it when absent. Used by the
	 * manual API and by profile-v2 materialization.
	 *
	 * @return array{row: array<string,mixed>, created: bool}
	 */
	/** @param array<string,string|int|null> $extra Optional columns for a newly inserted part */
	public function ensure(WorkspaceContext $context, string $uuid, string $manufacturer, string $partNumber, string $description, array $extra = []): array {
		$workspaceId = $context->workspace()->getId();
		$identityHash = $this->identity->partIdentityHash($manufacturer, $partNumber);
		$existing = $this->repository->findPartByIdentity($workspaceId, $identityHash);
		$now = $this->timeFactory->getTime();
		if ($existing !== null) {
			if ($existing['deleted_at'] !== null) {
				$revision = (int)$existing['revision'];
				if (!$this->repository->updatePart($workspaceId, (int)$existing['id'], $revision, (string)$existing['description'], null, $now)) {
					throw new RevisionConflictException('The part changed while it was being restored');
				}
				$this->journal->record($workspaceId, 'part', (string)$existing['uuid'], 'upsert', $revision + 1, $now);
				$existing = $this->repository->findPartById($workspaceId, (int)$existing['id']) ?? throw new \LogicException('Restored part disappeared');
			}
			return ['row' => $existing, 'created' => false];
		}
		if ($this->repository->findPartByUuidAnyWorkspace($uuid) !== null) {
			throw new RevisionConflictException('The supplied part UUID already exists with different data');
		}
		$id = $this->repository->insertPart($workspaceId, $uuid, $identityHash, $manufacturer, $partNumber, $description, $now, $extra);
		$this->journal->record($workspaceId, 'part', $uuid, 'upsert', 1, $now);
		return ['row' => $this->repository->findPartById($workspaceId, $id) ?? throw new \LogicException('Inserted part disappeared'), 'created' => true];
	}

	/**
	 * Manufacturer and part number are identity and immutable; only the
	 * descriptive text may be corrected.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		CatalogInput::known($input, ['description', ...self::EXTRA_FIELDS], 'part update');
		$row = $this->findRow($context, $uuid);
		$this->assertRevision($row, $expectedRevision);
		$description = array_key_exists('description', $input) ? CatalogInput::text($input['description'], 'description', 1000) : (string)$row['description'];
		$this->write($context, $row, $expectedRevision, $description, null, 'upsert', $this->extras($context, $input, $row));
		return $this->show($context, $uuid);
	}

	/** @return array<string,mixed> */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		$row = $this->findRow($context, $uuid);
		$this->assertRevision($row, $expectedRevision);
		return $this->write($context, $row, $expectedRevision, (string)$row['description'], $this->timeFactory->getTime(), 'delete');
	}

	/**
	 * Informational compatibility facts for an asset's components and work
	 * definitions. Archived parts are still reported and flagged.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function compatibilityForAsset(WorkspaceContext $context, string $assetUuid): array {
		$asset = $this->assets->find($context, $assetUuid);
		return array_map(static fn (array $row): array => [
			'subjectType' => (string)$row['subject_type'],
			'subjectUuid' => (string)$row['subject_uuid'],
			'part' => [
				'uuid' => (string)$row['part_uuid'],
				'manufacturer' => (string)$row['manufacturer'],
				'partNumber' => (string)$row['part_number'],
				'description' => (string)$row['description'],
				'archived' => $row['part_deleted_at'] !== null,
			],
		], $this->compatibility->findForAsset($context->workspace()->getId(), $asset->getId()));
	}

	/**
	 * @param array<string,mixed> $row
	 * @param list<array{assetUuid:string,assetName:string,source:string}> $fits
	 * @param array<int,array{uuid:string,name:string}>|null $vendors
	 * @return array<string,mixed>
	 */
	public function toApi(array $row, array $fits = [], ?array $vendors = null): array {
		$vendorId = isset($row['vendor_id']) ? (int)$row['vendor_id'] : null;
		$vendor = $vendorId === null ? null : ($vendors ?? [])[$vendorId] ?? null;
		$price = isset($row['unit_price_minor'], $row['currency']) ? CostPolicy::formatAmount((int)$row['unit_price_minor'], (string)$row['currency']) : null;
		return [
			'uuid' => (string)$row['uuid'],
			'manufacturer' => (string)$row['manufacturer'],
			'partNumber' => (string)$row['part_number'],
			'description' => (string)$row['description'],
			'notes' => isset($row['notes']) ? (string)$row['notes'] : null,
			'vendorUuid' => $vendor['uuid'] ?? null,
			'vendorName' => $vendor['name'] ?? null,
			'unitPrice' => $price,
			'currency' => $price === null ? null : (string)$row['currency'],
			'fits' => $fits,
			'revision' => (int)$row['revision'],
			'createdAt' => CatalogInput::formatTimestamp((int)$row['created_at']),
			'updatedAt' => CatalogInput::formatTimestamp((int)$row['updated_at']),
			'deletedAt' => $row['deleted_at'] === null ? null : CatalogInput::formatTimestamp((int)$row['deleted_at']),
		];
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	/** @param array<string,string|int|null> $extra */
	private function write(WorkspaceContext $context, array $row, int $expectedRevision, string $description, ?int $deletedAt, string $operation, array $extra = []): array {
		$workspaceId = $context->workspace()->getId();
		$now = $this->timeFactory->getTime();
		if (!$this->repository->updatePart($workspaceId, (int)$row['id'], $expectedRevision, $description, $deletedAt, $now, $extra)) {
			throw new RevisionConflictException('The part revision does not match');
		}
		$this->journal->record($workspaceId, 'part', (string)$row['uuid'], $operation, $expectedRevision + 1, $now);
		return $this->toApi($this->repository->findPartById($workspaceId, (int)$row['id']) ?? throw new \LogicException('Updated part disappeared'));
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function detail(WorkspaceContext $context, array $row): array {
		$workspaceId = $context->workspace()->getId();
		$fits = $this->compatibility->fitsByPart($workspaceId, (int)$row['id']);
		return $this->toApi($row, $fits[(int)$row['id']] ?? [], $this->vendorNames($workspaceId));
	}

	/** @return array<int,array{uuid:string,name:string}> */
	private function vendorNames(int $workspaceId): array {
		$names = [];
		foreach ($this->repository->listVendors($workspaceId, true) as $vendor) {
			$names[(int)$vendor['id']] = ['uuid' => (string)$vendor['uuid'], 'name' => (string)$vendor['name']];
		}
		return $names;
	}

	/**
	 * Optional catalog extras. Absent fields keep their stored value on update.
	 *
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $row
	 * @return array<string,string|int|null>
	 */
	private function extras(WorkspaceContext $context, array $input, array $row): array {
		$extra = [];
		if (array_key_exists('notes', $input)) {
			$extra['notes'] = CatalogInput::optionalText($input['notes'], 'notes', 2000, true);
		}
		if (array_key_exists('vendorUuid', $input)) {
			$vendorUuid = CatalogInput::optionalUuid($input['vendorUuid'] === '' ? null : $input['vendorUuid'], 'vendorUuid');
			$extra['vendor_id'] = null;
			if ($vendorUuid !== null) {
				$vendor = $this->repository->findVendorByUuid($context->workspace()->getId(), $vendorUuid);
				if ($vendor === null) {
					throw new ValidationException('vendorUuid does not name an active vendor in this workspace');
				}
				$extra['vendor_id'] = (int)$vendor['id'];
			}
		}
		if (array_key_exists('unitPrice', $input) || array_key_exists('currency', $input)) {
			$price = $input['unitPrice'] ?? null;
			if ($price === null || $price === '') {
				$extra['unit_price_minor'] = null;
				$extra['currency'] = null;
			} else {
				$currency = CurrencyCatalog::normalize($input['currency'] ?? ($row['currency'] ?? null), 'currency');
				$extra['unit_price_minor'] = DecimalQuantity::parse($price, CurrencyCatalog::exponent($currency), 'unitPrice', false, true);
				$extra['currency'] = $currency;
			}
		}
		return $extra;
	}

	/** @param array<string,mixed> $row */
	private function assertRevision(array $row, int $expectedRevision): void {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be a positive integer');
		}
		if ((int)$row['revision'] !== $expectedRevision) {
			throw new RevisionConflictException('The part revision does not match');
		}
	}
}
