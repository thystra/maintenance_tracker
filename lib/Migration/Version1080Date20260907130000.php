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

#[CreateTable(table: 'maint_profiles', columns: ['id','workspace_id','profile_key','origin','trust_state','created_at'], description: 'Workspace profile identities')]
#[CreateTable(table: 'maint_prof_revs', columns: ['id','workspace_id','profile_id','profile_version','content_hash','content_json','data_license','author_name','source_url','source_revision','created_at'], description: 'Immutable validated profile revision snapshots')]
#[CreateTable(table: 'maint_asset_prof', columns: ['id','workspace_id','asset_id','profile_revision_id','installation_uuid','installed_at'], description: 'Profile revision materialized into an asset')]
#[CreateTable(table: 'maint_prof_bind', columns: ['id','workspace_id','asset_profile_id','source_type','source_key','source_ordinal','target_uuid'], description: 'Profile source keys bound to materialized domain records')]
final class Version1080Date20260907130000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_profiles')) {
			$table = $schema->createTable('maint_profiles');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('profile_key', Types::STRING, ['notnull' => true, 'length' => 160]);
			$table->addColumn('origin', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('trust_state', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['workspace_id','profile_key'], 'maint_prof_ws_key_uq');
		}

		if (!$schema->hasTable('maint_prof_revs')) {
			$table = $schema->createTable('maint_prof_revs');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('profile_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('profile_version', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('content_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('content_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('data_license', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('author_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('source_url', Types::STRING, ['notnull' => true, 'length' => 2048]);
			$table->addColumn('source_revision', Types::STRING, ['notnull' => false, 'length' => 160, 'default' => null]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['workspace_id','profile_id','profile_version'], 'maint_prev_ver_uq');
			$table->addUniqueIndex(['workspace_id','profile_id','content_hash'], 'maint_prev_hash_uq');
		}

		if (!$schema->hasTable('maint_asset_prof')) {
			$table = $schema->createTable('maint_asset_prof');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('profile_revision_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('installation_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('installed_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['workspace_id','asset_id'], 'maint_aprof_asset_uq');
			$table->addUniqueIndex(['workspace_id','installation_uuid'], 'maint_aprof_uuid_uq');
		}

		if (!$schema->hasTable('maint_prof_bind')) {
			$table = $schema->createTable('maint_prof_bind');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('asset_profile_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('source_type', Types::STRING, ['notnull' => true, 'length' => 24]);
			$table->addColumn('source_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('source_ordinal', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('target_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addUniqueIndex(['workspace_id','asset_profile_id','source_type','source_key','source_ordinal'], 'maint_pbind_src_uq');
			$table->addUniqueIndex(['workspace_id','asset_profile_id','target_uuid'], 'maint_pbind_tgt_uq');
		}

		return $schema;
	}

	private function id(mixed $table): void {
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
	}
}
