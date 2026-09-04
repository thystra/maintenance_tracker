<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

#[CreateTable(
	table: 'maint_audit',
	columns: [
		'id', 'workspace_id', 'event_type', 'event_version', 'actor_uid',
		'subject_type', 'subject_id', 'subject_revision', 'level',
		'details_json', 'created_at',
	],
	description: 'Append-only security and domain audit events',
)]
final class Version1030Date20260904000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(
		IOutput $output,
		Closure $schemaClosure,
		array $options,
	): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('maint_audit')) {
			$table = $schema->createTable('maint_audit');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('event_type', Types::STRING, ['notnull' => true, 'length' => 96]);
			$table->addColumn('event_version', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('subject_type', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('subject_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('subject_revision', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
				'default' => null,
			]);
			$table->addColumn('level', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('details_json', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addIndex(['workspace_id', 'id'], 'maint_audit_space_idx');
			$table->addIndex(['workspace_id', 'actor_uid', 'id'], 'maint_audit_actor_idx');
		}

		return $schema;
	}

	public function postSchemaChange(
		IOutput $output,
		Closure $schemaClosure,
		array $options,
	): void {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_members')
			->set(
				'role',
				$query->createNamedParameter('manager', IQueryBuilder::PARAM_STR),
			)
			->where($query->expr()->eq(
				'role',
				$query->createNamedParameter('editor', IQueryBuilder::PARAM_STR),
			));
		$query->executeStatement();
	}
}
