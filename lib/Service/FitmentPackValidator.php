<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use JsonException;
use OCA\MaintenanceTracker\Exception\ValidationException;

final class FitmentPackValidator {
	public const MAX_PACK_BYTES = 8 * 1024 * 1024;
	private const MAX_DEPTH = 32;
	private const ASSET_CLASSES = ['vehicle','trailer','building','equipment','appliance','system','tool','medical_device','location','other'];
	private const QUALIFIER_MATCH = ['required','optional'];
	private const RELATIONS = ['oem','compatible'];
	private const VERIFICATION = ['asserted','verified'];
	private const EVIDENCE_KINDS = ['manufacturer','retailer_fitment','community_tested','other'];
	private const RESERVED_UNIT_IDENTIFIER_SEGMENTS = ['vin','serial','serial_number','registration','plate','license_plate','asset_uuid','uuid'];

	/** @var array<string,array{key:string,label:string,kind:string}> */
	private array $coreSlots = [];
	/** @var array<string,true> */
	private array $coreQualifiers = [];
	/** @var array<string,true> */
	private array $reservedSlotRoots = [];

	public function __construct() {
		$path = dirname(__DIR__, 2) . '/fitment/core-v1.json';
		$raw = @file_get_contents($path);
		if ($raw === false) {
			throw new \RuntimeException('The built-in fitment vocabulary is unavailable');
		}
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new \RuntimeException('The built-in fitment vocabulary is invalid JSON', 0, $exception);
		}
		if (!is_array($decoded) || ($decoded['schemaVersion'] ?? null) !== 1 || !isset($decoded['slots'], $decoded['qualifiers']) || !is_array($decoded['slots']) || !is_array($decoded['qualifiers'])) {
			throw new \RuntimeException('The built-in fitment vocabulary is invalid');
		}
		foreach ($decoded['slots'] as $slot) {
			if (!is_array($slot) || !isset($slot['key'], $slot['label'], $slot['kind']) || !is_string($slot['key']) || !is_string($slot['label']) || !is_string($slot['kind'])) {
				throw new \RuntimeException('The built-in fitment slot vocabulary is invalid');
			}
			if (isset($this->coreSlots[$slot['key']])) {
				throw new \RuntimeException('The built-in fitment slot vocabulary contains duplicate keys');
			}
			$this->coreSlots[$slot['key']] = ['key' => $slot['key'], 'label' => $slot['label'], 'kind' => $slot['kind']];
			$this->reservedSlotRoots[explode('.', $slot['key'], 2)[0]] = true;
		}
		foreach ($decoded['qualifiers'] as $qualifier) {
			if (!is_array($qualifier) || !isset($qualifier['key']) || !is_string($qualifier['key'])) {
				throw new \RuntimeException('The built-in fitment qualifier vocabulary is invalid');
			}
			if (isset($this->coreQualifiers[$qualifier['key']])) {
				throw new \RuntimeException('The built-in fitment qualifier vocabulary contains duplicate keys');
			}
			$this->coreQualifiers[$qualifier['key']] = true;
		}
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{pack:array<string,mixed>,canonicalJson:string,contentHash:string,summary:array<string,int>}
	 */
	public function validate(array $input): array {
		$this->assertEncodedBound($input);
		$this->assertDepth($input, 0);
		$this->known($input, ['schemaVersion','vocabularyVersion','id','version','name','description','dataLicense','provenance','equipment','slots','parts','offers','fitments'], 'fitment pack');
		$this->required($input, ['schemaVersion','vocabularyVersion','id','version','name','description','dataLicense','provenance','equipment','slots','parts','offers','fitments'], 'fitment pack');
		if ($input['schemaVersion'] !== 1) {
			throw new ValidationException('schemaVersion must be 1 for fitment-pack runtime import');
		}
		if ($input['vocabularyVersion'] !== 1) {
			throw new ValidationException('vocabularyVersion must be 1 for fitment-pack runtime import');
		}

		$pack = [
			'schemaVersion' => 1,
			'vocabularyVersion' => 1,
			'id' => $this->globalKey($input['id'], 'id'),
			'version' => $this->semver($input['version']),
			'name' => $this->text($input['name'], 'name', 255),
			'description' => $this->text($input['description'], 'description', 4000, true),
			'dataLicense' => $this->license($input['dataLicense']),
			'provenance' => $this->provenance($input['provenance']),
			'equipment' => $this->equipment($input['equipment']),
			'slots' => $this->slots($input['slots']),
			'parts' => $this->parts($input['parts']),
			'offers' => $this->offers($input['offers']),
			'fitments' => $this->fitments($input['fitments']),
		];
		$this->crossReferences($pack);
		$canonicalJson = $this->canonicalJson($pack);
		if (strlen($canonicalJson) > self::MAX_PACK_BYTES) {
			throw new ValidationException('Fitment pack exceeds the 8 MiB reviewed canonical JSON bound');
		}

		return [
			'pack' => $pack,
			'canonicalJson' => $canonicalJson,
			'contentHash' => hash('sha256', $canonicalJson),
			'summary' => [
				'equipment' => count($pack['equipment']),
				'slots' => count($pack['slots']),
				'parts' => count($pack['parts']),
				'offers' => count($pack['offers']),
				'fitments' => count($pack['fitments']),
			],
		];
	}

	/** @param array<string,mixed> $pack */
	public function canonicalJson(array $pack): string {
		$normalized = $pack;
		foreach ($normalized['equipment'] as &$equipment) {
			$this->sortStrings($equipment['aliases']['manufacturer']);
			$this->sortStrings($equipment['aliases']['model']);
			usort($equipment['identifiers'], static fn (array $a, array $b): int => strcmp($a['namespace'] . "\0" . $a['value'], $b['namespace'] . "\0" . $b['value']));
			foreach ($equipment['qualifiers'] as &$qualifier) {
				$this->sortStrings($qualifier['aliases']);
			}
			unset($qualifier);
			usort($equipment['qualifiers'], static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
		}
		unset($equipment);
		usort($normalized['equipment'], static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
		foreach ($normalized['slots'] as &$slot) {
			$this->sortStrings($slot['aliases']);
		}
		unset($slot);
		usort($normalized['slots'], static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
		usort($normalized['parts'], static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
		usort($normalized['offers'], static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
		foreach ($normalized['fitments'] as &$fitment) {
			usort($fitment['evidence'], static function (array $a, array $b): int {
				return strcmp($a['kind'] . "\0" . ($a['referenceUrl'] ?? '') . "\0" . ($a['note'] ?? ''), $b['kind'] . "\0" . ($b['referenceUrl'] ?? '') . "\0" . ($b['note'] ?? ''));
			});
		}
		unset($fitment);
		usort($normalized['fitments'], static fn (array $a, array $b): int => strcmp($a['equipmentKey'] . "\0" . $a['slotKey'] . "\0" . $a['partKey'], $b['equipmentKey'] . "\0" . $b['slotKey'] . "\0" . $b['partKey']));
		$normalized = $this->sortObjectKeys($normalized);
		try {
			return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		} catch (JsonException $exception) {
			throw new ValidationException('Fitment pack must be JSON encodable', 0, $exception);
		}
	}

	public function normalizeIdentity(string $value): string {
		if (preg_match('/[^\x00-\x7F]/', $value) === 1) {
			if (!class_exists(\Normalizer::class)) {
				throw new ValidationException('Unicode fitment identity matching requires the PHP intl extension');
			}
			$normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
			if (!is_string($normalized)) {
				throw new ValidationException('Unicode fitment identity normalization failed');
			}
			$value = $normalized;
		}
		$value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;
		if (preg_match('/[^\x00-\x7F]/', $value) === 1) {
			if (!function_exists('mb_strtolower')) {
				throw new ValidationException('Unicode fitment identity matching requires the PHP mbstring extension');
			}
			return mb_strtolower($value, 'UTF-8');
		}
		return strtolower($value);
	}

	public function partIdentityHash(string $manufacturer, string $partNumber): string {
		return hash('sha256', $this->normalizeIdentity($manufacturer) . "\0" . $this->normalizeIdentity($partNumber));
	}

	/** @return array<string,mixed> */
	private function provenance(mixed $value): array {
		$a = $this->object($value, 'provenance');
		$this->known($a, ['author','sourceUrl','sourceRevision'], 'provenance');
		$this->required($a, ['author','sourceUrl'], 'provenance');
		$out = ['author' => $this->text($a['author'], 'provenance.author', 255), 'sourceUrl' => $this->httpsUrl($a['sourceUrl'], 'provenance.sourceUrl')];
		if (array_key_exists('sourceRevision', $a)) {
			$out['sourceRevision'] = $this->text($a['sourceRevision'], 'provenance.sourceRevision', 160);
		}
		return $out;
	}

	/** @return list<array<string,mixed>> */
	private function equipment(mixed $value): array {
		$items = $this->listValue($value, 'equipment', 4096, 1);
		$out = [];
		foreach ($items as $i => $value) {
			$field = "equipment[{$i}]";
			$a = $this->object($value, $field);
			$this->known($a, ['key','assetClass','manufacturer','model','yearFrom','yearTo','aliases','identifiers','qualifiers'], $field);
			$this->required($a, ['key','assetClass','manufacturer','model','aliases','identifiers','qualifiers'], $field);
			$aliases = $this->object($a['aliases'], "{$field}.aliases");
			$this->known($aliases, ['manufacturer','model'], "{$field}.aliases");
			$this->required($aliases, ['manufacturer','model'], "{$field}.aliases");
			$n = [
				'key' => $this->shortKey($a['key'], "{$field}.key"),
				'assetClass' => $this->enum($a['assetClass'], "{$field}.assetClass", self::ASSET_CLASSES),
				'manufacturer' => $this->text($a['manufacturer'], "{$field}.manufacturer", 255),
				'model' => $this->text($a['model'], "{$field}.model", 255),
				'aliases' => [
					'manufacturer' => $this->textList($aliases['manufacturer'], "{$field}.aliases.manufacturer", 32, 255),
					'model' => $this->textList($aliases['model'], "{$field}.aliases.model", 64, 255),
				],
				'identifiers' => $this->identifiers($a['identifiers'], "{$field}.identifiers"),
				'qualifiers' => $this->qualifiers($a['qualifiers'], "{$field}.qualifiers"),
			];
			$hasFrom = array_key_exists('yearFrom', $a);
			$hasTo = array_key_exists('yearTo', $a);
			if ($hasFrom !== $hasTo) {
				throw new ValidationException("{$field} must set yearFrom and yearTo together");
			}
			if ($hasFrom) {
				$n['yearFrom'] = $this->integer($a['yearFrom'], "{$field}.yearFrom", 1000, 9999);
				$n['yearTo'] = $this->integer($a['yearTo'], "{$field}.yearTo", 1000, 9999);
				if ($n['yearFrom'] > $n['yearTo']) {
					throw new ValidationException("{$field} yearFrom must not exceed yearTo");
				}
			}
			$this->assertUniqueNormalized($n['aliases']['manufacturer'], "{$field}.aliases.manufacturer");
			$this->assertUniqueNormalized($n['aliases']['model'], "{$field}.aliases.model");
			$out[] = $n;
		}
		$this->uniqueKeys($out, 'equipment');
		return $out;
	}

	/** @return list<array{namespace:string,value:string}> */
	private function identifiers(mixed $value, string $field): array {
		$items = $this->listValue($value, $field, 16);
		$out = [];
		$seen = [];
		foreach ($items as $i => $value) {
			$a = $this->object($value, "{$field}[{$i}]");
			$this->known($a, ['namespace','value'], "{$field}[{$i}]");
			$this->required($a, ['namespace','value'], "{$field}[{$i}]");
			$namespace = $this->globalKey($a['namespace'], "{$field}[{$i}].namespace");
			if (!$this->isReverseDnsNamespace($namespace)) {
				throw new ValidationException("{$field}[{$i}].namespace must be reverse-DNS-style");
			}
			$segments = preg_split('/[._-]+/', $namespace) ?: [];
			foreach ($segments as $segment) {
				if (in_array($segment, self::RESERVED_UNIT_IDENTIFIER_SEGMENTS, true)) {
					throw new ValidationException("{$field}[{$i}] appears to contain unit-specific owned-unit identity rather than reusable model/configuration identity");
				}
			}
			$row = ['namespace' => $namespace, 'value' => $this->text($a['value'], "{$field}[{$i}].value", 255)];
			$key = $row['namespace'] . "\0" . $row['value'];
			if (isset($seen[$key])) {
				throw new ValidationException("{$field} contains a duplicate model/configuration identifier");
			}
			$seen[$key] = true;
			$out[] = $row;
		}
		return $out;
	}

	/** @return list<array<string,mixed>> */
	private function qualifiers(mixed $value, string $field): array {
		$items = $this->listValue($value, $field, 64);
		$out = [];
		foreach ($items as $i => $value) {
			$a = $this->object($value, "{$field}[{$i}]");
			$this->known($a, ['key','value','aliases','match'], "{$field}[{$i}]");
			$this->required($a, ['key','value','aliases','match'], "{$field}[{$i}]");
			$key = $this->globalKey($a['key'], "{$field}[{$i}].key");
			if (!isset($this->coreQualifiers[$key]) && !$this->isNamespacedExtension($key)) {
				throw new ValidationException("{$field}[{$i}].key is neither a core qualifier nor a reverse-DNS-style extension");
			}
			$aliases = $this->textList($a['aliases'], "{$field}[{$i}].aliases", 32, 255);
			$this->assertUniqueNormalized($aliases, "{$field}[{$i}].aliases");
			$out[] = [
				'key' => $key,
				'value' => $this->text($a['value'], "{$field}[{$i}].value", 255),
				'aliases' => $aliases,
				'match' => $this->enum($a['match'], "{$field}[{$i}].match", self::QUALIFIER_MATCH),
			];
		}
		$this->uniqueKeys($out, $field);
		return $out;
	}

	/** @return list<array<string,mixed>> */
	private function slots(mixed $value): array {
		$items = $this->listValue($value, 'slots', 2048, 1);
		$out = [];
		foreach ($items as $i => $value) {
			$field = "slots[{$i}]";
			$a = $this->object($value, $field);
			$this->known($a, ['key','label','kind','aliases'], $field);
			$this->required($a, ['key','label','kind','aliases'], $field);
			$key = $this->globalKey($a['key'], "{$field}.key");
			$kind = $this->shortKey($a['kind'], "{$field}.kind");
			if (isset($this->coreSlots[$key])) {
				if ($this->coreSlots[$key]['kind'] !== $kind) {
					throw new ValidationException("slot {$key} kind {$kind} disagrees with core vocabulary kind {$this->coreSlots[$key]['kind']}");
				}
			} else {
				$root = explode('.', $key, 2)[0];
				if (isset($this->reservedSlotRoots[$root]) || !$this->isNamespacedExtension($key)) {
					throw new ValidationException("slot {$key} must use an existing core key or a reverse-DNS-style extension namespace");
				}
			}
			$aliases = $this->textList($a['aliases'], "{$field}.aliases", 64, 255);
			$this->assertUniqueNormalized($aliases, "{$field}.aliases");
			$out[] = ['key' => $key, 'label' => $this->text($a['label'], "{$field}.label", 255), 'kind' => $kind, 'aliases' => $aliases];
		}
		$this->uniqueKeys($out, 'slots');
		return $out;
	}

	/** @return list<array<string,string>> */
	private function parts(mixed $value): array {
		$items = $this->listValue($value, 'parts', 20000, 1);
		$out = [];
		$identities = [];
		foreach ($items as $i => $value) {
			$field = "parts[{$i}]";
			$a = $this->object($value, $field);
			$this->known($a, ['key','manufacturer','partNumber','description'], $field);
			$this->required($a, ['key','manufacturer','partNumber','description'], $field);
			$row = [
				'key' => $this->shortKey($a['key'], "{$field}.key"),
				'manufacturer' => $this->text($a['manufacturer'], "{$field}.manufacturer", 255),
				'partNumber' => $this->text($a['partNumber'], "{$field}.partNumber", 128),
				'description' => $this->text($a['description'], "{$field}.description", 1000, true),
			];
			$identity = $this->normalizeIdentity($row['manufacturer']) . "\0" . $this->normalizeIdentity($row['partNumber']);
			if (isset($identities[$identity])) {
				throw new ValidationException('duplicate part identity after conservative case/whitespace normalization: ' . $row['manufacturer'] . ' / ' . $row['partNumber']);
			}
			$identities[$identity] = true;
			$out[] = $row;
		}
		$this->uniqueKeys($out, 'parts');
		return $out;
	}

	/** @return list<array<string,mixed>> */
	private function offers(mixed $value): array {
		$items = $this->listValue($value, 'offers', 50000);
		$out = [];
		foreach ($items as $i => $value) {
			$field = "offers[{$i}]";
			$a = $this->object($value, $field);
			$this->known($a, ['key','partKey','label','sku','url'], $field);
			$this->required($a, ['key','partKey','label','url'], $field);
			$row = [
				'key' => $this->shortKey($a['key'], "{$field}.key"),
				'partKey' => $this->shortKey($a['partKey'], "{$field}.partKey"),
				'label' => $this->text($a['label'], "{$field}.label", 255),
				'url' => $this->httpsUrl($a['url'], "{$field}.url"),
			];
			if (array_key_exists('sku', $a)) {
				$row['sku'] = $this->text($a['sku'], "{$field}.sku", 128);
			}
			$out[] = $row;
		}
		$this->uniqueKeys($out, 'offers');
		return $out;
	}

	/** @return list<array<string,mixed>> */
	private function fitments(mixed $value): array {
		$items = $this->listValue($value, 'fitments', 100000, 1);
		$out = [];
		$tuples = [];
		foreach ($items as $i => $value) {
			$field = "fitments[{$i}]";
			$a = $this->object($value, $field);
			$this->known($a, ['equipmentKey','slotKey','partKey','relation','verification','evidence','notes'], $field);
			$this->required($a, ['equipmentKey','slotKey','partKey','relation','verification','evidence'], $field);
			$evidence = $this->evidence($a['evidence'], "{$field}.evidence");
			$verification = $this->enum($a['verification'], "{$field}.verification", self::VERIFICATION);
			if ($verification === 'verified' && $evidence === []) {
				throw new ValidationException("verified {$field} requires evidence");
			}
			$row = [
				'equipmentKey' => $this->shortKey($a['equipmentKey'], "{$field}.equipmentKey"),
				'slotKey' => $this->globalKey($a['slotKey'], "{$field}.slotKey"),
				'partKey' => $this->shortKey($a['partKey'], "{$field}.partKey"),
				'relation' => $this->enum($a['relation'], "{$field}.relation", self::RELATIONS),
				'verification' => $verification,
				'evidence' => $evidence,
			];
			if (array_key_exists('notes', $a)) {
				$row['notes'] = $this->text($a['notes'], "{$field}.notes", 20000, true, true);
			}
			$tuple = $row['equipmentKey'] . "\0" . $row['slotKey'] . "\0" . $row['partKey'];
			if (isset($tuples[$tuple])) {
				throw new ValidationException('duplicate fitment tuple: ' . $row['equipmentKey'] . '/' . $row['slotKey'] . '/' . $row['partKey']);
			}
			$tuples[$tuple] = true;
			$out[] = $row;
		}
		return $out;
	}

	/** @return list<array<string,string>> */
	private function evidence(mixed $value, string $field): array {
		$items = $this->listValue($value, $field, 16);
		$out = [];
		$seen = [];
		foreach ($items as $i => $value) {
			$a = $this->object($value, "{$field}[{$i}]");
			$this->known($a, ['kind','referenceUrl','note'], "{$field}[{$i}]");
			$this->required($a, ['kind'], "{$field}[{$i}]");
			$row = ['kind' => $this->enum($a['kind'], "{$field}[{$i}].kind", self::EVIDENCE_KINDS)];
			if (array_key_exists('referenceUrl', $a)) {
				$row['referenceUrl'] = $this->httpsUrl($a['referenceUrl'], "{$field}[{$i}].referenceUrl");
			}
			if (array_key_exists('note', $a)) {
				$row['note'] = $this->text($a['note'], "{$field}[{$i}].note", 1000, true);
			}
			$key = $row['kind'] . "\0" . ($row['referenceUrl'] ?? '') . "\0" . ($row['note'] ?? '');
			if (isset($seen[$key])) {
				throw new ValidationException("{$field} contains duplicate evidence");
			}
			$seen[$key] = true;
			$out[] = $row;
		}
		return $out;
	}

	/** @param array<string,mixed> $pack */
	private function crossReferences(array $pack): void {
		$equipment = $this->byKey($pack['equipment']);
		$slots = $this->byKey($pack['slots']);
		$parts = $this->byKey($pack['parts']);
		foreach ($pack['offers'] as $offer) {
			if (!isset($parts[$offer['partKey']])) {
				throw new ValidationException("offer {$offer['key']} references unknown part {$offer['partKey']}");
			}
		}
		foreach ($pack['fitments'] as $fitment) {
			if (!isset($equipment[$fitment['equipmentKey']])) {
				throw new ValidationException("fitment references unknown equipment {$fitment['equipmentKey']}");
			}
			if (!isset($slots[$fitment['slotKey']])) {
				throw new ValidationException("fitment references unknown slot {$fitment['slotKey']}");
			}
			if (!isset($parts[$fitment['partKey']])) {
				throw new ValidationException("fitment references unknown part {$fitment['partKey']}");
			}
		}
	}

	/** @param list<array<string,mixed>> $items @return array<string,array<string,mixed>> */
	private function byKey(array $items): array {
		$out = [];
		foreach ($items as $item) {
			$out[$item['key']] = $item;
		}
		return $out;
	}

	/** @param list<array<string,mixed>> $items */
	private function uniqueKeys(array $items, string $field): void {
		$seen = [];
		foreach ($items as $item) {
			$key = (string)$item['key'];
			if (isset($seen[$key])) {
				throw new ValidationException("{$field} contains duplicate key {$key}");
			}
			$seen[$key] = true;
		}
	}

	/** @param list<string> $values */
	private function assertUniqueNormalized(array $values, string $field): void {
		$seen = [];
		foreach ($values as $value) {
			$normalized = $this->normalizeIdentity($value);
			if (isset($seen[$normalized])) {
				throw new ValidationException("{$field} contains duplicate aliases after conservative normalization");
			}
			$seen[$normalized] = true;
		}
	}

	/** @param list<string> $values */
	private function sortStrings(array &$values): void {
		usort($values, 'strcmp');
	}

	private function isReverseDnsNamespace(string $key): bool {
		return preg_match('/^[a-z0-9][a-z0-9-]*(?:\.[a-z0-9][a-z0-9-]*)+$/D', $key) === 1;
	}

	private function isNamespacedExtension(string $key): bool {
		return preg_match('/^[a-z0-9][a-z0-9-]*(?:\.[a-z0-9][a-z0-9_-]*){2,}$/D', $key) === 1;
	}

	/** @return list<string> */
	private function textList(mixed $value, string $field, int $maxItems, int $maxLength): array {
		$items = $this->listValue($value, $field, $maxItems);
		$out = [];
		foreach ($items as $i => $item) {
			$out[] = $this->text($item, "{$field}[{$i}]", $maxLength);
		}
		return $out;
	}

	/** @return list<mixed> */
	private function listValue(mixed $value, string $field, int $max, int $min = 0): array {
		if (!is_array($value) || !array_is_list($value)) {
			throw new ValidationException("{$field} must be an array");
		}
		$count = count($value);
		if ($count < $min || $count > $max) {
			throw new ValidationException("{$field} item count is outside the reviewed bound");
		}
		return $value;
	}

	/** @return array<string,mixed> */
	private function object(mixed $value, string $field): array {
		if (!is_array($value) || array_is_list($value)) {
			throw new ValidationException("{$field} must be an object");
		}
		return $value;
	}

	/** @param array<string,mixed> $input @param list<string> $required */
	private function required(array $input, array $required, string $field): void {
		foreach ($required as $key) {
			if (!array_key_exists($key, $input)) {
				throw new ValidationException("{$field}.{$key} is required");
			}
		}
	}

	/** @param array<string,mixed> $input @param list<string> $allowed */
	private function known(array $input, array $allowed, string $field): void {
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) {
			throw new ValidationException("{$field} contains unknown fields: " . implode(', ', $unknown));
		}
	}

	private function text(mixed $value, string $field, int $max, bool $newlines = false, bool $allowEmpty = false): string {
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be a string");
		}
		if (!$allowEmpty && $value === '') {
			throw new ValidationException("{$field} cannot be empty");
		}
		if ($this->length($value) > $max) {
			throw new ValidationException("{$field} exceeds {$max} characters");
		}
		$pattern = $newlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
		if (preg_match($pattern, $value) === 1) {
			throw new ValidationException("{$field} contains unsupported control characters");
		}
		return $value;
	}

	private function shortKey(mixed $value, string $field): string {
		$s = $this->text($value, $field, 64);
		if (preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $s) !== 1) {
			throw new ValidationException("{$field} must be a lowercase short key");
		}
		return $s;
	}

	private function globalKey(mixed $value, string $field): string {
		$s = $this->text($value, $field, 160);
		if (strlen($s) < 3 || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $s) !== 1) {
			throw new ValidationException("{$field} must be a lowercase global key");
		}
		return $s;
	}

	private function semver(mixed $value): string {
		$s = $this->text($value, 'version', 32);
		if (preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/D', $s) !== 1) {
			throw new ValidationException('version must be a semantic version');
		}
		return $s;
	}

	private function license(mixed $value): string {
		$s = $this->text($value, 'dataLicense', 64);
		if (preg_match('/^[A-Za-z0-9.+-]+$/D', $s) !== 1) {
			throw new ValidationException('dataLicense must be an SPDX-style identifier');
		}
		return $s;
	}

	private function httpsUrl(mixed $value, string $field): string {
		$s = $this->text($value, $field, 2048);
		if (!str_starts_with(strtolower($s), 'https://') || filter_var($s, FILTER_VALIDATE_URL) === false) {
			throw new ValidationException("{$field} must be an HTTPS URL");
		}
		return $s;
	}

	/** @param list<string> $allowed */
	private function enum(mixed $value, string $field, array $allowed): string {
		if (!is_string($value) || !in_array($value, $allowed, true)) {
			throw new ValidationException("{$field} is not supported");
		}
		return $value;
	}

	private function integer(mixed $value, string $field, int $min, int $max): int {
		if (!is_int($value) || $value < $min || $value > $max) {
			throw new ValidationException("{$field} must be an integer between {$min} and {$max}");
		}
		return $value;
	}

	private function length(string $value): int {
		return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
	}

	/** @param array<string,mixed> $input */
	private function assertEncodedBound(array $input): void {
		try {
			$json = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} catch (JsonException $exception) {
			throw new ValidationException('Fitment pack must be JSON encodable', 0, $exception);
		}
		if (strlen($json) > self::MAX_PACK_BYTES) {
			throw new ValidationException('Fitment pack exceeds the 8 MiB reviewed input bound');
		}
	}

	private function assertDepth(mixed $value, int $depth): void {
		if ($depth > self::MAX_DEPTH) {
			throw new ValidationException('Fitment pack nesting exceeds the reviewed depth bound');
		}
		if (is_array($value)) {
			foreach ($value as $child) {
				$this->assertDepth($child, $depth + 1);
			}
		}
	}

	private function sortObjectKeys(mixed $value): mixed {
		if (!is_array($value)) {
			return $value;
		}
		if (array_is_list($value)) {
			return array_map(fn (mixed $item): mixed => $this->sortObjectKeys($item), $value);
		}
		uksort($value, 'strcmp');
		foreach ($value as $key => $child) {
			$value[$key] = $this->sortObjectKeys($child);
		}
		return $value;
	}
}
