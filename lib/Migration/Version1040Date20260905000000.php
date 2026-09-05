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

#[CreateTable(
	table: 'maint_meters',
	columns: [
		'id', 'workspace_id', 'asset_id', 'component_id', 'uuid', 'meter_key',
		'name', 'dimension', 'canonical_unit', 'display_unit', 'monotonic',
		'revision', 'created_at', 'updated_at', 'deleted_at',
	],
	description: 'Asset and component meter definitions',
)]
#[CreateTable(
	table: 'maint_readings',
	columns: [
		'id', 'workspace_id', 'meter_id', 'uuid', 'observed_at',
		'canonical_value', 'original_value', 'original_unit', 'source_type',
		'source_ref', 'notes', 'supersedes_id', 'created_at',
	],
	description: 'Immutable meter reading observations and corrections',
)]
final class Version1040Date20260905000000 extends SimpleMigrationStep {
	public function changeSchema(
		IOutput $output,
		Closure $schemaClosure,
		array $options,
	): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_meters')) {
			$table = $schema->createTable('maint_meters');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('component_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('meter_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('dimension', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('canonical_unit', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('display_unit', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('monotonic', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$this->addTimestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_meter_uuid_uq');
			$table->addIndex(['workspace_id', 'asset_id', 'deleted_at'], 'maint_meter_asset_idx');
			$table->addIndex(['workspace_id', 'component_id', 'deleted_at'], 'maint_meter_comp_idx');
			$table->addIndex(['workspace_id', 'asset_id', 'meter_key'], 'maint_meter_key_idx');
		}

		if (!$schema->hasTable('maint_readings')) {
			$table = $schema->createTable('maint_readings');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('meter_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('observed_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('canonical_value', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('original_value', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('original_unit', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('source_type', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'manual']);
			$table->addColumn('source_ref', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('supersedes_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['uuid'], 'maint_read_uuid_uq');
			$table->addUniqueIndex(['supersedes_id'], 'maint_read_super_uq');
			$table->addIndex(['workspace_id', 'meter_id', 'observed_at'], 'maint_read_meter_idx');
		}

		return $schema;
	}

	private function addId(mixed $table): void {
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->setPrimaryKey(['id']);
	}

	private function addTimestamps(mixed $table): void {
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
	}
}
