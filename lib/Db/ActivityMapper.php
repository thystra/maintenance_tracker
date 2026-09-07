<?php

declare(strict_types=1);
namespace OCA\MaintenanceTracker\Db;
use OCP\AppFramework\Db\QBMapper; use OCP\DB\QueryBuilder\IQueryBuilder; use OCP\DB\QueryBuilder\IParameter; use OCP\IDBConnection;
/** @extends QBMapper<Activity> */
final class ActivityMapper extends QBMapper {
 public function __construct(IDBConnection $db){parent::__construct($db,'maint_activities',Activity::class);}
 /** @return list<Activity> */ public function findForAsset(int $ws,int $assetId,bool $includeDeleted=false):array{$q=$this->db->getQueryBuilder();$q->select('*')->from('maint_activities')->where($q->expr()->eq('workspace_id',$q->createNamedParameter($ws,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('asset_id',$q->createNamedParameter($assetId,IQueryBuilder::PARAM_INT)))->orderBy('performed_at','DESC')->addOrderBy('id','DESC');if(!$includeDeleted)$q->andWhere($q->expr()->isNull('deleted_at'));return $this->findEntities($q);}
 public function findByUuid(int $ws,string $uuid,bool $includeDeleted=false):Activity{$q=$this->db->getQueryBuilder();$q->select('*')->from('maint_activities')->where($q->expr()->eq('workspace_id',$q->createNamedParameter($ws,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('uuid',$q->createNamedParameter($uuid,IQueryBuilder::PARAM_STR)));if(!$includeDeleted)$q->andWhere($q->expr()->isNull('deleted_at'));return $this->findEntity($q);}
 public function updateWithExpectedRevision(Activity $a,int $expected):bool{$q=$this->db->getQueryBuilder();$q->update('maint_activities')->set('summary',$this->str($q,$a->getSummary()))->set('notes',$this->str($q,$a->getNotes()))->set('revision',$q->createNamedParameter($a->getRevision(),IQueryBuilder::PARAM_INT))->set('updated_at',$q->createNamedParameter($a->getUpdatedAt(),IQueryBuilder::PARAM_INT))->set('deleted_at',$this->num($q,$a->getDeletedAt()))->where($q->expr()->eq('id',$q->createNamedParameter($a->getId(),IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('workspace_id',$q->createNamedParameter($a->getWorkspaceId(),IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('revision',$q->createNamedParameter($expected,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->isNull('deleted_at'));return $q->executeStatement()===1;}
 private function str(IQueryBuilder $q,?string $v):IParameter{return $q->createNamedParameter($v,$v===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR);} private function num(IQueryBuilder $q,?int $v):IParameter{return $q->createNamedParameter($v,$v===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_INT);}
}
