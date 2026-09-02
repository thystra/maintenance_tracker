<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Category> */
final class CategoryMapper extends QBMapper {
	public function __construct(IDBConnection $db) { parent::__construct($db, 'maint_categories', Category::class); }
	/** @return list<Category> */
	public function findForWorkspace(int $workspaceId): array {
		$q=$this->db->getQueryBuilder();
		$q->select('*')->from('maint_categories')->where($q->expr()->eq('workspace_id',$q->createNamedParameter($workspaceId,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->isNull('deleted_at'))->orderBy('name','ASC');
		return $this->findEntities($q);
	}
	public function findByKey(int $workspaceId,string $key,bool $includeDeleted=false): Category {
		$q=$this->db->getQueryBuilder();
		$q->select('*')->from('maint_categories')->where($q->expr()->eq('workspace_id',$q->createNamedParameter($workspaceId,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('category_key',$q->createNamedParameter($key,IQueryBuilder::PARAM_STR)));
		if(!$includeDeleted){$q->andWhere($q->expr()->isNull('deleted_at'));}
		return $this->findEntity($q);
	}
	public function findByUuid(int $workspaceId,string $uuid): Category {
		$q=$this->db->getQueryBuilder();
		$q->select('*')->from('maint_categories')->where($q->expr()->eq('workspace_id',$q->createNamedParameter($workspaceId,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('uuid',$q->createNamedParameter($uuid,IQueryBuilder::PARAM_STR)))->andWhere($q->expr()->isNull('deleted_at'));
		return $this->findEntity($q);
	}
}
