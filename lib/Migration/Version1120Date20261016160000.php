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
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Integration delivery bookkeeping for Activity, notifications and calendar
 * projection. Neither table stores maintenance truth: the reminder log only
 * records which (definition, completion baseline, attention level) a member
 * was already notified about, and the calendar log records which projected
 * event was already written to which user calendar, so delivery stays
 * idempotent. Due dates and states remain derived.
 *
 * The audit-projection cursor starts at the current end of the append-only
 * audit trail so an upgrade does not replay history into the activity stream.
 */
#[CreateTable(table: 'maint_notify_log', columns: ['id','workspace_id','user_uid','definition_uuid','baseline_uuid','level','created_at','cleared_at'], description: 'Maintenance reminder notifications already delivered per member')]
#[CreateTable(table: 'maint_cal_events', columns: ['id','workspace_id','user_uid','calendar_uri','definition_uuid','baseline_uuid','event_uid','due_on','created_at'], description: 'Projected calendar events already written to a member calendar')]
final class Version1120Date20261016160000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_notify_log')) {
			$t = $schema->createTable('maint_notify_log');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('user_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('definition_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('baseline_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('level', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('cleared_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$t->addUniqueIndex(['workspace_id', 'user_uid', 'definition_uuid', 'baseline_uuid', 'level'], 'maint_ntf_uq');
			$t->addIndex(['workspace_id', 'definition_uuid'], 'maint_ntf_def_idx');
			$t->addIndex(['user_uid'], 'maint_ntf_user_idx');
		}

		if (!$schema->hasTable('maint_cal_events')) {
			$t = $schema->createTable('maint_cal_events');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('user_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('calendar_uri', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('definition_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('baseline_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('event_uid', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('due_on', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addUniqueIndex(['workspace_id', 'user_uid', 'calendar_uri', 'definition_uuid', 'baseline_uuid'], 'maint_cal_uq');
			$t->addIndex(['user_uid'], 'maint_cal_user_idx');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->appConfig->hasKey('maintenance_tracker', 'audit_projection_cursor')) {
			return;
		}
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->max('id'))->from('maint_audit');
		$result = $query->executeQuery();
		$max = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		$this->appConfig->setValueInt('maintenance_tracker', 'audit_projection_cursor', $max);
	}
}
