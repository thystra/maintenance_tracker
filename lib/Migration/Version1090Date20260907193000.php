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

#[CreateTable(table: 'maint_fit_packs', columns: ['id','workspace_id','pack_key','created_at'], description: 'Workspace fitment-pack identities')]
#[CreateTable(table: 'maint_fit_revs', columns: ['id','workspace_id','pack_id','pack_version','content_hash','content_json','data_license','author_name','source_url','source_revision','created_at'], description: 'Immutable validated fitment-pack revision snapshots')]
#[CreateTable(table: 'maint_fit_imports', columns: ['id','workspace_id','revision_id','import_uuid','imported_at'], description: 'Idempotent fitment-pack import operations')]
#[CreateTable(table: 'maint_fit_bind', columns: ['id','workspace_id','import_id','source_type','source_key','source_ordinal','target_uuid'], description: 'Fitment-pack source keys bound to canonical records')]
#[CreateTable(table: 'maint_fit_targets', columns: ['id','workspace_id','import_id','uuid','source_key','asset_class','manufacturer','model','year_from','year_to','aliases_json','identifiers_json','qualifiers_json','created_at'], description: 'Portable imported equipment descriptors')]
#[CreateTable(table: 'maint_fit_slots', columns: ['id','workspace_id','uuid','slot_key','label','kind','revision','created_at','updated_at','deleted_at'], description: 'Canonical standardized fitment slots')]
#[CreateTable(table: 'maint_parts', columns: ['id','workspace_id','uuid','identity_hash','manufacturer','part_number','description','revision','created_at','updated_at','deleted_at'], description: 'Canonical workspace part catalog')]
#[CreateTable(table: 'maint_offers', columns: ['id','workspace_id','import_id','part_id','uuid','source_key','label','sku','url','created_at'], description: 'Imported source-specific product offers')]
#[CreateTable(table: 'maint_fitments', columns: ['id','workspace_id','import_id','target_id','slot_id','part_id','uuid','relation','verification','evidence_json','notes','created_at'], description: 'Source-specific fitment assertions')]
#[CreateTable(table: 'maint_asset_fit', columns: ['id','workspace_id','uuid','target_id','asset_id','match_state','conflict_override','active_marker','mapped_at'], description: 'Explicit imported-target to local-asset mappings')]
final class Version1090Date20260907193000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('maint_fit_packs')) {
			$table = $schema->createTable('maint_fit_packs');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('pack_key', Types::STRING, ['notnull' => true, 'length' => 160]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['workspace_id','pack_key'], 'maint_fpack_ws_key_uq');
		}

		if (!$schema->hasTable('maint_fit_revs')) {
			$table = $schema->createTable('maint_fit_revs');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('pack_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('pack_version', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('content_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('content_json', Types::TEXT, ['notnull' => true, 'length' => 8388608]);
			$table->addColumn('data_license', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('author_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('source_url', Types::STRING, ['notnull' => true, 'length' => 2048]);
			$table->addColumn('source_revision', Types::STRING, ['notnull' => false, 'length' => 160, 'default' => null]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['workspace_id','pack_id','pack_version'], 'maint_frev_ver_uq');
			$table->addUniqueIndex(['workspace_id','pack_id','content_hash'], 'maint_frev_hash_uq');
		}

		if (!$schema->hasTable('maint_fit_imports')) {
			$table = $schema->createTable('maint_fit_imports');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('revision_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('import_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('imported_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['workspace_id','import_uuid'], 'maint_fimp_uuid_uq');
			$table->addUniqueIndex(['workspace_id','revision_id'], 'maint_fimp_rev_uq');
		}

		if (!$schema->hasTable('maint_fit_bind')) {
			$table = $schema->createTable('maint_fit_bind');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('import_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('source_type', Types::STRING, ['notnull' => true, 'length' => 24]);
			$table->addColumn('source_key', Types::STRING, ['notnull' => true, 'length' => 160]);
			$table->addColumn('source_ordinal', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('target_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addUniqueIndex(['workspace_id','import_id','source_type','source_key','source_ordinal'], 'maint_fbind_src_uq');
			$table->addUniqueIndex(['workspace_id','import_id','target_uuid'], 'maint_fbind_tgt_uq');
		}

		if (!$schema->hasTable('maint_fit_targets')) {
			$table = $schema->createTable('maint_fit_targets');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('import_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('source_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('asset_class', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('manufacturer', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('model', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('year_from', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('year_to', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('aliases_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('identifiers_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('qualifiers_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['uuid'], 'maint_ftgt_uuid_uq');
			$table->addUniqueIndex(['workspace_id','import_id','source_key'], 'maint_ftgt_src_uq');
			$table->addIndex(['workspace_id','import_id'], 'maint_ftgt_imp_idx');
		}

		if (!$schema->hasTable('maint_fit_slots')) {
			$table = $schema->createTable('maint_fit_slots');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('slot_key', Types::STRING, ['notnull' => true, 'length' => 160]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$this->timestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_fslot_uuid_uq');
			$table->addUniqueIndex(['workspace_id','slot_key'], 'maint_fslot_key_uq');
		}

		if (!$schema->hasTable('maint_parts')) {
			$table = $schema->createTable('maint_parts');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('identity_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('manufacturer', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('part_number', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('description', Types::STRING, ['notnull' => true, 'length' => 1000]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$this->timestamps($table);
			$table->addUniqueIndex(['uuid'], 'maint_part_uuid_uq');
			$table->addUniqueIndex(['workspace_id','identity_hash'], 'maint_part_ident_uq');
		}

		if (!$schema->hasTable('maint_offers')) {
			$table = $schema->createTable('maint_offers');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('import_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('part_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('source_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('sku', Types::STRING, ['notnull' => false, 'length' => 128, 'default' => null]);
			$table->addColumn('url', Types::STRING, ['notnull' => true, 'length' => 2048]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['uuid'], 'maint_offer_uuid_uq');
			$table->addUniqueIndex(['workspace_id','import_id','source_key'], 'maint_offer_src_uq');
			$table->addIndex(['workspace_id','part_id'], 'maint_offer_part_idx');
		}

		if (!$schema->hasTable('maint_fitments')) {
			$table = $schema->createTable('maint_fitments');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('import_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('target_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('slot_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('part_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('relation', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('verification', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('evidence_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['uuid'], 'maint_fit_uuid_uq');
			$table->addUniqueIndex(['workspace_id','import_id','target_id','slot_id','part_id'], 'maint_fit_fact_uq');
			$table->addIndex(['workspace_id','target_id'], 'maint_fit_tgt_idx');
		}

		if (!$schema->hasTable('maint_asset_fit')) {
			$table = $schema->createTable('maint_asset_fit');
			$this->id($table);
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('target_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('asset_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('match_state', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('conflict_override', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('active_marker', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => 'active']);
			$table->addColumn('mapped_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addUniqueIndex(['uuid'], 'maint_afit_uuid_uq');
			$table->addUniqueIndex(['workspace_id','target_id','active_marker'], 'maint_afit_tgt_uq');
			$table->addIndex(['workspace_id','asset_id','active_marker'], 'maint_afit_asset_idx');
		}

		return $schema;
	}

	private function id(mixed $table): void {
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
	}

	private function timestamps(mixed $table): void {
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
	}
}
