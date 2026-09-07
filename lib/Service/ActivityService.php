<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\MaintenanceTracker\Service;

use DateTimeImmutable;
use OCA\MaintenanceTracker\Db\Activity;
use OCA\MaintenanceTracker\Db\ActivityItem;
use OCA\MaintenanceTracker\Db\ActivityItemMapper;
use OCA\MaintenanceTracker\Db\ActivityMapper;
use OCA\MaintenanceTracker\Db\ActivityMeter;
use OCA\MaintenanceTracker\Db\ActivityMeterMapper;
use OCA\MaintenanceTracker\Db\Component;
use OCA\MaintenanceTracker\Db\ComponentMapper;
use OCA\MaintenanceTracker\Db\Reading;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;

final class ActivityService {
	public function __construct(
		private AssetService $assets,
		private ComponentMapper $components,
		private WorkDefinitionService $definitions,
		private MeterService $meters,
		private ReadingService $readings,
		private MeterValueConverter $meterValues,
		private ActivityMapper $mapper,
		private ActivityItemMapper $items,
		private ActivityMeterMapper $meterSnapshots,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {}

	/** @return list<array<string,mixed>> */
	public function list(WorkspaceContext $context, string $assetUuid): array {
		$asset = $this->assets->find($context, $assetUuid);
		return array_map(fn(Activity $a): array => $this->toApi($context, $a), $this->mapper->findForAsset($context->workspace()->getId(), $asset->getId()));
	}

	/** @return array<string,mixed> */
	public function show(WorkspaceContext $context, string $uuid): array { return $this->toApi($context, $this->find($context, $uuid)); }

	public function find(WorkspaceContext $context, string $uuid, bool $includeDeleted=false): Activity {
		try { return $this->mapper->findByUuid($context->workspace()->getId(), $this->uuid($uuid, 'uuid'), $includeDeleted); }
		catch (DoesNotExistException) { throw new NotFoundException('Activity not found'); }
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function create(WorkspaceContext $context, string $assetUuid, array $input): array {
		$this->known($input, ['uuid','performedAt','summary','notes','items','meters'], 'activity');
		foreach (['uuid','performedAt','items'] as $field) if (!array_key_exists($field,$input)) throw new ValidationException("{$field} is required");
		$asset = $this->assets->find($context, $assetUuid);
		$uuid = $this->uuid($input['uuid'], 'uuid');
		$performedAt = $this->timestamp($input['performedAt'], 'performedAt');
		$summary = $this->optionalText($input['summary'] ?? null, 'summary', 255);
		$notes = $this->optionalText($input['notes'] ?? null, 'notes', 20000);
		if (!is_array($input['items']) || !array_is_list($input['items']) || $input['items'] === []) throw new ValidationException('items must be a non-empty array');
		$meterInputs = $input['meters'] ?? [];
		if (!is_array($meterInputs) || !array_is_list($meterInputs)) throw new ValidationException('meters must be an array');

		try {
			$existing = $this->mapper->findByUuid($context->workspace()->getId(), $uuid, true);
			if ($existing->getDeletedAt() === null && $existing->getAssetId() === $asset->getId() && $this->matchesRetry($context, $existing, $input, $performedAt, $summary, $notes)) return $this->toApi($context, $existing);
			throw new RevisionConflictException('The supplied activity UUID already exists with different data');
		} catch (DoesNotExistException) {}

		$now = $this->timeFactory->getTime();
		$a = new Activity(); $a->setWorkspaceId($context->workspace()->getId()); $a->setAssetId($asset->getId()); $a->setUuid($uuid); $a->setPerformedAt($performedAt); $a->setSummary($summary); $a->setNotes($notes); $a->setRevision(1); $a->setCreatedAt($now); $a->setUpdatedAt($now); $a->setDeletedAt(null);
		try {
			/** @var Activity $a */ $a = $this->mapper->insert($a);
			foreach ($input['items'] as $position => $itemInput) $this->items->append($this->buildItem($context, $a, $asset->getId(), $itemInput, $position));
			foreach ($meterInputs as $position => $meterInput) $this->meterSnapshots->append($this->buildMeterSnapshot($context, $a, $asset->getId(), $meterInput, $position));
			$this->journal->record($a->getWorkspaceId(), 'activity', $uuid, 'upsert', 1, $now);
			return $this->toApi($context, $a);
		} catch (DatabaseException $e) {
			if ($e->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) throw new RevisionConflictException('An activity or child UUID already exists', 0, $e);
			throw $e;
		}
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		if ($expectedRevision < 1) throw new ValidationException('expectedRevision must be positive');
		$this->known($input, ['summary','notes'], 'activity update');
		$a = $this->find($context, $uuid);
		if ($a->getRevision() !== $expectedRevision) throw new RevisionConflictException('The activity has changed since it was last read');
		if (array_key_exists('summary',$input)) $a->setSummary($this->optionalText($input['summary'],'summary',255));
		if (array_key_exists('notes',$input)) $a->setNotes($this->optionalText($input['notes'],'notes',20000));
		$a->setRevision($expectedRevision+1); $a->setUpdatedAt($this->timeFactory->getTime());
		if (!$this->mapper->updateWithExpectedRevision($a,$expectedRevision)) throw new RevisionConflictException('The activity has changed since it was last read');
		$this->journal->record($a->getWorkspaceId(),'activity',$a->getUuid(),'upsert',$a->getRevision(),$a->getUpdatedAt());
		return $this->toApi($context,$a);
	}

	/** @return array<string,mixed> */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		if ($expectedRevision < 1) throw new ValidationException('expectedRevision must be positive');
		$a=$this->find($context,$uuid); if($a->getRevision()!==$expectedRevision) throw new RevisionConflictException('The activity has changed since it was last read');
		$now=$this->timeFactory->getTime();$a->setRevision($expectedRevision+1);$a->setUpdatedAt($now);$a->setDeletedAt($now);
		if(!$this->mapper->updateWithExpectedRevision($a,$expectedRevision)) throw new RevisionConflictException('The activity has changed since it was last read');
		$this->journal->record($a->getWorkspaceId(),'activity',$a->getUuid(),'delete',$a->getRevision(),$now); return $this->toApi($context,$a);
	}

	private function buildItem(WorkspaceContext $c, Activity $a, int $assetId, mixed $raw, int $position): ActivityItem {
		if(!is_array($raw)) throw new ValidationException('activity items must be objects'); $this->known($raw,['uuid','definitionUuid','componentUuid','title','kind','notes'],'activity item');
		if(!array_key_exists('uuid',$raw)) throw new ValidationException('activity item uuid is required');
		$definition=null;$component=null;$definitionUuid=null;
		if(($raw['definitionUuid']??null)!==null){$definitionUuid=$this->uuid($raw['definitionUuid'],'definitionUuid');$definition=$this->definitions->find($c,$definitionUuid,true);if($definition->getAssetId()!==$assetId)throw new ValidationException('definitionUuid must belong to the activity asset');if($definition->getComponentId()!==null)$component=$this->componentById($c,$definition->getComponentId());}
		if(($raw['componentUuid']??null)!==null){$requested=$this->componentByUuid($c,$this->uuid($raw['componentUuid'],'componentUuid'));if($requested->getAssetId()!==$assetId)throw new ValidationException('componentUuid must belong to the activity asset');if($component!==null&&$component->getId()!==$requested->getId())throw new ValidationException('componentUuid must match the linked work definition');$component=$requested;}
		if($definition===null){if(!array_key_exists('title',$raw)||!array_key_exists('kind',$raw))throw new ValidationException('ad-hoc activity items require title and kind');$title=$this->text($raw['title'],'title',255);$kind=$this->key($raw['kind'],'kind');}else{$title=$definition->getTitle();$kind=$definition->getKind();}
		$r=new ActivityItem();$r->setWorkspaceId($a->getWorkspaceId());$r->setActivityId($a->getId());$r->setUuid($this->uuid($raw['uuid'],'item uuid'));$r->setPosition($position);$r->setDefinitionId($definition?->getId());$r->setDefinitionUuid($definitionUuid);$r->setComponentId($component?->getId());$r->setComponentUuid($component?->getUuid());$r->setComponentName($component?->getName());$r->setTitle($title);$r->setKind($kind);$r->setNotes($this->optionalText($raw['notes']??null,'item notes',20000));return $r;
	}

	private function buildMeterSnapshot(WorkspaceContext $c, Activity $a, int $assetId, mixed $raw, int $position): ActivityMeter {
		if(!is_array($raw))throw new ValidationException('activity meters must be objects');$this->known($raw,['uuid','meterUuid','readingUuid','reading'],'activity meter');foreach(['uuid','meterUuid'] as $f)if(!array_key_exists($f,$raw))throw new ValidationException("activity meter {$f} is required");
		$meter=$this->meters->find($c,$this->uuid($raw['meterUuid'],'meterUuid'),true);if($meter->getAssetId()!==$assetId)throw new ValidationException('meterUuid must belong to the activity asset');
		$hasUuid=array_key_exists('readingUuid',$raw);$hasNew=array_key_exists('reading',$raw);if($hasUuid===$hasNew)throw new ValidationException('activity meter requires exactly one of readingUuid or reading');
		if($hasUuid){$reading=$this->readings->find($c,$this->uuid($raw['readingUuid'],'readingUuid'));}
		else{if(!is_array($raw['reading']))throw new ValidationException('reading must be an object');$this->known($raw['reading'],['uuid','value','unit','notes'],'activity-created reading');if(!array_key_exists('uuid',$raw['reading']))throw new ValidationException('activity-created readings require a uuid');if(!array_key_exists('value',$raw['reading']))throw new ValidationException('activity-created readings require a value');if(!array_key_exists('unit',$raw['reading']))throw new ValidationException('activity-created readings require an explicit unit');$payload=$raw['reading'];$payload['observedAt']=$this->formatTimestamp($a->getPerformedAt());$payload['source']=['type'=>'activity','reference'=>$a->getUuid()];$created=$this->readings->create($c,$meter->getUuid(),$payload,true);$reading=$this->readings->find($c,(string)$created['uuid']);}
		if($reading->getMeterId()!==$meter->getId())throw new ValidationException('readingUuid must belong to meterUuid');if($reading->getObservedAt()!==$a->getPerformedAt())throw new ValidationException('activity meter readings must be observed at performedAt');
		$r=new ActivityMeter();$r->setWorkspaceId($a->getWorkspaceId());$r->setActivityId($a->getId());$r->setUuid($this->uuid($raw['uuid'],'meter snapshot uuid'));$r->setPosition($position);$r->setMeterId($meter->getId());$r->setMeterUuid($meter->getUuid());$r->setMeterName($meter->getName());$r->setReadingId($reading->getId());$r->setReadingUuid($reading->getUuid());$r->setObservedAt($reading->getObservedAt());$r->setCanonicalValue($reading->getCanonicalValue());$r->setOriginalValue($reading->getOriginalValue());$r->setOriginalUnit($reading->getOriginalUnit());return $r;
	}

	private function matchesRetry(WorkspaceContext $c, Activity $a, array $input, int $performedAt, ?string $summary, ?string $notes): bool {
		if($a->getPerformedAt()!==$performedAt||$a->getSummary()!==$summary||$a->getNotes()!==$notes)return false;
		$storedItems=$this->items->findForActivity($a->getWorkspaceId(),$a->getId());$rawItems=$input['items'];if(!is_array($rawItems)||count($storedItems)!==count($rawItems))return false;
		foreach($storedItems as $i=>$stored){$raw=$rawItems[$i]??null;if(!is_array($raw))return false;$this->known($raw,['uuid','definitionUuid','componentUuid','title','kind','notes'],'activity item');if(!isset($raw['uuid'])||$stored->getUuid()!==$this->uuid($raw['uuid'],'item uuid'))return false;$du=$raw['definitionUuid']??null;if(($du===null?null:$this->uuid($du,'definitionUuid'))!==$stored->getDefinitionUuid())return false;if($du===null){if(!array_key_exists('title',$raw)||!array_key_exists('kind',$raw)||$this->text($raw['title'],'title',255)!==$stored->getTitle()||$this->key($raw['kind'],'kind')!==$stored->getKind())return false;}$rawComponent=$raw['componentUuid']??null;if($rawComponent!==null&&$this->uuid($rawComponent,'componentUuid')!==$stored->getComponentUuid())return false;elseif($rawComponent===null&&$du===null&&$stored->getComponentUuid()!==null)return false;if($this->optionalText($raw['notes']??null,'item notes',20000)!==$stored->getNotes())return false;}
		$rawMeters=$input['meters']??[];$storedMeters=$this->meterSnapshots->findForActivity($a->getWorkspaceId(),$a->getId());if(!is_array($rawMeters)||count($storedMeters)!==count($rawMeters))return false;
		foreach($storedMeters as $i=>$stored){$raw=$rawMeters[$i]??null;if(!is_array($raw))return false;$this->known($raw,['uuid','meterUuid','readingUuid','reading'],'activity meter');if(!isset($raw['uuid'],$raw['meterUuid'])||$stored->getUuid()!==$this->uuid($raw['uuid'],'meter snapshot uuid')||$stored->getMeterUuid()!==$this->uuid($raw['meterUuid'],'meterUuid'))return false;$hasUuid=array_key_exists('readingUuid',$raw);$hasNew=array_key_exists('reading',$raw);if($hasUuid===$hasNew)throw new ValidationException('activity meter requires exactly one of readingUuid or reading');if($hasNew){if(!is_array($raw['reading']))throw new ValidationException('reading must be an object');$this->known($raw['reading'],['uuid','value','unit','notes'],'activity-created reading');if(!array_key_exists('uuid',$raw['reading']))throw new ValidationException('activity-created readings require a uuid');if(!array_key_exists('value',$raw['reading']))throw new ValidationException('activity-created readings require a value');if(!array_key_exists('unit',$raw['reading']))throw new ValidationException('activity-created readings require an explicit unit');$requestedReading=$raw['reading']['uuid'];}else{$requestedReading=$raw['readingUuid'];}if(!is_string($requestedReading)||$stored->getReadingUuid()!==$this->uuid($requestedReading,'reading uuid'))return false;if($hasNew){$reading=$this->readings->find($c,$stored->getReadingUuid());$meter=$this->meters->find($c,$stored->getMeterUuid(),true);try{$converted=$this->meterValues->toCanonical($meter->getDimension(),$raw['reading']['value'],$raw['reading']['unit']);}catch(ValidationException){return false;}if($converted['originalUnit']!==$reading->getOriginalUnit()||$converted['originalValue']!==$reading->getOriginalValue()||$this->optionalText($raw['reading']['notes']??null,'reading notes',20000)!==$reading->getNotes()||$reading->getSourceType()!=='activity'||$reading->getSourceRef()!==$a->getUuid())return false;}}
		return true;
	}

	/** @return array<string,mixed> */ private function toApi(WorkspaceContext $c, Activity $a): array {return ['uuid'=>$a->getUuid(),'performedAt'=>$this->formatTimestamp($a->getPerformedAt()),'summary'=>$a->getSummary(),'notes'=>$a->getNotes(),'revision'=>$a->getRevision(),'items'=>array_map(fn(ActivityItem $r)=>['uuid'=>$r->getUuid(),'definitionUuid'=>$r->getDefinitionUuid(),'componentUuid'=>$r->getComponentUuid(),'componentName'=>$r->getComponentName(),'title'=>$r->getTitle(),'kind'=>$r->getKind(),'notes'=>$r->getNotes()],$this->items->findForActivity($a->getWorkspaceId(),$a->getId())),'meters'=>array_map(fn(ActivityMeter $r)=>['uuid'=>$r->getUuid(),'meterUuid'=>$r->getMeterUuid(),'meterName'=>$r->getMeterName(),'readingUuid'=>$r->getReadingUuid(),'observedAt'=>$this->formatTimestamp($r->getObservedAt()),'canonicalValue'=>$r->getCanonicalValue(),'originalValue'=>$r->getOriginalValue(),'originalUnit'=>$r->getOriginalUnit()],$this->meterSnapshots->findForActivity($a->getWorkspaceId(),$a->getId())),'createdAt'=>$this->formatTimestamp($a->getCreatedAt()),'updatedAt'=>$this->formatTimestamp($a->getUpdatedAt()),'deletedAt'=>$a->getDeletedAt()===null?null:$this->formatTimestamp($a->getDeletedAt())];}
	private function componentByUuid(WorkspaceContext $c,string $uuid):Component{try{return $this->components->findByUuid($c->workspace()->getId(),$uuid,true);}catch(DoesNotExistException){throw new NotFoundException('Component not found');}}
	private function componentById(WorkspaceContext $c,int $id):Component{try{return $this->components->findById($c->workspace()->getId(),$id,true);}catch(DoesNotExistException){throw new NotFoundException('Component not found');}}
	private function timestamp(mixed $v,string $f):int{if(!is_string($v)||preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',trim($v))!==1)throw new ValidationException("{$f} must include seconds and a timezone");$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',str_replace('Z','+00:00',trim($v)));$e=DateTimeImmutable::getLastErrors();if($d===false||(is_array($e)&&($e['warning_count']>0||$e['error_count']>0))||$d->getTimestamp()<0)throw new ValidationException("{$f} is not a valid timestamp");return $d->getTimestamp();}
	private function formatTimestamp(int $v):string{return gmdate('Y-m-d\TH:i:s\Z',$v);} private function uuid(mixed $v,string $f):string{if(!is_string($v)||!UuidGenerator::isValid(strtolower(trim($v))))throw new ValidationException("{$f} must be an RFC 4122 version 4 UUID");return strtolower(trim($v));}
	private function text(mixed $v,string $f,int $m):string{if(!is_string($v))throw new ValidationException("{$f} must be a string");$v=trim($v);if($v===''||mb_strlen($v)>$m||preg_match('/[\x00-\x1F\x7F]/u',$v)===1)throw new ValidationException("{$f} contains unsupported content");return $v;} private function key(mixed $v,string $f):string{$s=$this->text($v,$f,64);if(preg_match('/^[a-z0-9][a-z0-9_-]*$/D',$s)!==1)throw new ValidationException("{$f} must be a lowercase key");return $s;}
	private function optionalText(mixed $v,string $f,int $m):?string{if($v===null||$v==='')return null;if(!is_string($v))throw new ValidationException("{$f} must be a string");$v=trim($v);if(mb_strlen($v)>$m||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$v)===1)throw new ValidationException("{$f} contains unsupported content");return $v===''?null:$v;} private function known(array $in,array $allowed,string $label):void{$u=array_diff(array_keys($in),$allowed);if($u!==[])throw new ValidationException('Unknown '.$label.' fields: '.implode(', ',$u));}
}
