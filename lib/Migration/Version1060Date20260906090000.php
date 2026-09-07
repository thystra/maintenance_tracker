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

#[CreateTable(table: 'maint_activities', columns: ['id','workspace_id','asset_id','uuid','performed_at','summary','notes','revision','created_at','updated_at','deleted_at'], description: 'Maintenance execution activity headers')]
#[CreateTable(table: 'maint_activity_items', columns: ['id','workspace_id','activity_id','uuid','position','definition_id','definition_uuid','component_id','component_uuid','component_name','title','kind','notes'], description: 'Immutable work items performed in an activity')]
#[CreateTable(table: 'maint_activity_meters', columns: ['id','workspace_id','activity_id','uuid','position','meter_id','meter_uuid','meter_name','reading_id','reading_uuid','observed_at','canonical_value','original_value','original_unit'], description: 'Immutable meter snapshots captured with an activity')]
final class Version1060Date20260906090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('maint_activities')) {
			$t = $schema->createTable('maint_activities'); $this->id($t);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('asset_id', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('uuid', Types::STRING, ['notnull'=>true,'length'=>36]);
			$t->addColumn('performed_at', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('summary', Types::STRING, ['notnull'=>false,'length'=>255,'default'=>null]);
			$t->addColumn('notes', Types::TEXT, ['notnull'=>false,'default'=>null]);
			$t->addColumn('revision', Types::INTEGER, ['notnull'=>true,'unsigned'=>true,'default'=>1]);
			$this->timestamps($t);
			$t->addUniqueIndex(['uuid'], 'maint_activity_uuid_uq');
			$t->addIndex(['workspace_id','asset_id','performed_at'], 'maint_activity_asset_idx');
		}
		if (!$schema->hasTable('maint_activity_items')) {
			$t = $schema->createTable('maint_activity_items'); $this->id($t);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('activity_id', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('uuid', Types::STRING, ['notnull'=>true,'length'=>36]);
			$t->addColumn('position', Types::INTEGER, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('definition_id', Types::BIGINT, ['notnull'=>false,'unsigned'=>true,'default'=>null]);
			$t->addColumn('definition_uuid', Types::STRING, ['notnull'=>false,'length'=>36,'default'=>null]);
			$t->addColumn('component_id', Types::BIGINT, ['notnull'=>false,'unsigned'=>true,'default'=>null]);
			$t->addColumn('component_uuid', Types::STRING, ['notnull'=>false,'length'=>36,'default'=>null]);
			$t->addColumn('component_name', Types::STRING, ['notnull'=>false,'length'=>255,'default'=>null]);
			$t->addColumn('title', Types::STRING, ['notnull'=>true,'length'=>255]);
			$t->addColumn('kind', Types::STRING, ['notnull'=>true,'length'=>64]);
			$t->addColumn('notes', Types::TEXT, ['notnull'=>false,'default'=>null]);
			$t->addUniqueIndex(['uuid'], 'maint_activity_item_uuid_uq');
			$t->addUniqueIndex(['activity_id','position'], 'maint_activity_item_pos_uq');
			$t->addIndex(['workspace_id','activity_id'], 'maint_activity_item_act_idx');
		}
		if (!$schema->hasTable('maint_activity_meters')) {
			$t = $schema->createTable('maint_activity_meters'); $this->id($t);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('activity_id', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('uuid', Types::STRING, ['notnull'=>true,'length'=>36]);
			$t->addColumn('position', Types::INTEGER, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('meter_id', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('meter_uuid', Types::STRING, ['notnull'=>true,'length'=>36]);
			$t->addColumn('meter_name', Types::STRING, ['notnull'=>true,'length'=>255]);
			$t->addColumn('reading_id', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('reading_uuid', Types::STRING, ['notnull'=>true,'length'=>36]);
			$t->addColumn('observed_at', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('canonical_value', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]);
			$t->addColumn('original_value', Types::STRING, ['notnull'=>true,'length'=>64]);
			$t->addColumn('original_unit', Types::STRING, ['notnull'=>true,'length'=>16]);
			$t->addUniqueIndex(['uuid'], 'maint_activity_meter_uuid_uq');
			$t->addUniqueIndex(['activity_id','position'], 'maint_activity_meter_pos_uq');
			$t->addIndex(['workspace_id','activity_id'], 'maint_activity_meter_act_idx');
			$t->addIndex(['workspace_id','reading_id'], 'maint_activity_meter_read_idx');
		}
		return $schema;
	}
	private function id(mixed $t): void { $t->addColumn('id', Types::BIGINT, ['autoincrement'=>true,'notnull'=>true,'unsigned'=>true]); $t->setPrimaryKey(['id']); }
	private function timestamps(mixed $t): void { $t->addColumn('created_at', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]); $t->addColumn('updated_at', Types::BIGINT, ['notnull'=>true,'unsigned'=>true]); $t->addColumn('deleted_at', Types::BIGINT, ['notnull'=>false,'unsigned'=>true,'default'=>null]); }
}
