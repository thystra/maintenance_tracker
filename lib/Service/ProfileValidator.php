<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use JsonException;
use OCA\MaintenanceTracker\Exception\ValidationException;

final class ProfileValidator {
	public const MAX_PROFILE_BYTES = 1048576;
	private const MAX_DEPTH = 16;
	private const METER_INTERVAL_UNITS = [
		'distance' => ['mi','km','m','mm'],
		'runtime' => ['hour','min','s'],
		'usage_count' => ['use','count'],
	];

	public function __construct(private MeterValueConverter $meterValues) {
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{profile:array<string,mixed>,canonicalJson:string,contentHash:string,summary:array<string,int>}
	 */
	public function validate(array $input): array {
		$this->assertEncodedBound($input);
		$this->assertDepth($input, 0);
		$this->known($input, ['schemaVersion','id','version','name','category','description','dataLicense','provenance','applicability','meters','components','parts','workGroups','workDefinitions'], 'profile');
		$this->required($input, ['schemaVersion','id','version','name','category','description','dataLicense','provenance','applicability','meters','components','parts','workGroups','workDefinitions'], 'profile');
		if ($input['schemaVersion'] !== 2) {
			throw new ValidationException('schemaVersion must be 2 for runtime installation');
		}

		$profile = [
			'schemaVersion' => 2,
			'id' => $this->key($input['id'], 'id', 160, true),
			'version' => $this->semver($input['version']),
			'name' => $this->text($input['name'], 'name', 255),
			'category' => $this->key($input['category'], 'category', 64),
			'description' => $this->text($input['description'], 'description', 4000, true),
			'dataLicense' => $this->license($input['dataLicense']),
			'provenance' => $this->provenance($input['provenance']),
			'applicability' => $this->applicability($input['applicability']),
			'meters' => $this->meters($input['meters']),
			'components' => $this->components($input['components']),
			'parts' => $this->parts($input['parts']),
			'workGroups' => $this->workGroups($input['workGroups']),
			'workDefinitions' => $this->workDefinitions($input['workDefinitions']),
		];
		$this->crossReferences($profile);
		$canonicalJson = $this->canonicalJson($profile);
		return [
			'profile' => $profile,
			'canonicalJson' => $canonicalJson,
			'contentHash' => hash('sha256', $canonicalJson),
			'summary' => [
				'meters' => count($profile['meters']),
				'components' => array_sum(array_map(static fn (array $c): int => $c['quantity'], $profile['components'])),
				'componentTemplates' => count($profile['components']),
				'parts' => count($profile['parts']),
				'workGroups' => count($profile['workGroups']),
				'workDefinitions' => count($profile['workDefinitions']),
			],
		];
	}

	/** @param array<string,mixed> $profile */
	public function canonicalJson(array $profile): string {
		$normalized = $this->sortObjectKeys($profile);
		try {
			return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		} catch (JsonException $exception) {
			throw new ValidationException('Profile must be JSON encodable', 0, $exception);
		}
	}

	private function provenance(mixed $value): array {
		$a = $this->object($value, 'provenance');
		$this->known($a, ['author','sourceUrl','sourceRevision'], 'provenance');
		$this->required($a, ['author','sourceUrl'], 'provenance');
		$out = ['author' => $this->text($a['author'], 'provenance.author', 255), 'sourceUrl' => $this->httpsUrl($a['sourceUrl'], 'provenance.sourceUrl')];
		if (array_key_exists('sourceRevision', $a)) $out['sourceRevision'] = $this->text($a['sourceRevision'], 'provenance.sourceRevision', 160);
		return $out;
	}

	private function applicability(mixed $value): array {
		$a = $this->object($value, 'applicability');
		$this->known($a, ['manufacturer','model','yearFrom','yearTo','tags'], 'applicability');
		$out = [];
		foreach (['manufacturer','model'] as $field) if (array_key_exists($field, $a)) $out[$field] = $this->text($a[$field], "applicability.{$field}", 255);
		foreach (['yearFrom','yearTo'] as $field) if (array_key_exists($field, $a)) $out[$field] = $this->integer($a[$field], "applicability.{$field}", 1000, 9999);
		if (isset($out['yearFrom'], $out['yearTo']) && $out['yearFrom'] > $out['yearTo']) throw new ValidationException('applicability yearFrom must not exceed yearTo');
		if (array_key_exists('tags', $a)) $out['tags'] = $this->keyList($a['tags'], 'applicability.tags', 32);
		return $out;
	}

	private function meters(mixed $value): array {
		$items = $this->listValue($value, 'meters', 32); $out = [];
		foreach ($items as $i => $value) {
			$a = $this->object($value, "meters[{$i}]");
			$this->known($a, ['key','name','dimension','displayUnit','monotonic'], "meters[{$i}]");
			$this->required($a, ['key','name','dimension','displayUnit','monotonic'], "meters[{$i}]");
			$dimension = $this->enum($a['dimension'], "meters[{$i}].dimension", $this->meterValues->dimensions());
			$unit = $this->meterValues->validateDisplayUnit($dimension, $a['displayUnit']);
			$out[] = ['key'=>$this->key($a['key'],"meters[{$i}].key",64),'name'=>$this->text($a['name'],"meters[{$i}].name",255),'dimension'=>$dimension,'displayUnit'=>$unit,'monotonic'=>$this->boolean($a['monotonic'],"meters[{$i}].monotonic")];
		}
		$this->uniqueKeys($out, 'meters'); return $out;
	}

	private function components(mixed $value): array {
		$items = $this->listValue($value, 'components', 256); $out = [];
		foreach ($items as $i => $value) {
			$a=$this->object($value,"components[{$i}]");
			$this->known($a,['key','name','parentKey','quantity','enabledByDefault','notes','compatiblePartKeys'],"components[{$i}]");
			$this->required($a,['key','name','quantity','enabledByDefault','compatiblePartKeys'],"components[{$i}]");
			$item=['key'=>$this->key($a['key'],"components[{$i}].key",64),'name'=>$this->text($a['name'],"components[{$i}].name",255),'quantity'=>$this->integer($a['quantity'],"components[{$i}].quantity",1,64),'enabledByDefault'=>$this->boolean($a['enabledByDefault'],"components[{$i}].enabledByDefault"),'compatiblePartKeys'=>$this->keyList($a['compatiblePartKeys'],"components[{$i}].compatiblePartKeys",64)];
			if(array_key_exists('parentKey',$a))$item['parentKey']=$this->key($a['parentKey'],"components[{$i}].parentKey",64);
			if(array_key_exists('notes',$a))$item['notes']=$this->text($a['notes'],"components[{$i}].notes",20000,true);
			$out[]=$item;
		}
		$this->uniqueKeys($out,'components'); return $out;
	}

	private function parts(mixed $value): array {
		$items=$this->listValue($value,'parts',512);$out=[];
		foreach($items as $i=>$value){$a=$this->object($value,"parts[{$i}]");$this->known($a,['key','manufacturer','partNumber','description','offers'],"parts[{$i}]");$this->required($a,['key','manufacturer','partNumber','description','offers'],"parts[{$i}]");$offers=$this->listValue($a['offers'],"parts[{$i}].offers",32);$normOffers=[];foreach($offers as $j=>$offer){$o=$this->object($offer,"parts[{$i}].offers[{$j}]");$this->known($o,['label','sku','url'],"parts[{$i}].offers[{$j}]");$this->required($o,['label','url'],"parts[{$i}].offers[{$j}]");$n=['label'=>$this->text($o['label'],"parts[{$i}].offers[{$j}].label",255),'url'=>$this->httpsUrl($o['url'],"parts[{$i}].offers[{$j}].url")];if(array_key_exists('sku',$o))$n['sku']=$this->text($o['sku'],"parts[{$i}].offers[{$j}].sku",128);$normOffers[]=$n;}$out[]=['key'=>$this->key($a['key'],"parts[{$i}].key",64),'manufacturer'=>$this->text($a['manufacturer'],"parts[{$i}].manufacturer",255),'partNumber'=>$this->text($a['partNumber'],"parts[{$i}].partNumber",128),'description'=>$this->text($a['description'],"parts[{$i}].description",1000,true),'offers'=>$normOffers];}
		$this->uniqueKeys($out,'parts');return $out;
	}

	private function workGroups(mixed $value): array {
		$items=$this->listValue($value,'workGroups',256);$out=[];foreach($items as $i=>$value){$a=$this->object($value,"workGroups[{$i}]");$this->known($a,['key','name','description','sortOrder'],"workGroups[{$i}]");$this->required($a,['key','name','sortOrder'],"workGroups[{$i}]");$n=['key'=>$this->key($a['key'],"workGroups[{$i}].key",64),'name'=>$this->text($a['name'],"workGroups[{$i}].name",255),'sortOrder'=>$this->integer($a['sortOrder'],"workGroups[{$i}].sortOrder",-1000000,1000000)];if(array_key_exists('description',$a))$n['description']=$this->text($a['description'],"workGroups[{$i}].description",4000,true);$out[]=$n;}$this->uniqueKeys($out,'workGroups');return $out;
	}

	private function workDefinitions(mixed $value): array {
		$items=$this->listValue($value,'workDefinitions',512);$out=[];foreach($items as $i=>$value){$a=$this->object($value,"workDefinitions[{$i}]");$this->known($a,['key','title','kind','groupKey','componentKey','enabledByDefault','instructions','notes','schedule','compatiblePartKeys'],"workDefinitions[{$i}]");$this->required($a,['key','title','kind','enabledByDefault','schedule','compatiblePartKeys'],"workDefinitions[{$i}]");$n=['key'=>$this->key($a['key'],"workDefinitions[{$i}].key",64),'title'=>$this->text($a['title'],"workDefinitions[{$i}].title",255),'kind'=>$this->key($a['kind'],"workDefinitions[{$i}].kind",64),'enabledByDefault'=>$this->boolean($a['enabledByDefault'],"workDefinitions[{$i}].enabledByDefault"),'schedule'=>$this->schedule($a['schedule'],"workDefinitions[{$i}].schedule"),'compatiblePartKeys'=>$this->keyList($a['compatiblePartKeys'],"workDefinitions[{$i}].compatiblePartKeys",64)];foreach(['groupKey','componentKey'] as $f)if(array_key_exists($f,$a))$n[$f]=$this->key($a[$f],"workDefinitions[{$i}].{$f}",64);foreach(['instructions','notes'] as $f)if(array_key_exists($f,$a))$n[$f]=$this->text($a[$f],"workDefinitions[{$i}].{$f}",$f==='instructions'?10000:20000,true);$out[]=$n;}$this->uniqueKeys($out,'workDefinitions');return $out;
	}

	private function schedule(mixed $value,string $field): string|array {
		if($value==='none')return 'none';$a=$this->object($value,$field);$this->known($a,['combination','rules'],$field);$this->required($a,['combination','rules'],$field);if($a['combination']!=='any')throw new ValidationException("{$field}.combination must be any");$rules=$this->listValue($a['rules'],"{$field}.rules",8,1);$out=[];foreach($rules as $i=>$value){$r=$this->object($value,"{$field}.rules[{$i}]");$this->required($r,['type','interval'],"{$field}.rules[{$i}]");$type=$this->enum($r['type'],"{$field}.rules[{$i}].type",['calendar','business_days','meter']);if($type==='calendar'){$this->known($r,['type','interval'],"{$field}.rules[{$i}]");$interval=$this->interval($r['interval'],"{$field}.rules[{$i}].interval",['day','week','month','year'],10000,true);$out[]=['type'=>'calendar','interval'=>$interval];}elseif($type==='business_days'){$this->known($r,['type','interval','weekdays'],"{$field}.rules[{$i}]");$this->required($r,['weekdays'],"{$field}.rules[{$i}]");$interval=$this->interval($r['interval'],"{$field}.rules[{$i}].interval",['business_day'],1000000,true);$weekdays=$this->enumList($r['weekdays'],"{$field}.rules[{$i}].weekdays",['sun','mon','tue','wed','thu','fri','sat'],7,1);$out[]=['type'=>'business_days','interval'=>$interval,'weekdays'=>$weekdays];}else{$this->known($r,['type','meterKey','interval'],"{$field}.rules[{$i}]");$this->required($r,['meterKey'],"{$field}.rules[{$i}]");$interval=$this->interval($r['interval'],"{$field}.rules[{$i}].interval",['mi','km','m','mm','hour','min','s','use','count'],PHP_INT_MAX,false);$out[]=['type'=>'meter','meterKey'=>$this->key($r['meterKey'],"{$field}.rules[{$i}].meterKey",64),'interval'=>$interval];}}return ['combination'=>'any','rules'=>$out];
	}

	private function interval(mixed $value,string $field,array $units,int $max,bool $whole):array{$a=$this->object($value,$field);$this->known($a,['value','unit'],$field);$this->required($a,['value','unit'],$field);$unit=$this->enum($a['unit'],"{$field}.unit",$units);$v=$whole?$this->integer($a['value'],"{$field}.value",1,$max):$this->positiveDecimal($a['value'],"{$field}.value");return ['value'=>$v,'unit'=>$unit];}

	/** @param array<string,mixed> $p */
	private function crossReferences(array $p):void{
		$meters=$this->byKey($p['meters']);$components=$this->byKey($p['components']);$parts=$this->byKey($p['parts']);$groups=$this->byKey($p['workGroups']);
		foreach($p['components'] as $c){if(isset($c['parentKey'])){if(!isset($components[$c['parentKey']]))throw new ValidationException("Component {$c['key']} references unknown parentKey {$c['parentKey']}");if($components[$c['parentKey']]['quantity']!==1)throw new ValidationException("Component {$c['key']} parentKey {$c['parentKey']} is ambiguous because the parent quantity is greater than one");}foreach($c['compatiblePartKeys'] as $k)if(!isset($parts[$k]))throw new ValidationException("Component {$c['key']} references unknown part {$k}");}
		$this->assertNoComponentCycles($components);
		foreach($p['workDefinitions'] as $d){if(isset($d['groupKey'])&&!isset($groups[$d['groupKey']]))throw new ValidationException("Work definition {$d['key']} references unknown groupKey {$d['groupKey']}");if(isset($d['componentKey'])){if(!isset($components[$d['componentKey']]))throw new ValidationException("Work definition {$d['key']} references unknown componentKey {$d['componentKey']}");if($components[$d['componentKey']]['quantity']!==1)throw new ValidationException("Work definition {$d['key']} componentKey {$d['componentKey']} is ambiguous because the component quantity is greater than one");}foreach($d['compatiblePartKeys'] as $k)if(!isset($parts[$k]))throw new ValidationException("Work definition {$d['key']} references unknown part {$k}");if(is_array($d['schedule']))foreach($d['schedule']['rules'] as $r)if($r['type']==='meter'){if(!isset($meters[$r['meterKey']]))throw new ValidationException("Work definition {$d['key']} references unknown meterKey {$r['meterKey']}");$allowed=self::METER_INTERVAL_UNITS[$meters[$r['meterKey']]['dimension']];if(!in_array($r['interval']['unit'],$allowed,true))throw new ValidationException("Work definition {$d['key']} uses a meter interval unit incompatible with {$r['meterKey']}");$converted=$this->meterValues->toCanonical($meters[$r['meterKey']]['dimension'],$r['interval']['value'],$r['interval']['unit']);if($converted['canonicalValue']<1)throw new ValidationException("Work definition {$d['key']} meter interval rounds below one canonical unit");}}
	}

	private function assertNoComponentCycles(array $components):void{foreach(array_keys($components) as $start){$seen=[];$key=$start;while(isset($components[$key]['parentKey'])){$key=$components[$key]['parentKey'];if(isset($seen[$key])||$key===$start)throw new ValidationException("Component parent cycle includes {$start}");$seen[$key]=true;}}}
	private function byKey(array $items):array{$out=[];foreach($items as $item)$out[$item['key']]=$item;return $out;}
	private function uniqueKeys(array $items,string $field):void{$seen=[];foreach($items as $item){if(isset($seen[$item['key']]))throw new ValidationException("{$field} contains duplicate key {$item['key']}");$seen[$item['key']]=true;}}
	private function keyList(mixed $value,string $field,int $max):array{return $this->enumList($value,$field,null,$max,0,true);}
	private function enumList(mixed $value,string $field,?array $allowed,int $max,int $min=0,bool $keys=false):array{$items=$this->listValue($value,$field,$max,$min);$out=[];foreach($items as $i=>$v)$out[]=$keys?$this->key($v,"{$field}[{$i}]",64):$this->enum($v,"{$field}[{$i}]",$allowed??[]);if(count(array_unique($out,SORT_STRING))!==count($out))throw new ValidationException("{$field} must contain unique values");return $out;}
	private function listValue(mixed $value,string $field,int $max,int $min=0):array{if(!is_array($value)||!array_is_list($value))throw new ValidationException("{$field} must be an array");$n=count($value);if($n<$min||$n>$max)throw new ValidationException("{$field} item count is outside the reviewed bound");return $value;}
	private function object(mixed $value,string $field):array{if(!is_array($value)||array_is_list($value))throw new ValidationException("{$field} must be an object");return $value;}
	private function required(array $a,array $required,string $field):void{foreach($required as $k)if(!array_key_exists($k,$a))throw new ValidationException("{$field}.{$k} is required");}
	private function known(array $a,array $allowed,string $field):void{$unknown=array_diff(array_keys($a),$allowed);if($unknown!==[])throw new ValidationException("{$field} contains unknown fields: ".implode(', ',$unknown));}
	private function text(mixed $v,string $field,int $max,bool $newlines=false):string{if(!is_string($v))throw new ValidationException("{$field} must be a string");$v=trim($v);if($v==='')throw new ValidationException("{$field} cannot be empty");if($this->length($v)>$max)throw new ValidationException("{$field} exceeds {$max} characters");$pattern=$newlines?'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u':'/[\x00-\x1F\x7F]/u';if(preg_match($pattern,$v)===1)throw new ValidationException("{$field} contains unsupported control characters");return $v;}
	private function key(mixed $v,string $field,int $max,bool $dots=false):string{$s=$this->text($v,$field,$max);$pattern=$dots?'/^[a-z0-9][a-z0-9._-]*$/D':'/^[a-z0-9][a-z0-9_-]*$/D';if(preg_match($pattern,$s)!==1)throw new ValidationException("{$field} must be a lowercase key");if($dots&&$this->length($s)<3)throw new ValidationException("{$field} must contain at least three characters");return $s;}
	private function semver(mixed $v):string{$s=$this->text($v,'version',32);if(preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/D',$s)!==1)throw new ValidationException('version must be a semantic version');return $s;}
	private function license(mixed $v):string{$s=$this->text($v,'dataLicense',64);if(preg_match('/^[A-Za-z0-9.+-]+$/D',$s)!==1)throw new ValidationException('dataLicense must be an SPDX-style identifier');return $s;}
	private function httpsUrl(mixed $v,string $field):string{$s=$this->text($v,$field,2048);if(str_starts_with(strtolower($s),'https://')===false||filter_var($s,FILTER_VALIDATE_URL)===false)throw new ValidationException("{$field} must be an HTTPS URL");return $s;}
	private function positiveDecimal(mixed $value,string $field):string{if(is_int($value)){if($value<=0)throw new ValidationException("{$field} must be positive");return (string)$value;}if(is_float($value)){if(!is_finite($value)||$value<=0)throw new ValidationException("{$field} must be a finite positive decimal number");$value=rtrim(rtrim(sprintf('%.9F',$value),'0'),'.');}if(!is_string($value))throw new ValidationException("{$field} must be a positive decimal number or decimal string");$value=trim($value);if(preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,9})?$/D',$value)!==1)throw new ValidationException("{$field} must be a positive decimal with at most 9 fractional digits");[$whole,$fraction]=array_pad(explode('.',$value,2),2,'');$whole=ltrim($whole,'0');$whole=$whole===''?'0':$whole;$fraction=rtrim($fraction,'0');$normalized=$fraction===''?$whole:$whole.'.'.$fraction;if($normalized==='0')throw new ValidationException("{$field} must be positive");return $normalized;}
	private function integer(mixed $v,string $field,int $min,int $max):int{if(!is_int($v)||$v<$min||$v>$max)throw new ValidationException("{$field} must be an integer between {$min} and {$max}");return $v;}
	private function boolean(mixed $v,string $field):bool{if(!is_bool($v))throw new ValidationException("{$field} must be boolean");return $v;}
	private function enum(mixed $v,string $field,array $allowed):string{if(!is_string($v)||!in_array($v,$allowed,true))throw new ValidationException("{$field} is not supported");return $v;}
	private function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
	private function assertEncodedBound(array $input):void{try{$json=json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}catch(JsonException $e){throw new ValidationException('Profile must be JSON encodable',0,$e);}if(strlen($json)>self::MAX_PROFILE_BYTES)throw new ValidationException('Profile exceeds the 1 MiB reviewed input bound');}
	private function assertDepth(mixed $value,int $depth):void{if($depth>self::MAX_DEPTH)throw new ValidationException('Profile nesting exceeds the reviewed depth bound');if(is_array($value))foreach($value as $child)$this->assertDepth($child,$depth+1);}
	private function sortObjectKeys(mixed $value):mixed{if(!is_array($value))return $value;if(array_is_list($value))return array_map(fn(mixed $v):mixed=>$this->sortObjectKeys($v),$value);ksort($value,SORT_STRING);foreach($value as $k=>$v)$value[$k]=$this->sortObjectKeys($v);return $value;}
}
