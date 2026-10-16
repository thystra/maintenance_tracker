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
 * v0.1.11 evidence in Nextcloud Files. Bytes stay in Files; these tables hold
 * verified file references, many-to-many links, the app-managed workspace
 * folder identities, and the member shares of those folders.
 */
#[CreateTable(table: 'maint_evidence', columns: ['id','workspace_id','uuid','kind','title','file_id','attached_by','storage','mime','size','sha256','original_name','last_known_path','notes','revision','created_at','updated_at','deleted_at'], description: 'Evidence file references into Nextcloud Files')]
#[CreateTable(table: 'maint_evidence_links', columns: ['id','workspace_id','evidence_id','target_type','target_id','created_at'], description: 'Many-to-many evidence links to assets, activities, and costs')]
#[CreateTable(table: 'maint_ev_folders', columns: ['id','workspace_id','scope','owner_uid','folder_id','created_at'], description: 'App-managed workspace/asset evidence folders in the workspace owner Files')]
#[CreateTable(table: 'maint_ev_shares', columns: ['id','workspace_id','member_uid','share_id','permissions','created_at','updated_at'], description: 'Nextcloud user shares of the workspace evidence folder to members')]
final class Version1110Date20261016100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_evidence')) {
			$t = $schema->createTable('maint_evidence');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('attached_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('storage', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('mime', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('sha256', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			$t->addColumn('original_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('last_known_path', Types::TEXT, ['notnull' => true]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$t->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addUniqueIndex(['uuid'], 'maint_ev_uuid_uq');
			$t->addIndex(['workspace_id', 'file_id'], 'maint_ev_file_idx');
		}

		if (!$schema->hasTable('maint_evidence_links')) {
			$t = $schema->createTable('maint_evidence_links');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('evidence_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('target_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('target_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addUniqueIndex(['evidence_id', 'target_type', 'target_id'], 'maint_evl_uq');
			$t->addIndex(['workspace_id', 'target_type', 'target_id'], 'maint_evl_target_idx');
		}

		if (!$schema->hasTable('maint_ev_folders')) {
			$t = $schema->createTable('maint_ev_folders');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 48]);
			$t->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('folder_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addUniqueIndex(['workspace_id', 'scope'], 'maint_evf_scope_uq');
		}

		if (!$schema->hasTable('maint_ev_shares')) {
			$t = $schema->createTable('maint_ev_shares');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('member_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('share_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('permissions', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addUniqueIndex(['workspace_id', 'member_uid'], 'maint_evs_member_uq');
			$t->addIndex(['member_uid'], 'maint_evs_uid_idx');
		}

		return $schema;
	}
}
