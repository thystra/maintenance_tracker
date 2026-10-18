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
 * Workspace profile library (profile builder).
 *
 * maint_ws_profiles holds editable maintenance profiles that belong to a
 * workspace: built in the profile editor, saved from an item, or imported
 * from a JSON file. content_json is always the validator's canonical form;
 * profile_key, profile_version, name and category are copies for listing.
 * Installing one still snapshots an immutable revision in maint_prof_revs.
 */
#[CreateTable(table: 'maint_ws_profiles', columns: ['id','workspace_id','uuid','profile_key','profile_version','name','category','content_hash','content_json','revision','created_at','updated_at','deleted_at'], description: 'Editable workspace maintenance profiles (profile library)')]
final class Version1170Date20261018220000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('maint_ws_profiles')) {
			return null;
		}
		$t = $schema->createTable('maint_ws_profiles');
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$t->setPrimaryKey(['id']);
		$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$t->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$t->addColumn('profile_key', Types::STRING, ['notnull' => true, 'length' => 160]);
		$t->addColumn('profile_version', Types::STRING, ['notnull' => true, 'length' => 32]);
		$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$t->addColumn('category', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('content_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('content_json', Types::TEXT, ['notnull' => true]);
		$t->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
		$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$t->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
		$t->addUniqueIndex(['uuid'], 'maint_wsprof_uuid_uq');
		$t->addIndex(['workspace_id', 'profile_key'], 'maint_wsprof_key_idx');
		return $schema;
	}
}
