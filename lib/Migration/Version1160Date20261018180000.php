<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fuel log with operating regimes (docs/domain-model.md "Vehicle extension").
 *
 * - maint_vehicle: per-item fuel settings (Fuel tab mode, default display
 *   units and fuel type). One row per item, created on first change.
 * - maint_contexts: user-defined operating regimes per item ("Empty",
 *   "Towing camper"), one of them the default.
 * - maint_fuel: fuel tickets. Volume and odometer are kept exactly as entered
 *   plus an integer canonical value (microlitres, millimetres). The price is
 *   not stored here: a ticket links to a maint_costs entry, the one ledger
 *   for money. The odometer reading can create or link a maint_readings row.
 */
#[CreateTable(table: 'maint_vehicle', columns: ['id','workspace_id','asset_id','fuel_tab','distance_unit','volume_unit','economy_unit','fuel_type','revision','created_at','updated_at'], description: 'Per-item fuel settings: Fuel tab mode, default units and fuel type')]
#[CreateTable(table: 'maint_contexts', columns: ['id','workspace_id','asset_id','uuid','name','is_default','sort_order','revision','created_at','updated_at','deleted_at'], description: 'Operating regimes (operating contexts) of an item')]
#[CreateTable(table: 'maint_fuel', columns: ['id','workspace_id','asset_id','uuid','filled_at','odometer_value','odometer_unit','odometer_mm','meter_id','reading_id','volume_value','volume_unit','volume_ul','full_fill','missed_previous','fuel_type','station','context_id','cost_id','notes','revision','created_at','updated_at','deleted_at'], description: 'Fuel tickets with regime, odometer link and linked cost entry')]
final class Version1160Date20261018180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('maint_vehicle')) {
			$t = $schema->createTable('maint_vehicle');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('fuel_tab', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'auto']);
			$t->addColumn('distance_unit', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'mi']);
			$t->addColumn('volume_unit', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'gal']);
			$t->addColumn('economy_unit', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'mpg']);
			$t->addColumn('fuel_type', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
			$t->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addUniqueIndex(['asset_id'], 'maint_veh_asset_uq');
			$t->addIndex(['workspace_id'], 'maint_veh_ws_idx');
			$changed = true;
		}

		if (!$schema->hasTable('maint_contexts')) {
			$t = $schema->createTable('maint_contexts');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('is_default', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addUniqueIndex(['uuid'], 'maint_ctx_uuid_uq');
			$t->addIndex(['workspace_id', 'asset_id'], 'maint_ctx_asset_idx');
			$changed = true;
		}

		if (!$schema->hasTable('maint_fuel')) {
			$t = $schema->createTable('maint_fuel');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('filled_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('odometer_value', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('odometer_unit', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('odometer_mm', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('meter_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addColumn('reading_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addColumn('volume_value', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('volume_unit', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('volume_ul', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('full_fill', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('missed_previous', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('fuel_type', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
			$t->addColumn('station', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$t->addColumn('context_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addColumn('cost_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$t->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addUniqueIndex(['uuid'], 'maint_fuel_uuid_uq');
			$t->addIndex(['workspace_id', 'asset_id', 'filled_at'], 'maint_fuel_asset_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
