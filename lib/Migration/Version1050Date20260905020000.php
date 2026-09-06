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
	table: 'maint_work_groups',
	columns: [
		'id', 'workspace_id', 'asset_id', 'uuid', 'group_key', 'name',
		'description', 'sort_order', 'revision', 'created_at', 'updated_at', 'deleted_at',
	],
	description: 'Asset-scoped data-defined work groups',
)]
#[CreateTable(
	table: 'maint_work_defs',
	columns: [
		'id', 'workspace_id', 'asset_id', 'component_id', 'group_id', 'uuid',
		'definition_key', 'title', 'kind', 'instructions', 'notes', 'status',
		'schedule_type', 'schedule_combination', 'revision', 'created_at',
		'updated_at', 'deleted_at',
	],
	description: 'Common scheduled and unscheduled maintenance work definitions',
)]
#[CreateTable(
	table: 'maint_work_sched',
	columns: [
		'id', 'workspace_id', 'definition_id', 'position', 'rule_type',
		'interval_value', 'interval_unit', 'canonical_value', 'meter_id', 'weekday_mask',
	],
	description: 'Normalized scheduling rules for maintenance work definitions',
)]
final class Version1050Date20260905020000 extends SimpleMigrationStep {
	public function changeSchema(
		IOutput $output,
		Closure $schemaClosure,
		array $options,
	): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_work_groups')) {
			$table = $schema->createTable('maint_work_groups');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('group_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$this->addTimestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_work_group_uuid_uq');
			$table->addIndex(['workspace_id', 'asset_id', 'deleted_at'], 'maint_work_group_asset_idx');
			$table->addIndex(['workspace_id', 'asset_id', 'group_key'], 'maint_work_group_key_idx');
		}

		if (!$schema->hasTable('maint_work_defs')) {
			$table = $schema->createTable('maint_work_defs');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('component_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('group_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('definition_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('instructions', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$table->addColumn('schedule_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('schedule_combination', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$this->addTimestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_work_def_uuid_uq');
			$table->addIndex(['workspace_id', 'asset_id', 'deleted_at'], 'maint_work_def_asset_idx');
			$table->addIndex(['workspace_id', 'component_id', 'deleted_at'], 'maint_work_def_comp_idx');
			$table->addIndex(['workspace_id', 'group_id', 'deleted_at'], 'maint_work_def_group_idx');
			$table->addIndex(['workspace_id', 'asset_id', 'definition_key'], 'maint_work_def_key_idx');
			$table->addIndex(['workspace_id', 'schedule_type', 'deleted_at'], 'maint_work_def_sched_idx');
		}

		if (!$schema->hasTable('maint_work_sched')) {
			$table = $schema->createTable('maint_work_sched');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('definition_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('position', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('rule_type', Types::STRING, ['notnull' => true, 'length' => 24]);
			$table->addColumn('interval_value', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('interval_unit', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('canonical_value', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('meter_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('weekday_mask', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addUniqueIndex(['definition_id', 'position'], 'maint_work_sched_pos_uq');
			$table->addIndex(['workspace_id', 'definition_id'], 'maint_work_sched_def_idx');
			$table->addIndex(['workspace_id', 'meter_id'], 'maint_work_sched_meter_idx');
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
