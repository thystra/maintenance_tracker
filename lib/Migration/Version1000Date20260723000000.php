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
	table: 'maint_spaces',
	columns: [
		'id',
		'uuid',
		'owner_uid',
		'personal_owner_uid',
		'name',
		'kind',
		'revision',
		'created_at',
		'updated_at',
		'deleted_at',
	],
	description: 'Maintenance Tracker workspaces',
)]
#[CreateTable(
	table: 'maint_user_state',
	columns: ['id', 'user_key', 'state', 'lock_token', 'updated_at'],
	description: 'Serialized account lifecycle state',
)]
#[CreateTable(
	table: 'maint_members',
	columns: ['id', 'workspace_id', 'user_uid', 'role', 'created_at'],
	description: 'Workspace membership and authorization',
)]
#[CreateTable(
	table: 'maint_assets',
	columns: [
		'id',
		'workspace_id',
		'uuid',
		'category_key',
		'name',
		'manufacturer',
		'model',
		'model_year',
		'serial_number',
		'notes',
		'status',
		'profile_key',
		'profile_version',
		'acquired_on',
		'purchase_price_minor',
		'currency',
		'revision',
		'created_at',
		'updated_at',
		'deleted_at',
	],
	description: 'Things maintained by a workspace',
)]
#[CreateTable(
	table: 'maint_changes',
	columns: [
		'id',
		'workspace_id',
		'entity_type',
		'entity_uuid',
		'operation',
		'revision',
		'changed_at',
	],
	description: 'Monotonic journal for offline synchronization',
)]
final class Version1000Date20260723000000 extends SimpleMigrationStep {
	public function changeSchema(
		IOutput $output,
		Closure $schemaClosure,
		array $options,
	): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_spaces')) {
			$table = $schema->createTable('maint_spaces');
			$this->addId($table);
			$table->addColumn('uuid', Types::STRING, [
				'notnull' => true,
				'length' => 36,
			]);
			$table->addColumn('owner_uid', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('personal_owner_uid', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('kind', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'personal',
			]);
			$table->addColumn('revision', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
				'default' => 1,
			]);
			$this->addTimestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_sp_uuid_uq');
			$table->addUniqueIndex(['personal_owner_uid'], 'maint_sp_person_uq');
			$table->addIndex(['owner_uid'], 'maint_sp_owner_idx');
		}

		if (!$schema->hasTable('maint_user_state')) {
			$table = $schema->createTable('maint_user_state');
			$this->addId($table);
			$table->addColumn('user_key', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('state', Types::STRING, [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('lock_token', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addUniqueIndex(['user_key'], 'maint_usr_key_uq');
			$table->addIndex(['state', 'updated_at'], 'maint_usr_state_idx');
		}

		if (!$schema->hasTable('maint_members')) {
			$table = $schema->createTable('maint_members');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_uid', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('role', Types::STRING, [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addUniqueIndex(
				['workspace_id', 'user_uid'],
				'maint_mem_ws_user_uq',
			);
			$table->addIndex(['user_uid'], 'maint_mem_user_idx');
		}

		if (!$schema->hasTable('maint_assets')) {
			$table = $schema->createTable('maint_assets');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('uuid', Types::STRING, [
				'notnull' => true,
				'length' => 36,
			]);
			$table->addColumn('category_key', Types::STRING, [
				'notnull' => true,
				'length' => 64,
				'default' => 'other',
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('manufacturer', Types::STRING, [
				'notnull' => false,
				'length' => 255,
				'default' => null,
			]);
			$table->addColumn('model', Types::STRING, [
				'notnull' => false,
				'length' => 255,
				'default' => null,
			]);
			$table->addColumn('model_year', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
				'default' => null,
			]);
			$table->addColumn('serial_number', Types::STRING, [
				'notnull' => false,
				'length' => 255,
				'default' => null,
			]);
			$table->addColumn('notes', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'active',
			]);
			$table->addColumn('profile_key', Types::STRING, [
				'notnull' => false,
				'length' => 160,
				'default' => null,
			]);
			$table->addColumn('profile_version', Types::STRING, [
				'notnull' => false,
				'length' => 32,
				'default' => null,
			]);
			$table->addColumn('acquired_on', Types::STRING, [
				'notnull' => false,
				'length' => 10,
				'default' => null,
			]);
			$table->addColumn('purchase_price_minor', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
				'default' => null,
			]);
			$table->addColumn('currency', Types::STRING, [
				'notnull' => false,
				'length' => 3,
				'default' => null,
			]);
			$table->addColumn('revision', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
				'default' => 1,
			]);
			$this->addTimestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_asset_uuid_uq');
			$table->addIndex(
				['workspace_id', 'deleted_at', 'status'],
				'maint_asset_ws_idx',
			);
			$table->addIndex(
				['workspace_id', 'category_key'],
				'maint_asset_cat_idx',
			);
		}

		if (!$schema->hasTable('maint_changes')) {
			$table = $schema->createTable('maint_changes');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('entity_type', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('entity_uuid', Types::STRING, [
				'notnull' => true,
				'length' => 36,
			]);
			$table->addColumn('operation', Types::STRING, [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('revision', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('changed_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addIndex(
				['workspace_id', 'id'],
				'maint_ch_ws_id_idx',
			);
			$table->addIndex(
				['workspace_id', 'entity_uuid'],
				'maint_ch_entity_idx',
			);
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
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('updated_at', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('deleted_at', Types::BIGINT, [
			'notnull' => false,
			'unsigned' => true,
			'default' => null,
		]);
	}
}
