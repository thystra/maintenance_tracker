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
use OCP\Migration\Attributes\AddColumn;
use OCP\Migration\Attributes\ColumnType;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Workspace ownership transfer. A transfer is a resumable state machine: each
 * step is persisted before it runs, and the per-item snapshot lets evidence
 * file references be re-pointed after the evidence folder moves to the new
 * owner's Files. `orphaned_at` marks a workspace whose owner account was
 * deleted while other members remained, awaiting an administrator.
 */
#[CreateTable(table: 'maint_ws_transfers', columns: ['id','uuid','workspace_id','from_uid','to_uid','reason','status','step','leave_after','expires_at','target_path','error','created_at','updated_at','finished_at'], description: 'Pending and in-progress workspace ownership transfers')]
#[CreateTable(table: 'maint_ws_xfer_items', columns: ['id','transfer_id','item_type','ref_id','old_file_id','rel_path','new_file_id'], description: 'Evidence folder/file snapshot used to re-point references after a transfer move')]
#[AddColumn(table: 'maint_spaces', name: 'orphaned_at', type: ColumnType::BIGINT, description: 'Set when the owner account was deleted and other members remain')]
final class Version1111Date20261016140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_ws_transfers')) {
			$t = $schema->createTable('maint_ws_transfers');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('from_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('to_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 24]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('step', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('leave_after', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('target_path', Types::TEXT, ['notnull' => false, 'default' => null]);
			$t->addColumn('error', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('finished_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addUniqueIndex(['uuid'], 'maint_wst_uuid_uq');
			$t->addIndex(['workspace_id', 'status'], 'maint_wst_ws_idx');
			$t->addIndex(['to_uid', 'status'], 'maint_wst_to_idx');
			$t->addIndex(['from_uid', 'status'], 'maint_wst_from_idx');
		}

		if (!$schema->hasTable('maint_ws_xfer_items')) {
			$t = $schema->createTable('maint_ws_xfer_items');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('transfer_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('item_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('ref_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('old_file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('rel_path', Types::TEXT, ['notnull' => true]);
			$t->addColumn('new_file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addUniqueIndex(['transfer_id', 'item_type', 'ref_id'], 'maint_wsx_uq');
		}

		$spaces = $schema->getTable('maint_spaces');
		if (!$spaces->hasColumn('orphaned_at')) {
			$spaces->addColumn('orphaned_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
		}

		return $schema;
	}
}
