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
 * Item groups: workspace-scoped, hierarchical organisational containers
 * (for example "Vehicles > Fleet 1" or "House > Basement"). Groups form a
 * tree through a nullable parent id; cycles are prevented by the service.
 * Membership lives in a separate link table with one row per asset (unique),
 * so an asset belongs to at most one group and moving it never rewrites the
 * asset row or its revision.
 */
#[CreateTable(table: 'maint_item_groups', columns: ['id','workspace_id','uuid','parent_id','name','sort_order','revision','created_at','updated_at','deleted_at'], description: 'Hierarchical item groups (organisational containers for assets)')]
#[CreateTable(table: 'maint_asset_grp', columns: ['id','workspace_id','asset_id','group_id','revision','updated_at'], description: 'Asset membership in an item group (at most one group per asset)')]
final class Version1150Date20261018120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('maint_item_groups')) {
			$t = $schema->createTable('maint_item_groups');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('parent_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addUniqueIndex(['uuid'], 'maint_igrp_uuid_uq');
			$t->addIndex(['workspace_id', 'parent_id'], 'maint_igrp_parent_idx');
			$changed = true;
		}

		if (!$schema->hasTable('maint_asset_grp')) {
			$t = $schema->createTable('maint_asset_grp');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('group_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addUniqueIndex(['asset_id'], 'maint_agrp_asset_uq');
			$t->addIndex(['workspace_id', 'group_id'], 'maint_agrp_group_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
