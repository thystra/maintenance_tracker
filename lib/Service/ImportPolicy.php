<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

/**
 * Pure rules for restoring a workspace export into a new workspace: which
 * sections are restored and in which order, how local numeric references are
 * remapped, and how every UUID identity is replaced so the copy can live next
 * to the original on the same instance (UUIDs are globally unique).
 */
final class ImportPolicy {
	/** Upload and content bounds. */
	public const MAX_UPLOAD_BYTES = 671088640;
	public const MAX_DATA_BYTES = 67108864;
	public const MAX_ROWS = 250000;
	public const MAX_ZIP_ENTRIES = 20000;

	/** Restored sections in dependency order (referenced tables first). */
	public const ORDER = [
		'categories', 'item_groups', 'assets', 'asset_groups', 'components', 'specifications', 'relationships', 'assignments',
		'meters', 'readings', 'work_groups', 'work_definitions', 'work_schedule_rules',
		'vendors', 'parts', 'links',
		'profiles', 'profile_revisions', 'asset_profiles', 'profile_bindings', 'part_compatibility',
		'fitment_packs', 'fitment_revisions', 'fitment_imports', 'fitment_bindings', 'fitment_targets',
		'fitment_slots', 'fitment_offers', 'fitments', 'asset_fitments',
		'activities', 'activity_items', 'activity_meters', 'activity_parts',
		'occurrences', 'reminder_policy', 'cost_settings', 'costs',
		'fuel_settings', 'fuel_regimes', 'fuel', 'workspace_profiles',
	];

	/**
	 * Sections that are exported but deliberately not restored: membership is
	 * re-established by inviting people, and the audit trail of the original
	 * workspace is history of a different workspace.
	 */
	public const NOT_RESTORED = ['members', 'audit'];

	/** @var array<string, array<string, string>> section => column => referenced section */
	public const REFERENCES = [
		'*' => [
			'asset_id' => 'assets', 'source_asset_id' => 'assets', 'target_asset_id' => 'assets',
			'component_id' => 'components', 'activity_id' => 'activities', 'definition_id' => 'work_definitions',
			'meter_id' => 'meters', 'reading_id' => 'readings', 'part_id' => 'parts', 'vendor_id' => 'vendors',
			'profile_id' => 'profiles', 'profile_revision_id' => 'profile_revisions', 'asset_profile_id' => 'asset_profiles',
			'pack_id' => 'fitment_packs', 'import_id' => 'fitment_imports', 'slot_id' => 'fitment_slots',
		],
		'components' => ['parent_id' => 'components'],
		'item_groups' => ['parent_id' => 'item_groups'],
		'asset_groups' => ['group_id' => 'item_groups'],
		'readings' => ['supersedes_id' => 'readings'],
		'work_definitions' => ['group_id' => 'work_groups'],
		'fitment_imports' => ['revision_id' => 'fitment_revisions'],
		'fitments' => ['target_id' => 'fitment_targets'],
		'asset_fitments' => ['target_id' => 'fitment_targets'],
		'fuel' => ['context_id' => 'fuel_regimes', 'cost_id' => 'costs'],
	];

	/** Polymorphic references: section => [type column, id column, type => section]. */
	public const POLYMORPHIC = [
		'links' => ['subject_type', 'subject_id', ['part' => 'parts', 'vendor' => 'vendors']],
	];

	/**
	 * Self-referencing sections whose rows are re-ordered parents-first before
	 * insertion: a moved group can reference a parent with a larger id.
	 */
	public const PARENT_FIRST = ['item_groups' => 'parent_id'];

	/** Columns holding a row's own UUID identity, regenerated on import. */
	public const IDENTITY_COLUMNS = ['uuid', 'installation_uuid', 'import_uuid'];

	/** Columns whose content is hashed; never rewritten. */
	public const VERBATIM_COLUMNS = ['content_json', 'content_hash'];

	private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

	/**
	 * Validate the export document envelope.
	 *
	 * @param mixed $document
	 * @return array<string, mixed>
	 */
	public static function document(mixed $document): array {
		if (!is_array($document) || ($document['format'] ?? null) !== ExportPolicy::FORMAT) {
			throw new ValidationException('Not a Maintenance Tracker export');
		}
		if (($document['formatVersion'] ?? null) !== ExportPolicy::FORMAT_VERSION) {
			throw new ValidationException('Unsupported export format version');
		}
		if (($document['role'] ?? null) !== 'owner') {
			throw new ValidationException('Only a complete export made by a workspace owner can be restored');
		}
		if (!is_array($document['tables'] ?? null) || !is_array($document['evidence'] ?? [])) {
			throw new ValidationException('The export has no tables');
		}
		$rows = 0;
		foreach ($document['tables'] as $section => $sectionRows) {
			if (!is_string($section) || (!in_array($section, self::ORDER, true) && !in_array($section, self::NOT_RESTORED, true))) {
				throw new ValidationException('Unknown export section ' . (is_string($section) ? $section : '?'));
			}
			if (!is_array($sectionRows) || !array_is_list($sectionRows)) {
				throw new ValidationException('Section ' . $section . ' must be a list of rows');
			}
			foreach ($sectionRows as $row) {
				if (!is_array($row) || array_is_list($row) && $row !== []) {
					throw new ValidationException('Section ' . $section . ' contains a malformed row');
				}
				foreach ($row as $column => $value) {
					if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $column) !== 1) {
						throw new ValidationException('Section ' . $section . ' contains an invalid column name');
					}
					if (!is_scalar($value) && $value !== null) {
						throw new ValidationException('Section ' . $section . ' column ' . $column . ' must be a scalar');
					}
				}
			}
			$rows += count($sectionRows);
		}
		if ($rows > self::MAX_ROWS) {
			throw new ValidationException('The export has more than ' . self::MAX_ROWS . ' rows');
		}
		return $document;
	}

	/** Referenced section for a column, or null when the column is not a reference. */
	public static function reference(string $section, string $column): ?string {
		return self::REFERENCES[$section][$column] ?? self::REFERENCES['*'][$column] ?? null;
	}

	/**
	 * Build the old => new UUID map for every identity column in the document.
	 *
	 * @param array<string, list<array<string, mixed>>> $tables
	 * @param callable(): string $generate
	 * @return array<string, string>
	 */
	public static function uuidMap(array $tables, array $evidence, callable $generate): array {
		$map = [];
		foreach (self::ORDER as $section) {
			foreach ($tables[$section] ?? [] as $row) {
				foreach (self::IDENTITY_COLUMNS as $column) {
					if (is_string($row[$column] ?? null) && preg_match(self::UUID, $row[$column]) === 1) {
						$map[strtolower($row[$column])] ??= $generate();
					}
				}
			}
		}
		foreach ($evidence as $item) {
			if (is_array($item) && is_string($item['uuid'] ?? null)) {
				$map[strtolower($item['uuid'])] ??= $generate();
			}
		}
		return $map;
	}

	/**
	 * Replace every mapped UUID occurring in a string value.
	 *
	 * @param array<string, string> $uuidMap
	 */
	public static function rewrite(string $value, array $uuidMap): string {
		return (string)preg_replace_callback(self::UUID, static fn (array $m): string => $uuidMap[strtolower($m[0])] ?? $m[0], $value);
	}

	/**
	 * Remap one exported row for insertion into the new workspace.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, array<int, int>> $idMap section => old id => new id
	 * @param array<string, string> $uuidMap
	 * @param array<string, string> $columnTypes column => 'int'|'bool'|'string' for the target table
	 * @return array<string, scalar|null>
	 */
	public static function remap(string $section, array $row, array $idMap, array $uuidMap, array $columnTypes): array {
		$out = [];
		$polymorphic = self::POLYMORPHIC[$section] ?? null;
		foreach ($row as $column => $value) {
			if ($column === 'id' || $column === 'workspace_id') {
				continue;
			}
			if (!isset($columnTypes[$column])) {
				throw new ValidationException('Section ' . $section . ' has unknown column ' . $column);
			}
			$target = self::reference($section, $column);
			if ($polymorphic !== null && $column === $polymorphic[1]) {
				$target = $polymorphic[2][(string)($row[$polymorphic[0]] ?? '')] ?? null;
				if ($target === null) {
					throw new ValidationException('Section ' . $section . ' has an unknown ' . $polymorphic[0]);
				}
			}
			if ($target === null && str_ends_with($column, '_id')) {
				throw new ValidationException('Section ' . $section . ' column ' . $column . ' is not a restorable reference');
			}
			if ($target !== null) {
				if ($value === null) {
					$out[$column] = null;
					continue;
				}
				$new = $idMap[$target][(int)$value] ?? null;
				if ($new === null) {
					throw new ValidationException('Section ' . $section . ' references a missing ' . $target . ' row');
				}
				$out[$column] = $new;
				continue;
			}
			$out[$column] = self::cast($value, $columnTypes[$column], in_array($column, self::VERBATIM_COLUMNS, true) ? [] : $uuidMap);
		}
		return $out;
	}

	/** @param array<string, string> $uuidMap */
	private static function cast(mixed $value, string $type, array $uuidMap): mixed {
		if ($value === null) {
			return null;
		}
		return match ($type) {
			'int' => is_numeric($value) ? (int)$value : throw new ValidationException('Expected an integer value'),
			'bool' => in_array($value, [true, 1, '1', 't', 'true'], true),
			default => self::rewrite((string)$value, $uuidMap),
		};
	}
}
