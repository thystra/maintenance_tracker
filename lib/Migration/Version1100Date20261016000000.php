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
 * v0.1.10 vendor catalog. Canonical parts already exist in maint_parts
 * (Version1090); this step adds the workspace vendor records that parts usage,
 * costs, and links reference.
 */
#[CreateTable(table: 'maint_vendors', columns: ['id','workspace_id','uuid','name','notes','revision','created_at','updated_at','deleted_at'], description: 'Workspace vendor catalog')]
final class Version1100Date20261016000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_vendors')) {
			$table = $schema->createTable('maint_vendors');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addUniqueIndex(['uuid'], 'maint_vendor_uuid_uq');
			$table->addIndex(['workspace_id', 'deleted_at'], 'maint_vendor_ws_idx');
		}

		return $schema;
	}
}
