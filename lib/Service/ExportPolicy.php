<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

/**
 * What a workspace export may contain. Every section requires the read
 * capability that already governs the same data through the OCS API, so an
 * export never shows a role more than it can read today. Internal sharing,
 * delivery, transfer and change-journal bookkeeping is never exported, and
 * evidence is exported only through the viewer-scoped evidence projection.
 */
final class ExportPolicy {
	public const FORMAT = 'maintenance-tracker-export';
	public const FORMAT_VERSION = 1;
	public const SPREADSHEET_ESCAPE = 'leading-apostrophe-v1';

	/** Total and per-file bounds for evidence bytes in a ZIP export. */
	public const MAX_FILE_BYTES = 104857600;
	public const MAX_TOTAL_FILE_BYTES = 536870912;

	/** @var array<string, string> section => table */
	public const TABLES = [
		'categories' => 'maint_categories',
		'assets' => 'maint_assets',
		'components' => 'maint_components',
		'specifications' => 'maint_specs',
		'relationships' => 'maint_relationships',
		'assignments' => 'maint_assignments',
		'meters' => 'maint_meters',
		'readings' => 'maint_readings',
		'work_groups' => 'maint_work_groups',
		'work_definitions' => 'maint_work_defs',
		'work_schedule_rules' => 'maint_work_sched',
		'activities' => 'maint_activities',
		'activity_items' => 'maint_activity_items',
		'activity_meters' => 'maint_activity_meters',
		'activity_parts' => 'maint_activity_parts',
		'occurrences' => 'maint_occurrences',
		'reminder_policy' => 'maint_reminder_policy',
		'profiles' => 'maint_profiles',
		'profile_revisions' => 'maint_prof_revs',
		'asset_profiles' => 'maint_asset_prof',
		'profile_bindings' => 'maint_prof_bind',
		'fitment_packs' => 'maint_fit_packs',
		'fitment_revisions' => 'maint_fit_revs',
		'fitment_imports' => 'maint_fit_imports',
		'fitment_bindings' => 'maint_fit_bind',
		'fitment_targets' => 'maint_fit_targets',
		'fitment_slots' => 'maint_fit_slots',
		'fitment_offers' => 'maint_offers',
		'fitments' => 'maint_fitments',
		'asset_fitments' => 'maint_asset_fit',
		'parts' => 'maint_parts',
		'part_compatibility' => 'maint_part_compat',
		'vendors' => 'maint_vendors',
		'links' => 'maint_links',
		'costs' => 'maint_costs',
		'cost_settings' => 'maint_cost_settings',
		'members' => 'maint_members',
		'audit' => 'maint_audit',
	];

	/** @var array<string, string> section => required capability */
	public const CAPABILITY = [
		'categories' => AuthorizationCatalog::INVENTORY_READ,
		'assets' => AuthorizationCatalog::INVENTORY_READ,
		'components' => AuthorizationCatalog::INVENTORY_READ,
		'specifications' => AuthorizationCatalog::INVENTORY_READ,
		'relationships' => AuthorizationCatalog::INVENTORY_READ,
		'assignments' => AuthorizationCatalog::INVENTORY_READ,
		'meters' => AuthorizationCatalog::METER_READ,
		'readings' => AuthorizationCatalog::METER_READ,
		'work_groups' => AuthorizationCatalog::MAINTENANCE_DEFINITION_READ,
		'work_definitions' => AuthorizationCatalog::MAINTENANCE_DEFINITION_READ,
		'work_schedule_rules' => AuthorizationCatalog::MAINTENANCE_DEFINITION_READ,
		'activities' => AuthorizationCatalog::ACTIVITY_READ,
		'activity_items' => AuthorizationCatalog::ACTIVITY_READ,
		'activity_meters' => AuthorizationCatalog::ACTIVITY_READ,
		'activity_parts' => AuthorizationCatalog::ACTIVITY_READ,
		'occurrences' => AuthorizationCatalog::MAINTENANCE_OCCURRENCE_READ,
		'reminder_policy' => AuthorizationCatalog::REMINDER_POLICY_READ,
		'profiles' => AuthorizationCatalog::PROFILE_READ,
		'profile_revisions' => AuthorizationCatalog::PROFILE_READ,
		'asset_profiles' => AuthorizationCatalog::PROFILE_READ,
		'profile_bindings' => AuthorizationCatalog::PROFILE_READ,
		'fitment_packs' => AuthorizationCatalog::FITMENT_READ,
		'fitment_revisions' => AuthorizationCatalog::FITMENT_READ,
		'fitment_imports' => AuthorizationCatalog::FITMENT_READ,
		'fitment_bindings' => AuthorizationCatalog::FITMENT_READ,
		'fitment_targets' => AuthorizationCatalog::FITMENT_READ,
		'fitment_slots' => AuthorizationCatalog::FITMENT_READ,
		'fitment_offers' => AuthorizationCatalog::FITMENT_READ,
		'fitments' => AuthorizationCatalog::FITMENT_READ,
		'asset_fitments' => AuthorizationCatalog::FITMENT_READ,
		'parts' => AuthorizationCatalog::PART_READ,
		'part_compatibility' => AuthorizationCatalog::PART_READ,
		'vendors' => AuthorizationCatalog::VENDOR_READ,
		'links' => AuthorizationCatalog::PART_READ,
		'costs' => AuthorizationCatalog::COST_READ,
		'cost_settings' => AuthorizationCatalog::COST_READ,
		'members' => AuthorizationCatalog::WORKSPACE_MEMBERS_READ,
		'audit' => AuthorizationCatalog::AUDIT_READ,
		'evidence' => AuthorizationCatalog::EVIDENCE_READ,
	];

	/** Columns never exported from any table. */
	private const DROPPED_COLUMNS = ['workspace_id', 'write_lock_token'];

	/** Offer/link URL columns hidden while outside links are disabled. */
	public const URL_SECTIONS = ['fitment_offers' => 'url', 'vendors' => 'website'];

	/** Never exported: internal bookkeeping, not maintenance data. */
	public const EXCLUDED_TABLES = ['maint_changes', 'maint_ev_folders', 'maint_ev_shares', 'maint_ws_transfers', 'maint_ws_xfer_items', 'maint_notify_log', 'maint_cal_events', 'maint_evidence', 'maint_evidence_links'];

	public function __construct(private AuthorizationCatalog $authorization) {
	}

	/**
	 * @return array{included: list<string>, omitted: list<array{section: string, reason: string}>}
	 */
	public function sections(string $role, bool $outsideLinksEnabled): array {
		$included = [];
		$omitted = [];
		foreach (array_keys(self::CAPABILITY) as $section) {
			if (!$this->authorization->allows($role, self::CAPABILITY[$section])) {
				$omitted[] = ['section' => $section, 'reason' => 'not_permitted_for_role'];
			} elseif ($section === 'links' && !$outsideLinksEnabled) {
				$omitted[] = ['section' => $section, 'reason' => 'outside_links_disabled'];
			} else {
				$included[] = $section;
			}
		}
		return ['included' => $included, 'omitted' => $omitted];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, scalar|null>
	 */
	public function row(string $section, array $row, bool $outsideLinksEnabled): array {
		foreach (self::DROPPED_COLUMNS as $column) {
			unset($row[$column]);
		}
		if (!$outsideLinksEnabled && ($column = self::URL_SECTIONS[$section] ?? null) !== null) {
			$row[$column] = null;
		}
		return array_map(static fn (mixed $value): mixed => is_resource($value) ? stream_get_contents($value) : $value, $row);
	}

	public static function spreadsheetCell(mixed $value): string {
		if ($value === null) {
			return '';
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if (is_array($value)) {
			$value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		}
		$value = (string)$value;
		// Same reversible scheme as the fitment CSV projection.
		foreach (['=', '+', '-', '@', "'", "\t", "\r", "\n", '＝', '＋', '－', '＠'] as $prefix) {
			if (str_starts_with($value, $prefix)) {
				return "'" . $value;
			}
		}
		return $value;
	}

	/** @param list<array<string, mixed>> $rows */
	public static function csv(array $rows): string {
		$columns = [];
		foreach ($rows as $row) {
			foreach (array_keys($row) as $column) {
				$columns[$column] = true;
			}
		}
		$columns = array_keys($columns);
		$handle = fopen('php://temp', 'w+b');
		if ($handle === false) {
			throw new \RuntimeException('Could not allocate CSV buffer');
		}
		fputcsv($handle, $columns, ',', '"', '');
		foreach ($rows as $row) {
			fputcsv($handle, array_map(static fn (string $column): string => self::spreadsheetCell($row[$column] ?? null), $columns), ',', '"', '');
		}
		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);
		return $csv;
	}

	/** Safe ZIP entry name for an evidence file: never a path, never hidden. */
	public static function fileEntry(string $evidenceUuid, string $name): string {
		$base = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename(str_replace('\\', '/', $name))) ?? '';
		$base = ltrim($base, '.');
		$base = substr($base === '' ? 'file' : $base, 0, 120);
		return 'files/' . $evidenceUuid . '-' . $base;
	}
}
