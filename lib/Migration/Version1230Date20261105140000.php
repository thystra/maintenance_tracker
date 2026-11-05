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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Optional next-due override on work definitions with no logged completion.
 *
 * next_due_on is a UTC calendar date (Y-m-d) for time-based schedules.
 * next_due_canonical is the meter threshold in the meter's canonical unit
 * for meter-based schedules. Both stay null for existing rows; due logic
 * ignores them once a completion activity exists.
 */
#[AddColumn(table: 'maint_work_defs', name: 'next_due_on', type: ColumnType::STRING, description: 'Optional next-due calendar date when no completion is logged')]
#[AddColumn(table: 'maint_work_defs', name: 'next_due_canonical', type: ColumnType::BIGINT, description: 'Optional next-due meter canonical value when no completion is logged')]
final class Version1230Date20261105140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('maint_work_defs')) {
			return null;
		}
		$table = $schema->getTable('maint_work_defs');
		$changed = false;
		if (!$table->hasColumn('next_due_on')) {
			$table->addColumn('next_due_on', Types::STRING, ['notnull' => false, 'length' => 10, 'default' => null]);
			$changed = true;
		}
		if (!$table->hasColumn('next_due_canonical')) {
			$table->addColumn('next_due_canonical', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
