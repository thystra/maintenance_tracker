<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Activity;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;

/**
 * Parts used during an activity. Rows are created atomically with the activity,
 * reference canonical parts (and optionally a vendor) by UUID, and snapshot the
 * display facts so history survives later catalog corrections.
 */
final class ActivityPartService {
	public const MAX_PARTS = 100;
	public const QUANTITY_SCALE = 3;
	public const UNITS = ['each', 'pair', 'set', 'kit', 'ml', 'l', 'fl_oz', 'qt', 'gal', 'g', 'kg', 'oz', 'lb', 'mm', 'cm', 'm', 'in', 'ft'];
	private const FIELDS = ['uuid', 'partUuid', 'quantity', 'unit', 'vendorUuid', 'notes'];

	public function __construct(
		private ActivityPartRepository $repository,
		private PartCatalogRepository $catalog,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function validateList(mixed $raw): array {
		if ($raw === null) {
			return [];
		}
		if (!is_array($raw) || !array_is_list($raw)) {
			throw new ValidationException('parts must be an array');
		}
		if (count($raw) > self::MAX_PARTS) {
			throw new ValidationException('An activity may record at most ' . self::MAX_PARTS . ' parts');
		}
		$out = [];
		$seen = [];
		foreach ($raw as $i => $entry) {
			if (!is_array($entry)) {
				throw new ValidationException("parts[{$i}] must be an object");
			}
			CatalogInput::known($entry, self::FIELDS, 'activity part');
			foreach (['uuid', 'partUuid', 'quantity', 'unit'] as $field) {
				if (!array_key_exists($field, $entry)) {
					throw new ValidationException("parts[{$i}].{$field} is required");
				}
			}
			$uuid = CatalogInput::uuid($entry['uuid'], "parts[{$i}].uuid");
			if (isset($seen[$uuid])) {
				throw new ValidationException('Activity part UUIDs must be unique');
			}
			$seen[$uuid] = true;
			$unit = CatalogInput::text($entry['unit'], "parts[{$i}].unit", 16);
			if (!in_array($unit, self::UNITS, true)) {
				throw new ValidationException("parts[{$i}].unit is not a supported quantity unit");
			}
			$out[] = [
				'uuid' => $uuid,
				'partUuid' => CatalogInput::uuid($entry['partUuid'], "parts[{$i}].partUuid"),
				'quantityMilli' => DecimalQuantity::parse($entry['quantity'], self::QUANTITY_SCALE, "parts[{$i}].quantity"),
				'unit' => $unit,
				'vendorUuid' => CatalogInput::optionalUuid($entry['vendorUuid'] ?? null, "parts[{$i}].vendorUuid"),
				'notes' => CatalogInput::optionalText($entry['notes'] ?? null, "parts[{$i}].notes", 2000, true),
			];
		}
		return $out;
	}

	/** @param list<array<string,mixed>> $parts validated by validateList() */
	public function appendAll(WorkspaceContext $context, Activity $activity, array $parts): void {
		$workspaceId = $context->workspace()->getId();
		foreach ($parts as $position => $p) {
			if ($this->repository->uuidExists($p['uuid'])) {
				throw new RevisionConflictException('An activity or child UUID already exists');
			}
			$part = $this->catalog->findPartByUuid($workspaceId, $p['partUuid']);
			if ($part === null) {
				throw new ValidationException('Activity part references an unknown or archived part');
			}
			$vendor = null;
			if ($p['vendorUuid'] !== null) {
				$vendor = $this->catalog->findVendorByUuid($workspaceId, $p['vendorUuid']);
				if ($vendor === null) {
					throw new ValidationException('Activity part references an unknown or archived vendor');
				}
			}
			$this->repository->append([
				'workspace_id' => $workspaceId,
				'activity_id' => $activity->getId(),
				'uuid' => $p['uuid'],
				'position' => $position,
				'part_id' => (int)$part['id'],
				'part_uuid' => (string)$part['uuid'],
				'manufacturer' => (string)$part['manufacturer'],
				'part_number' => (string)$part['part_number'],
				'description' => (string)$part['description'],
				'quantity_milli' => $p['quantityMilli'],
				'quantity_unit' => $p['unit'],
				'vendor_id' => $vendor === null ? null : (int)$vendor['id'],
				'vendor_uuid' => $vendor === null ? null : (string)$vendor['uuid'],
				'vendor_name' => $vendor === null ? null : (string)$vendor['name'],
				'notes' => $p['notes'],
			]);
		}
	}

	/** Idempotent-retry comparison of the semantic parts payload. */
	public function matches(WorkspaceContext $context, Activity $activity, mixed $raw): bool {
		$requested = $this->validateList($raw);
		$stored = $this->repository->findForActivity($context->workspace()->getId(), $activity->getId());
		if (count($requested) !== count($stored)) {
			return false;
		}
		foreach ($stored as $i => $row) {
			$p = $requested[$i];
			if ($p['uuid'] !== $row['uuid'] || $p['partUuid'] !== $row['part_uuid'] || $p['quantityMilli'] !== (int)$row['quantity_milli']
				|| $p['unit'] !== $row['quantity_unit'] || $p['vendorUuid'] !== $row['vendor_uuid'] || $p['notes'] !== $row['notes']) {
				return false;
			}
		}
		return true;
	}

	/** @return list<array<string,mixed>> */
	public function forActivity(WorkspaceContext $context, Activity $activity): array {
		return array_map(static fn (array $row): array => [
			'uuid' => (string)$row['uuid'],
			'partUuid' => (string)$row['part_uuid'],
			'manufacturer' => (string)$row['manufacturer'],
			'partNumber' => (string)$row['part_number'],
			'description' => (string)$row['description'],
			'quantity' => DecimalQuantity::format((int)$row['quantity_milli'], self::QUANTITY_SCALE, true),
			'unit' => (string)$row['quantity_unit'],
			'vendorUuid' => $row['vendor_uuid'] === null ? null : (string)$row['vendor_uuid'],
			'vendorName' => $row['vendor_name'] === null ? null : (string)$row['vendor_name'],
			'notes' => $row['notes'] === null ? null : (string)$row['notes'],
		], $this->repository->findForActivity($context->workspace()->getId(), $activity->getId()));
	}
}
