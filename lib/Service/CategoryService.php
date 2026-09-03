<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Category;
use OCA\MaintenanceTracker\Db\CategoryMapper;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\IDBConnection;use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

final class CategoryService {
	public const ASSET_CLASSES=['vehicle','trailer','building','equipment','appliance','system','tool','medical_device','location','other'];
	private const BUILTINS=[
		'vehicle'=>['Vehicle','vehicle'], 'home'=>['Home','building'], 'tool'=>['Tool','tool'],
		'health'=>['Health equipment','medical_device'], 'outdoor'=>['Outdoor equipment','equipment'], 'other'=>['Other','other'],
	];
	public function __construct(private CategoryMapper $mapper,private UuidGenerator $uuidGenerator,private ChangeJournal $changeJournal,private IDBConnection $db,private ITimeFactory $timeFactory){}
	public function list(WorkspaceContext $context): array {
		$items=[];
		foreach(self::BUILTINS as $key=>[$name,$class]){$items[]=['uuid'=>null,'key'=>$key,'name'=>$name,'defaultAssetClass'=>$class,'description'=>null,'builtIn'=>true,'revision'=>null];}
		foreach($this->mapper->findForWorkspace($context->workspace()->getId()) as $category){$items[]=$category->toApi();}
		return $items;
	}
	public function defaultClass(WorkspaceContext $context,string $key): string {
		if(isset(self::BUILTINS[$key])){return self::BUILTINS[$key][1];}
		try{return $this->mapper->findByKey($context->workspace()->getId(),$key)->getDefaultClass();}catch(DoesNotExistException){throw new ValidationException('Unknown asset category');}
	}
	public function create(WorkspaceContext $context,array $input): Category {
		$allowed=['uuid','key','name','defaultAssetClass','description'];
		$unknown=array_diff(array_keys($input),$allowed); if($unknown!==[]){throw new ValidationException('Unknown category fields: '.implode(', ',$unknown));}
		$key=$this->key($input['key']??null); if(isset(self::BUILTINS[$key])){throw new ValidationException('Built-in category keys cannot be replaced');}
		$name=$this->text($input['name']??null,'name',120,false); $class=$this->assetClass($input['defaultAssetClass']??'other');
		$description=$this->text($input['description']??null,'description',2000,true,true);
		try{$this->mapper->findByKey($context->workspace()->getId(),$key,true);throw new ValidationException('Category key already exists');}catch(DoesNotExistException){}
		$c=new Category();$now=$this->timeFactory->getTime();$c->setWorkspaceId($context->workspace()->getId());$uuid=$this->uuid($input['uuid']??null)??$this->uuidGenerator->generate();$c->setUuid($uuid);$c->setCategoryKey($key);$c->setName($name);$c->setDefaultClass($class);$c->setDescription($description);$c->setRevision(1);$c->setCreatedAt($now);$c->setUpdatedAt($now);$c->setDeletedAt(null);$owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();try{$c=$this->mapper->insert($c);$this->changeJournal->record($c->getWorkspaceId(),'category',$c->getUuid(),'upsert',1,$now);if($owns)$this->db->commit();return $c;}catch(\Throwable $e){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
	}
	private function uuid(mixed $v):?string{if($v===null||$v==='')return null;if(!is_string($v)||!UuidGenerator::isValid(strtolower(trim($v))))throw new ValidationException('uuid must be an RFC 4122 version 4 UUID');return strtolower(trim($v));}
	private function key(mixed $v):string{$s=$this->text($v,'key',64,false);if(preg_match('/^[a-z0-9][a-z0-9_-]*$/D',$s)!==1){throw new ValidationException('key must use lowercase letters, digits, underscores, or hyphens');}return $s;}
	private function assetClass(mixed $v):string{$s=$this->text($v,'defaultAssetClass',32,false);if(!in_array($s,self::ASSET_CLASSES,true)){throw new ValidationException('Unsupported asset class');}return $s;}
	private function text(mixed $v,string $field,int $max,bool $newlines,bool $optional=false):?string{if($optional&&($v===null||$v===''))return null;if(!is_string($v))throw new ValidationException("{$field} must be a string");$v=trim($v);if($v===''&&!$optional)throw new ValidationException("{$field} cannot be empty");if(mb_strlen($v)>$max)throw new ValidationException("{$field} exceeds {$max} characters");$p=$newlines?'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u':'/[\x00-\x1F\x7F]/u';if(preg_match($p,$v)===1)throw new ValidationException("{$field} contains unsupported control characters");return $v===''?null:$v;}
}
