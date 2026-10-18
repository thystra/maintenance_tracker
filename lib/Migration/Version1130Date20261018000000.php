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
 * Vendor contact details and part catalog extras (notes, preferred vendor,
 * typical price). All columns are nullable, so existing rows stay valid.
 */
#[AddColumn(table: 'maint_vendors', name: 'website', type: ColumnType::STRING, description: 'Vendor website (https URL, subject to the outside-links admin setting)')]
#[AddColumn(table: 'maint_vendors', name: 'address', type: ColumnType::TEXT, description: 'Vendor postal address')]
#[AddColumn(table: 'maint_vendors', name: 'phone', type: ColumnType::STRING, description: 'Vendor phone number')]
#[AddColumn(table: 'maint_vendors', name: 'email', type: ColumnType::STRING, description: 'Vendor email address')]
#[AddColumn(table: 'maint_vendors', name: 'contact_name', type: ColumnType::STRING, description: 'Point of contact at the vendor')]
#[AddColumn(table: 'maint_parts', name: 'notes', type: ColumnType::TEXT, description: 'Free-text part notes')]
#[AddColumn(table: 'maint_parts', name: 'vendor_id', type: ColumnType::BIGINT, description: 'Preferred vendor for the part')]
#[AddColumn(table: 'maint_parts', name: 'unit_price_minor', type: ColumnType::BIGINT, description: 'Typical unit price in integer minor units')]
#[AddColumn(table: 'maint_parts', name: 'currency', type: ColumnType::STRING, description: 'ISO 4217 currency of the typical unit price')]
final class Version1130Date20261018000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('maint_vendors')) {
			$t = $schema->getTable('maint_vendors');
			$columns = [
				'website' => [Types::STRING, ['notnull' => false, 'length' => 2048, 'default' => null]],
				'address' => [Types::TEXT, ['notnull' => false, 'default' => null]],
				'phone' => [Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]],
				'email' => [Types::STRING, ['notnull' => false, 'length' => 254, 'default' => null]],
				'contact_name' => [Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]],
			];
			foreach ($columns as $name => [$type, $opts]) {
				if (!$t->hasColumn($name)) {
					$t->addColumn($name, $type, $opts);
					$changed = true;
				}
			}
		}

		if ($schema->hasTable('maint_parts')) {
			$t = $schema->getTable('maint_parts');
			$columns = [
				'notes' => [Types::TEXT, ['notnull' => false, 'default' => null]],
				'vendor_id' => [Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]],
				'unit_price_minor' => [Types::BIGINT, ['notnull' => false, 'default' => null]],
				'currency' => [Types::STRING, ['notnull' => false, 'length' => 3, 'default' => null]],
			];
			foreach ($columns as $name => [$type, $opts]) {
				if (!$t->hasColumn($name)) {
					$t->addColumn($name, $type, $opts);
					$changed = true;
				}
			}
			if (!$t->hasIndex('maint_part_vendor_idx')) {
				$t->addIndex(['workspace_id', 'vendor_id'], 'maint_part_vendor_idx');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
