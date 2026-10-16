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
 * Informational part compatibility for components and work definitions, as
 * supplied by profile-v2 `compatiblePartKeys`. Rows reference canonical parts;
 * there is no profile-only parts table.
 */
#[CreateTable(table: 'maint_part_compat', columns: ['id','workspace_id','asset_id','part_id','subject_type','subject_uuid','asset_profile_id','created_at'], description: 'Informational part compatibility for components and work definitions')]
final class Version1102Date20261016020000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_part_compat')) {
			$t = $schema->createTable('maint_part_compat');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('part_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('subject_type', Types::STRING, ['notnull' => true, 'length' => 24]);
			$t->addColumn('subject_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('asset_profile_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addUniqueIndex(['workspace_id', 'subject_type', 'subject_uuid', 'part_id'], 'maint_pcompat_fact_uq');
			$t->addIndex(['workspace_id', 'asset_id'], 'maint_pcompat_asset_idx');
			$t->addIndex(['workspace_id', 'part_id'], 'maint_pcompat_part_idx');
		}

		return $schema;
	}
}
