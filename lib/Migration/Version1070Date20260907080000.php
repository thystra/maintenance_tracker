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

#[CreateTable(table: 'maint_reminder_policy', columns: ['id','workspace_id','calendar_lead_days','meter_lead_percent','revision','created_at','updated_at'], description: 'Workspace maintenance forecast/reminder policy')]
#[CreateTable(table: 'maint_occurrences', columns: ['id','workspace_id','asset_id','definition_id','definition_uuid','uuid','baseline_activity_uuid','open_marker','opened_at','closed_at','closed_reason','revision','created_at','updated_at'], description: 'Materialized maintenance work-queue occurrences')]
final class Version1070Date20260907080000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_reminder_policy')) {
			$table = $schema->createTable('maint_reminder_policy');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('calendar_lead_days', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 14]);
			$table->addColumn('meter_lead_percent', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 10]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['workspace_id'], 'maint_remind_ws_uq');
		}

		if (!$schema->hasTable('maint_occurrences')) {
			$table = $schema->createTable('maint_occurrences');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('definition_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('definition_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('baseline_activity_uuid', Types::STRING, ['notnull' => false, 'length' => 36, 'default' => null]);
			// Nullable marker provides a portable one-open-row uniqueness constraint:
			// open rows store "open"; closed rows store NULL and may coexist historically.
			$table->addColumn('open_marker', Types::STRING, ['notnull' => false, 'length' => 8, 'default' => 'open']);
			$table->addColumn('opened_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('closed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('closed_reason', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['uuid'], 'maint_occ_uuid_uq');
			$table->addUniqueIndex(['workspace_id','definition_id','open_marker'], 'maint_occ_one_open_uq');
			$table->addIndex(['workspace_id','asset_id','open_marker'], 'maint_occ_asset_open_idx');
		}

		return $schema;
	}

	private function id(mixed $table): void {
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
	}
}
