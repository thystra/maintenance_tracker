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
 * v0.1.10 store and vendor links. A link belongs to one canonical part or one
 * vendor; the URL is validated https-only and is never fetched server-side.
 */
#[CreateTable(table: 'maint_links', columns: ['id','workspace_id','uuid','subject_type','subject_id','label','url','source','revision','created_at','updated_at','deleted_at'], description: 'Validated https store/vendor links for parts and vendors')]
final class Version1104Date20261016040000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_links')) {
			$t = $schema->createTable('maint_links');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('subject_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('subject_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('url', Types::STRING, ['notnull' => true, 'length' => 2048]);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addUniqueIndex(['uuid'], 'maint_link_uuid_uq');
			$t->addIndex(['workspace_id', 'subject_type', 'subject_id'], 'maint_link_subject_idx');
		}

		return $schema;
	}
}
