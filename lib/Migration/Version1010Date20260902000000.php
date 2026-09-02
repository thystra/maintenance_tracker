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
	table: 'maint_categories',
	columns: ['id', 'workspace_id', 'uuid', 'category_key', 'name', 'default_class', 'description', 'revision', 'created_at', 'updated_at', 'deleted_at'],
	description: 'Workspace-defined asset categories',
)]
#[CreateTable(
	table: 'maint_components',
	columns: ['id', 'workspace_id', 'asset_id', 'parent_id', 'uuid', 'type_key', 'name', 'manufacturer', 'model', 'part_number', 'serial_number', 'notes', 'status', 'revision', 'created_at', 'updated_at', 'deleted_at'],
	description: 'Maintainable component instances',
)]
#[CreateTable(
	table: 'maint_specs',
	columns: ['id', 'workspace_id', 'asset_id', 'component_id', 'uuid', 'spec_key', 'label', 'value_json', 'unit', 'regime_key', 'source_type', 'source_ref', 'revision', 'created_at', 'updated_at', 'deleted_at'],
	description: 'Structured asset and component specifications',
)]
final class Version1010Date20260902000000 extends SimpleMigrationStep {
	private const BUILTIN_CATEGORIES = [
		'vehicle',
		'home',
		'tool',
		'health',
		'outdoor',
		'other',
	];

	public function __construct(private IDBConnection $db) {
	}

	public function changeSchema(
		IOutput $output,
		Closure $schemaClosure,
		array $options,
	): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('maint_assets')) {
			$assets = $schema->getTable('maint_assets');
			if (!$assets->hasColumn('asset_class')) {
				$assets->addColumn('asset_class', Types::STRING, [
					'notnull' => true,
					'length' => 32,
					'default' => 'other',
				]);
				$assets->addIndex(['workspace_id', 'asset_class'], 'maint_asset_class_idx');
			}
		}

		if (!$schema->hasTable('maint_categories')) {
			$table = $schema->createTable('maint_categories');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('category_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 120]);
			$table->addColumn('default_class', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'other']);
			$table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$this->addTimestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_cat_uuid_uq');
			$table->addUniqueIndex(['workspace_id', 'category_key'], 'maint_cat_ws_key_uq');
			$table->addIndex(['workspace_id', 'deleted_at'], 'maint_cat_ws_idx');
		}

		if (!$schema->hasTable('maint_components')) {
			$table = $schema->createTable('maint_components');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('parent_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('type_key', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'component']);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('manufacturer', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$table->addColumn('model', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$table->addColumn('part_number', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$table->addColumn('serial_number', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$this->addTimestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_comp_uuid_uq');
			$table->addIndex(['workspace_id', 'asset_id', 'deleted_at'], 'maint_comp_asset_idx');
			$table->addIndex(['workspace_id', 'parent_id', 'deleted_at'], 'maint_comp_parent_idx');
		}

		if (!$schema->hasTable('maint_specs')) {
			$table = $schema->createTable('maint_specs');
			$this->addId($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('component_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('spec_key', Types::STRING, ['notnull' => true, 'length' => 160]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('value_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('unit', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			$table->addColumn('regime_key', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			$table->addColumn('source_type', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
			$table->addColumn('source_ref', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$this->addTimestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_spec_uuid_uq');
			$table->addIndex(['workspace_id', 'asset_id', 'deleted_at'], 'maint_spec_asset_idx');
			$table->addIndex(['workspace_id', 'component_id', 'deleted_at'], 'maint_spec_comp_idx');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->backfillAssetClasses();
		$this->backfillCustomCategories();
	}

	private function backfillAssetClasses(): void {
		$classes = [
			'vehicle' => 'vehicle',
			'home' => 'building',
			'tool' => 'tool',
			'health' => 'medical_device',
			'outdoor' => 'equipment',
			'other' => 'other',
		];

		foreach ($classes as $category => $class) {
			$query = $this->db->getQueryBuilder();
			$query->update('maint_assets')
				->set('asset_class', $query->createNamedParameter($class, IQueryBuilder::PARAM_STR))
				->where($query->expr()->eq('category_key', $query->createNamedParameter($category, IQueryBuilder::PARAM_STR)))
				->executeStatement();
		}
	}

	private function backfillCustomCategories(): void {
		$query = $this->db->getQueryBuilder();
		$query->selectDistinct('workspace_id')
			->addSelect('category_key')
			->from('maint_assets');
		$result = $query->executeQuery();
		try {
			while (($row = $result->fetch()) !== false) {
				$key = (string)$row['category_key'];
				if (in_array($key, self::BUILTIN_CATEGORIES, true)) {
					continue;
				}
				$this->insertCategoryIfMissing((int)$row['workspace_id'], $key);
			}
		} finally {
			$result->closeCursor();
		}
	}

	private function insertCategoryIfMissing(int $workspaceId, string $key): void {
		$query = $this->db->getQueryBuilder();
		$query->select('id')
			->from('maint_categories')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('category_key', $query->createNamedParameter($key, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);
		if ($query->executeQuery()->fetchOne() !== false) {
			return;
		}

		$now = time();
		$insert = $this->db->getQueryBuilder();
		$insert->insert('maint_categories')
			->values([
				'workspace_id' => $insert->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
				'uuid' => $insert->createNamedParameter($this->uuidV4(), IQueryBuilder::PARAM_STR),
				'category_key' => $insert->createNamedParameter($key, IQueryBuilder::PARAM_STR),
				'name' => $insert->createNamedParameter($this->humanizeKey($key), IQueryBuilder::PARAM_STR),
				'default_class' => $insert->createNamedParameter('other', IQueryBuilder::PARAM_STR),
				'description' => $insert->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'revision' => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'created_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'deleted_at' => $insert->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			])
			->executeStatement();
	}

	private function humanizeKey(string $key): string {
		$value = str_replace(['-', '_'], ' ', $key);
		return ucwords($value);
	}

	private function uuidV4(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$hex = bin2hex($bytes);
		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
			. '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
	}

	private function addId(mixed $table): void {
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
	}

	private function addTimestamps(mixed $table): void {
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
	}
}
