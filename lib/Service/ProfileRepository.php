<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class ProfileRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string,mixed>|null */
	public function findProfile(int $workspaceId, string $profileKey): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_profiles')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('profile_key', $q->createNamedParameter($profileKey, IQueryBuilder::PARAM_STR)));
		return $this->one($q);
	}

	public function insertProfile(int $workspaceId, string $profileKey, string $origin, string $trustState, int $createdAt): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_profiles')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'profile_key' => $q->createNamedParameter($profileKey, IQueryBuilder::PARAM_STR),
			'origin' => $q->createNamedParameter($origin, IQueryBuilder::PARAM_STR),
			'trust_state' => $q->createNamedParameter($trustState, IQueryBuilder::PARAM_STR),
			'created_at' => $q->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
		]);
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/** @return array<string,mixed>|null */
	public function findRevision(int $workspaceId, int $profileId, string $version): ?array {
		$q=$this->db->getQueryBuilder();$q->select('*')->from('maint_prof_revs')
			->where($q->expr()->eq('workspace_id',$q->createNamedParameter($workspaceId,IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('profile_id',$q->createNamedParameter($profileId,IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('profile_version',$q->createNamedParameter($version,IQueryBuilder::PARAM_STR)));
		return $this->one($q);
	}

	/** @param array<string,mixed> $profile */
	public function insertRevision(int $workspaceId,int $profileId,array $profile,string $contentHash,string $canonicalJson,int $createdAt):int{
		$q=$this->db->getQueryBuilder();$sourceRevision=$profile['provenance']['sourceRevision']??null;
		$q->insert('maint_prof_revs')->values([
			'workspace_id'=>$q->createNamedParameter($workspaceId,IQueryBuilder::PARAM_INT),
			'profile_id'=>$q->createNamedParameter($profileId,IQueryBuilder::PARAM_INT),
			'profile_version'=>$q->createNamedParameter($profile['version'],IQueryBuilder::PARAM_STR),
			'content_hash'=>$q->createNamedParameter($contentHash,IQueryBuilder::PARAM_STR),
			'content_json'=>$q->createNamedParameter($canonicalJson,IQueryBuilder::PARAM_STR),
			'data_license'=>$q->createNamedParameter($profile['dataLicense'],IQueryBuilder::PARAM_STR),
			'author_name'=>$q->createNamedParameter($profile['provenance']['author'],IQueryBuilder::PARAM_STR),
			'source_url'=>$q->createNamedParameter($profile['provenance']['sourceUrl'],IQueryBuilder::PARAM_STR),
			'source_revision'=>$q->createNamedParameter($sourceRevision,$sourceRevision===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR),
			'created_at'=>$q->createNamedParameter($createdAt,IQueryBuilder::PARAM_INT),
		]);$q->executeStatement();return $q->getLastInsertId();
	}

	/** @return array<string,mixed>|null */
	public function findInstallationForAsset(int $workspaceId,int $assetId):?array{$q=$this->db->getQueryBuilder();$q->select('ap.*','p.profile_key','p.origin','p.trust_state','pr.profile_version','pr.content_hash','pr.data_license','pr.author_name','pr.source_url','pr.source_revision')->from('maint_asset_prof','ap')->innerJoin('ap','maint_prof_revs','pr',$q->expr()->eq('ap.profile_revision_id','pr.id'))->innerJoin('pr','maint_profiles','p',$q->expr()->eq('pr.profile_id','p.id'))->where($q->expr()->eq('ap.workspace_id',$q->createNamedParameter($workspaceId,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('ap.asset_id',$q->createNamedParameter($assetId,IQueryBuilder::PARAM_INT)));return $this->one($q);}
	/** @return array<string,mixed>|null */
	public function findInstallationByUuid(int $workspaceId,string $uuid):?array{$q=$this->db->getQueryBuilder();$q->select('ap.*','p.profile_key','p.origin','p.trust_state','pr.profile_version','pr.content_hash','pr.data_license','pr.author_name','pr.source_url','pr.source_revision')->from('maint_asset_prof','ap')->innerJoin('ap','maint_prof_revs','pr',$q->expr()->eq('ap.profile_revision_id','pr.id'))->innerJoin('pr','maint_profiles','p',$q->expr()->eq('pr.profile_id','p.id'))->where($q->expr()->eq('ap.workspace_id',$q->createNamedParameter($workspaceId,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('ap.installation_uuid',$q->createNamedParameter($uuid,IQueryBuilder::PARAM_STR)));return $this->one($q);}
	public function insertInstallation(int $workspaceId,int $assetId,int $revisionId,string $uuid,int $installedAt):int{$q=$this->db->getQueryBuilder();$q->insert('maint_asset_prof')->values(['workspace_id'=>$q->createNamedParameter($workspaceId,IQueryBuilder::PARAM_INT),'asset_id'=>$q->createNamedParameter($assetId,IQueryBuilder::PARAM_INT),'profile_revision_id'=>$q->createNamedParameter($revisionId,IQueryBuilder::PARAM_INT),'installation_uuid'=>$q->createNamedParameter($uuid,IQueryBuilder::PARAM_STR),'installed_at'=>$q->createNamedParameter($installedAt,IQueryBuilder::PARAM_INT)]);$q->executeStatement();return $q->getLastInsertId();}
	public function insertBinding(int $workspaceId,int $assetProfileId,string $sourceType,string $sourceKey,int $ordinal,string $targetUuid):void{$q=$this->db->getQueryBuilder();$q->insert('maint_prof_bind')->values(['workspace_id'=>$q->createNamedParameter($workspaceId,IQueryBuilder::PARAM_INT),'asset_profile_id'=>$q->createNamedParameter($assetProfileId,IQueryBuilder::PARAM_INT),'source_type'=>$q->createNamedParameter($sourceType,IQueryBuilder::PARAM_STR),'source_key'=>$q->createNamedParameter($sourceKey,IQueryBuilder::PARAM_STR),'source_ordinal'=>$q->createNamedParameter($ordinal,IQueryBuilder::PARAM_INT),'target_uuid'=>$q->createNamedParameter($targetUuid,IQueryBuilder::PARAM_STR)]);$q->executeStatement();}
	/** @return list<array<string,mixed>> */
	public function findBindings(int $workspaceId,int $assetProfileId):array{$q=$this->db->getQueryBuilder();$q->select('*')->from('maint_prof_bind')->where($q->expr()->eq('workspace_id',$q->createNamedParameter($workspaceId,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('asset_profile_id',$q->createNamedParameter($assetProfileId,IQueryBuilder::PARAM_INT)))->orderBy('source_type','ASC')->addOrderBy('source_key','ASC')->addOrderBy('source_ordinal','ASC');$r=$q->executeQuery();try{return $r->fetchAllAssociative();}finally{$r->closeCursor();}}

	/** @return array<string,mixed>|null */
	private function one(IQueryBuilder $q):?array{$r=$q->executeQuery();try{$row=$r->fetchAssociative();return $row===false?null:$row;}finally{$r->closeCursor();}}
}
