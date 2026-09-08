<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class FitmentRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string,mixed>|null */
	public function findPack(int $workspaceId, string $packKey): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_fit_packs')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('pack_key', $q->createNamedParameter($packKey, IQueryBuilder::PARAM_STR)));
		return $this->one($q);
	}

	public function insertPack(int $workspaceId, string $packKey, int $createdAt): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_fit_packs')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'pack_key' => $q->createNamedParameter($packKey, IQueryBuilder::PARAM_STR),
			'created_at' => $q->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/** @return array<string,mixed>|null */
	public function findRevision(int $workspaceId, int $packId, string $version): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_fit_revs')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('pack_id', $q->createNamedParameter($packId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('pack_version', $q->createNamedParameter($version, IQueryBuilder::PARAM_STR)));
		return $this->one($q);
	}

	/** @param array<string,mixed> $pack */
	public function insertRevision(int $workspaceId, int $packId, array $pack, string $hash, string $json, int $createdAt): int {
		$q = $this->db->getQueryBuilder();
		$sourceRevision = $pack['provenance']['sourceRevision'] ?? null;
		$q->insert('maint_fit_revs')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'pack_id' => $q->createNamedParameter($packId, IQueryBuilder::PARAM_INT),
			'pack_version' => $q->createNamedParameter($pack['version'], IQueryBuilder::PARAM_STR),
			'content_hash' => $q->createNamedParameter($hash, IQueryBuilder::PARAM_STR),
			'content_json' => $q->createNamedParameter($json, IQueryBuilder::PARAM_STR),
			'data_license' => $q->createNamedParameter($pack['dataLicense'], IQueryBuilder::PARAM_STR),
			'author_name' => $q->createNamedParameter($pack['provenance']['author'], IQueryBuilder::PARAM_STR),
			'source_url' => $q->createNamedParameter($pack['provenance']['sourceUrl'], IQueryBuilder::PARAM_STR),
			'source_revision' => $this->nullableString($q, is_string($sourceRevision) ? $sourceRevision : null),
			'created_at' => $q->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/** @return array<string,mixed>|null */
	public function findImportByUuid(int $workspaceId, string $uuid): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('i.*','r.pack_id','r.pack_version','r.content_hash','r.content_json','r.data_license','r.author_name','r.source_url','r.source_revision','p.pack_key')
			->from('maint_fit_imports', 'i')
			->innerJoin('i', 'maint_fit_revs', 'r', $q->expr()->eq('i.revision_id', 'r.id'))
			->innerJoin('r', 'maint_fit_packs', 'p', $q->expr()->eq('r.pack_id', 'p.id'))
			->where($q->expr()->eq('i.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('i.import_uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		return $this->one($q);
	}

	/** @return array<string,mixed>|null */
	public function findImportByRevision(int $workspaceId, int $revisionId): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_fit_imports')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('revision_id', $q->createNamedParameter($revisionId, IQueryBuilder::PARAM_INT)));
		return $this->one($q);
	}

	public function insertImport(int $workspaceId, int $revisionId, string $uuid, int $importedAt): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_fit_imports')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'revision_id' => $q->createNamedParameter($revisionId, IQueryBuilder::PARAM_INT),
			'import_uuid' => $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			'imported_at' => $q->createNamedParameter($importedAt, IQueryBuilder::PARAM_INT),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	public function insertBinding(int $workspaceId, int $importId, string $sourceType, string $sourceKey, int $ordinal, string $targetUuid): void {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_fit_bind')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'import_id' => $q->createNamedParameter($importId, IQueryBuilder::PARAM_INT),
			'source_type' => $q->createNamedParameter($sourceType, IQueryBuilder::PARAM_STR),
			'source_key' => $q->createNamedParameter($sourceKey, IQueryBuilder::PARAM_STR),
			'source_ordinal' => $q->createNamedParameter($ordinal, IQueryBuilder::PARAM_INT),
			'target_uuid' => $q->createNamedParameter($targetUuid, IQueryBuilder::PARAM_STR),
		]);
		$q->executeStatement();
	}

	/** @param array<string,mixed> $target */
	public function insertTarget(int $workspaceId, int $importId, string $uuid, array $target, string $aliasesJson, string $identifiersJson, string $qualifiersJson, int $createdAt): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_fit_targets')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'import_id' => $q->createNamedParameter($importId, IQueryBuilder::PARAM_INT),
			'uuid' => $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			'source_key' => $q->createNamedParameter($target['key'], IQueryBuilder::PARAM_STR),
			'asset_class' => $q->createNamedParameter($target['assetClass'], IQueryBuilder::PARAM_STR),
			'manufacturer' => $q->createNamedParameter($target['manufacturer'], IQueryBuilder::PARAM_STR),
			'model' => $q->createNamedParameter($target['model'], IQueryBuilder::PARAM_STR),
			'year_from' => $this->nullableInt($q, $target['yearFrom'] ?? null),
			'year_to' => $this->nullableInt($q, $target['yearTo'] ?? null),
			'aliases_json' => $q->createNamedParameter($aliasesJson, IQueryBuilder::PARAM_STR),
			'identifiers_json' => $q->createNamedParameter($identifiersJson, IQueryBuilder::PARAM_STR),
			'qualifiers_json' => $q->createNamedParameter($qualifiersJson, IQueryBuilder::PARAM_STR),
			'created_at' => $q->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/** @return list<array<string,mixed>> */
	public function listTargetsForImport(int $workspaceId, int $importId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_fit_targets')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('import_id', $q->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
			->orderBy('source_key', 'ASC');
		return $this->many($q);
	}

	/** @return array<string,mixed>|null */
	public function findTargetByUuid(int $workspaceId, string $uuid): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('t.*','i.import_uuid','r.pack_version','r.content_hash','p.pack_key')
			->from('maint_fit_targets', 't')
			->innerJoin('t', 'maint_fit_imports', 'i', $q->expr()->eq('t.import_id', 'i.id'))
			->innerJoin('i', 'maint_fit_revs', 'r', $q->expr()->eq('i.revision_id', 'r.id'))
			->innerJoin('r', 'maint_fit_packs', 'p', $q->expr()->eq('r.pack_id', 'p.id'))
			->where($q->expr()->eq('t.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('t.uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		return $this->one($q);
	}

	/** @return array<string,mixed>|null */
	public function findSlotByKey(int $workspaceId, string $slotKey): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_fit_slots')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('slot_key', $q->createNamedParameter($slotKey, IQueryBuilder::PARAM_STR)))
			->andWhere($q->expr()->isNull('deleted_at'));
		return $this->one($q);
	}

	public function insertSlot(int $workspaceId, string $uuid, string $key, string $label, string $kind, int $now): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_fit_slots')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'uuid' => $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			'slot_key' => $q->createNamedParameter($key, IQueryBuilder::PARAM_STR),
			'label' => $q->createNamedParameter($label, IQueryBuilder::PARAM_STR),
			'kind' => $q->createNamedParameter($kind, IQueryBuilder::PARAM_STR),
			'revision' => $q->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'deleted_at' => $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/** @return array<string,mixed>|null */
	public function findPartByIdentityHash(int $workspaceId, string $identityHash): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_parts')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('identity_hash', $q->createNamedParameter($identityHash, IQueryBuilder::PARAM_STR)))
			->andWhere($q->expr()->isNull('deleted_at'));
		return $this->one($q);
	}

	public function insertPart(int $workspaceId, string $uuid, string $identityHash, string $manufacturer, string $partNumber, string $description, int $now): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_parts')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'uuid' => $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			'identity_hash' => $q->createNamedParameter($identityHash, IQueryBuilder::PARAM_STR),
			'manufacturer' => $q->createNamedParameter($manufacturer, IQueryBuilder::PARAM_STR),
			'part_number' => $q->createNamedParameter($partNumber, IQueryBuilder::PARAM_STR),
			'description' => $q->createNamedParameter($description, IQueryBuilder::PARAM_STR),
			'revision' => $q->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'deleted_at' => $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	public function insertOffer(int $workspaceId, int $importId, int $partId, string $uuid, array $offer, int $now): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_offers')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'import_id' => $q->createNamedParameter($importId, IQueryBuilder::PARAM_INT),
			'part_id' => $q->createNamedParameter($partId, IQueryBuilder::PARAM_INT),
			'uuid' => $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			'source_key' => $q->createNamedParameter($offer['key'], IQueryBuilder::PARAM_STR),
			'label' => $q->createNamedParameter($offer['label'], IQueryBuilder::PARAM_STR),
			'sku' => $this->nullableString($q, $offer['sku'] ?? null),
			'url' => $q->createNamedParameter($offer['url'], IQueryBuilder::PARAM_STR),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	public function insertFitment(int $workspaceId, int $importId, int $targetId, int $slotId, int $partId, string $uuid, array $fitment, string $evidenceJson, int $now): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_fitments')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'import_id' => $q->createNamedParameter($importId, IQueryBuilder::PARAM_INT),
			'target_id' => $q->createNamedParameter($targetId, IQueryBuilder::PARAM_INT),
			'slot_id' => $q->createNamedParameter($slotId, IQueryBuilder::PARAM_INT),
			'part_id' => $q->createNamedParameter($partId, IQueryBuilder::PARAM_INT),
			'uuid' => $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			'relation' => $q->createNamedParameter($fitment['relation'], IQueryBuilder::PARAM_STR),
			'verification' => $q->createNamedParameter($fitment['verification'], IQueryBuilder::PARAM_STR),
			'evidence_json' => $q->createNamedParameter($evidenceJson, IQueryBuilder::PARAM_STR),
			'notes' => $this->nullableString($q, $fitment['notes'] ?? null),
			'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/** @return array<string,mixed>|null */
	public function findMappingByUuid(int $workspaceId, string $uuid): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_asset_fit')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		return $this->one($q);
	}

	/** @return array<string,mixed>|null */
	public function findActiveMappingForTarget(int $workspaceId, int $targetId): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_asset_fit')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('target_id', $q->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('active_marker', $q->createNamedParameter('active', IQueryBuilder::PARAM_STR)));
		return $this->one($q);
	}

	public function insertMapping(int $workspaceId, string $uuid, int $targetId, int $assetId, string $matchState, bool $conflictOverride, int $mappedAt): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_asset_fit')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'uuid' => $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR),
			'target_id' => $q->createNamedParameter($targetId, IQueryBuilder::PARAM_INT),
			'asset_id' => $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT),
			'match_state' => $q->createNamedParameter($matchState, IQueryBuilder::PARAM_STR),
			'conflict_override' => $q->createNamedParameter($conflictOverride, IQueryBuilder::PARAM_BOOL),
			'active_marker' => $q->createNamedParameter('active', IQueryBuilder::PARAM_STR),
			'mapped_at' => $q->createNamedParameter($mappedAt, IQueryBuilder::PARAM_INT),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/** @return list<array<string,mixed>> */
	public function findFitmentsForAsset(int $workspaceId, int $assetId): array {
		$q = $this->db->getQueryBuilder();
		$q->select(
			'f.uuid AS fitment_uuid','f.relation','f.verification','f.evidence_json','f.notes',
			't.uuid AS target_uuid','t.source_key AS equipment_key','t.manufacturer AS equipment_manufacturer','t.model AS equipment_model',
			's.uuid AS slot_uuid','s.slot_key','s.label AS slot_label','s.kind AS slot_kind',
			'p.uuid AS part_uuid','p.manufacturer AS part_manufacturer','p.part_number','p.description AS part_description',
			'i.import_uuid','r.pack_version','r.content_hash','pk.pack_key'
		)->from('maint_asset_fit', 'af')
			->innerJoin('af', 'maint_fit_targets', 't', $q->expr()->eq('af.target_id', 't.id'))
			->innerJoin('t', 'maint_fitments', 'f', $q->expr()->eq('f.target_id', 't.id'))
			->innerJoin('f', 'maint_fit_slots', 's', $q->expr()->eq('f.slot_id', 's.id'))
			->innerJoin('f', 'maint_parts', 'p', $q->expr()->eq('f.part_id', 'p.id'))
			->innerJoin('f', 'maint_fit_imports', 'i', $q->expr()->eq('f.import_id', 'i.id'))
			->innerJoin('i', 'maint_fit_revs', 'r', $q->expr()->eq('i.revision_id', 'r.id'))
			->innerJoin('r', 'maint_fit_packs', 'pk', $q->expr()->eq('r.pack_id', 'pk.id'))
			->where($q->expr()->eq('af.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('af.asset_id', $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('af.active_marker', $q->createNamedParameter('active', IQueryBuilder::PARAM_STR)))
			->andWhere($q->expr()->isNull('s.deleted_at'))
			->andWhere($q->expr()->isNull('p.deleted_at'))
			->orderBy('s.slot_key', 'ASC')
			->addOrderBy('p.manufacturer', 'ASC')
			->addOrderBy('p.part_number', 'ASC')
			->addOrderBy('f.id', 'ASC');
		return $this->many($q);
	}

	/** @return list<array<string,mixed>> */
	public function findFitmentsForTarget(int $workspaceId, int $targetId): array {
		$q = $this->db->getQueryBuilder();
		$q->select(
			'f.*','s.uuid AS slot_uuid','s.slot_key','s.label AS slot_label','s.kind AS slot_kind',
			'p.uuid AS part_uuid','p.manufacturer AS part_manufacturer','p.part_number','p.description AS part_description'
		)->from('maint_fitments', 'f')
			->innerJoin('f', 'maint_fit_slots', 's', $q->expr()->eq('f.slot_id', 's.id'))
			->innerJoin('f', 'maint_parts', 'p', $q->expr()->eq('f.part_id', 'p.id'))
			->where($q->expr()->eq('f.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('f.target_id', $q->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('s.deleted_at'))
			->andWhere($q->expr()->isNull('p.deleted_at'))
			->orderBy('s.slot_key', 'ASC')->addOrderBy('p.manufacturer', 'ASC')->addOrderBy('p.part_number', 'ASC');
		return $this->many($q);
	}

	/** @return array<string,mixed>|null */
	private function one(IQueryBuilder $q): ?array {
		$result = $q->executeQuery();
		try {
			$row = $result->fetchAssociative();
			return $row === false ? null : $row;
		} finally {
			$result->closeCursor();
		}
	}

	/** @return list<array<string,mixed>> */
	private function many(IQueryBuilder $q): array {
		$result = $q->executeQuery();
		try {
			return $result->fetchAllAssociative();
		} finally {
			$result->closeCursor();
		}
	}

	private function nullableString(IQueryBuilder $q, mixed $value): mixed {
		return $q->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR);
	}

	private function nullableInt(IQueryBuilder $q, mixed $value): mixed {
		return $q->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT);
	}
}
