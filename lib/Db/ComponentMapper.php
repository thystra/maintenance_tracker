<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\MaintenanceTracker\Db;
use OCP\AppFramework\Db\QBMapper;use OCP\DB\QueryBuilder\IQueryBuilder;use OCP\IDBConnection;
/** @extends QBMapper<Component> */
final class ComponentMapper extends QBMapper{
 public function __construct(IDBConnection $db){parent::__construct($db,'maint_components',Component::class);}
 /** @return list<Component> */ public function findForAsset(int $ws,int $assetId,bool $includeDeleted=false):array{$q=$this->db->getQueryBuilder();$q->select('*')->from('maint_components')->where($q->expr()->eq('workspace_id',$q->createNamedParameter($ws,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('asset_id',$q->createNamedParameter($assetId,IQueryBuilder::PARAM_INT)))->orderBy('id','ASC');if(!$includeDeleted)$q->andWhere($q->expr()->isNull('deleted_at'));return $this->findEntities($q);}
 public function findByUuid(int $ws,string $uuid,bool $includeDeleted=false):Component{$q=$this->db->getQueryBuilder();$q->select('*')->from('maint_components')->where($q->expr()->eq('workspace_id',$q->createNamedParameter($ws,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('uuid',$q->createNamedParameter($uuid,IQueryBuilder::PARAM_STR)));if(!$includeDeleted)$q->andWhere($q->expr()->isNull('deleted_at'));return $this->findEntity($q);}
}
