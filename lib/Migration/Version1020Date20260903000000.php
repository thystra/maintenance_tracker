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
	table: 'maint_relationships',
	columns: [
		'id', 'workspace_id', 'uuid', 'source_asset_id', 'target_asset_id',
		'type_key', 'context_key', 'is_default', 'notes', 'revision',
		'created_at', 'updated_at', 'deleted_at',
	],
	description: 'Typed relationships between independent assets',
)]
#[CreateTable(
	table: 'maint_assignments',
	columns: [
		'id', 'workspace_id', 'uuid', 'source_asset_id', 'target_asset_id',
		'type_key', 'context_key', 'is_primary', 'effective_from',
		'effective_until', 'notes', 'revision', 'created_at', 'updated_at',
		'deleted_at',
	],
	description: 'Effective-dated operational asset assignments',
)]
final class Version1020Date20260903000000 extends SimpleMigrationStep {
	public function changeSchema(
		IOutput $output,
		Closure $schemaClosure,
		array $options,
	): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('maint_spaces')) {
			$spaces = $schema->getTable('maint_spaces');
			if (!$spaces->hasColumn('write_lock_token')) {
				$spaces->addColumn('write_lock_token', Types::STRING, [
					'notnull' => false,
					'length' => 36,
					'default' => null,
				]);
			}
		}

		if (!$schema->hasTable('maint_relationships')) {
			$table = $schema->createTable('maint_relationships');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('source_asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('target_asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('type_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('context_key', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			$table->addColumn('is_default', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$this->addTimestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_rel_uuid_uq');
			$table->addIndex(['workspace_id', 'source_asset_id', 'deleted_at'], 'maint_rel_source_idx');
			$table->addIndex(['workspace_id', 'target_asset_id', 'deleted_at'], 'maint_rel_target_idx');
			$table->addIndex(['workspace_id', 'type_key', 'context_key', 'is_default'], 'maint_rel_default_idx');
		}

		if (!$schema->hasTable('maint_assignments')) {
			$table = $schema->createTable('maint_assignments');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('source_asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('target_asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('type_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('context_key', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			$table->addColumn('is_primary', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('effective_from', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('effective_until', Types::STRING, ['notnull' => false, 'length' => 10, 'default' => null]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$this->addTimestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_asn_uuid_uq');
			$table->addIndex(['workspace_id', 'source_asset_id', 'deleted_at'], 'maint_asn_source_idx');
			$table->addIndex(['workspace_id', 'target_asset_id', 'deleted_at'], 'maint_asn_target_idx');
			$table->addIndex(['workspace_id', 'type_key', 'context_key', 'is_primary'], 'maint_asn_primary_idx');
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
