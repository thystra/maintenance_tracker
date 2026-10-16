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
 * Central cost ledger. Money is stored only as signed 64-bit integer minor
 * units plus an ISO 4217 code; there is no floating-point or decimal column.
 */
#[CreateTable(table: 'maint_costs', columns: ['id','workspace_id','asset_id','uuid','activity_id','vendor_id','part_id','line_type','category','description','amount_minor','currency','incurred_on','notes','revision','created_at','updated_at','deleted_at'], description: 'Central maintenance cost ledger in integer minor units')]
#[CreateTable(table: 'maint_cost_settings', columns: ['id','workspace_id','default_currency','tax_enabled','multi_currency','revision','updated_at'], description: 'Per-workspace cost ledger currency and tax settings')]
final class Version1103Date20261016030000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_costs')) {
			$t = $schema->createTable('maint_costs');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('activity_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addColumn('vendor_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addColumn('part_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addColumn('line_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('category', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('description', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('amount_minor', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('currency', Types::STRING, ['notnull' => true, 'length' => 3]);
			$t->addColumn('incurred_on', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$t->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addUniqueIndex(['uuid'], 'maint_cost_uuid_uq');
			$t->addIndex(['workspace_id', 'asset_id', 'incurred_on'], 'maint_cost_asset_idx');
			$t->addIndex(['workspace_id', 'activity_id'], 'maint_cost_activity_idx');
			$t->addIndex(['workspace_id', 'currency'], 'maint_cost_currency_idx');
		}

		if (!$schema->hasTable('maint_cost_settings')) {
			$t = $schema->createTable('maint_cost_settings');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('default_currency', Types::STRING, ['notnull' => true, 'length' => 3]);
			$t->addColumn('tax_enabled', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$t->addColumn('multi_currency', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$t->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addUniqueIndex(['workspace_id'], 'maint_costset_ws_uq');
		}

		return $schema;
	}
}
