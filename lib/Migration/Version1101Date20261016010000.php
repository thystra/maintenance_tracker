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
 * Immutable parts-used records captured with an activity. Part and vendor
 * display facts are snapshotted so later catalog corrections or archival do
 * not rewrite execution history.
 */
#[CreateTable(table: 'maint_activity_parts', columns: ['id','workspace_id','activity_id','uuid','position','part_id','part_uuid','manufacturer','part_number','description','quantity_milli','quantity_unit','vendor_id','vendor_uuid','vendor_name','notes'], description: 'Immutable parts used in an activity')]
final class Version1101Date20261016010000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_activity_parts')) {
			$t = $schema->createTable('maint_activity_parts');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('activity_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('part_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('part_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('manufacturer', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('part_number', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('description', Types::STRING, ['notnull' => true, 'length' => 1000]);
			$t->addColumn('quantity_milli', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('quantity_unit', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('vendor_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addColumn('vendor_uuid', Types::STRING, ['notnull' => false, 'length' => 36, 'default' => null]);
			$t->addColumn('vendor_name', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$t->addUniqueIndex(['uuid'], 'maint_activity_part_uuid_uq');
			$t->addUniqueIndex(['activity_id', 'position'], 'maint_activity_part_pos_uq');
			$t->addIndex(['workspace_id', 'activity_id'], 'maint_activity_part_act_idx');
			$t->addIndex(['workspace_id', 'part_id'], 'maint_activity_part_part_idx');
		}

		return $schema;
	}
}
