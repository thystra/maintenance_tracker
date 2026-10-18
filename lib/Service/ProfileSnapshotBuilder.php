<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Model\WorkspaceContext;

/**
 * Builds a profile-v2 document from an item's setup: meters, components,
 * specifications, parts that fit, task groups, tasks with their schedules,
 * and fuel regimes and settings. History (readings, maintenance records,
 * fill-ups, costs, files) is never part of a profile. Retired components
 * and tasks are left out. Record UUIDs are replaced by profile keys.
 */
class ProfileSnapshotBuilder {
	public function __construct(
		private AssetService $assets,
		private MeterService $meters,
		private ComponentService $components,
		private SpecificationService $specifications,
		private WorkGroupService $groups,
		private WorkDefinitionService $definitions,
		private PartService $parts,
		private FuelService $fuel,
	) {
	}

	/**
	 * @param array{id:string,version:string,name:string,description:string,author:string} $meta
	 * @return array<string,mixed> unvalidated profile document
	 */
	public function fromAsset(WorkspaceContext $context, string $assetUuid, array $meta): array {
		$asset = $this->assets->find($context, $assetUuid);
		$used = [];

		$meterKeys = [];
		$meters = [];
		foreach ($this->meters->list($context, $asset->getUuid()) as $m) {
			$key = self::uniqueKey((string)$m['key'], $used['meter']);
			$meterKeys[(string)$m['uuid']] = $key;
			$meters[] = ['key' => $key, 'name' => (string)$m['name'], 'dimension' => (string)$m['dimension'], 'displayUnit' => (string)$m['displayUnit'], 'monotonic' => (bool)$m['monotonic']];
		}

		$componentKeys = [];
		$componentRows = [];
		foreach ($this->components->list($context, $asset->getUuid()) as $c) {
			if (!in_array($c['status'] ?? 'active', ['active', 'suppressed'], true)) {
				continue;
			}
			$componentKeys[(string)$c['uuid']] = self::uniqueKey((string)($c['type'] ?: $c['name']), $used['component']);
			$componentRows[] = $c;
		}

		// Parts that fit, keyed by subject; only parts with a manufacturer and
		// part number can be expressed in a profile.
		$partKeys = [];
		$parts = [];
		$partsBySubject = [];
		foreach ($this->parts->compatibilityForAsset($context, $asset->getUuid()) as $fit) {
			$part = $fit['part'];
			if ($part['archived'] || trim($part['manufacturer']) === '' || trim($part['partNumber']) === '') {
				continue;
			}
			if (!isset($partKeys[$part['uuid']])) {
				$partKeys[$part['uuid']] = self::uniqueKey($part['partNumber'], $used['part']);
				$parts[] = ['key' => $partKeys[$part['uuid']], 'manufacturer' => $part['manufacturer'], 'partNumber' => $part['partNumber'], 'description' => trim($part['description']) !== '' ? $part['description'] : $part['partNumber'], 'offers' => []];
			}
			$partsBySubject[$fit['subjectType'] . ':' . $fit['subjectUuid']][] = $partKeys[$part['uuid']];
		}

		$components = [];
		foreach ($componentRows as $c) {
			$item = [
				'key' => $componentKeys[(string)$c['uuid']],
				'name' => (string)$c['name'],
				'quantity' => 1,
				'enabledByDefault' => ($c['status'] ?? 'active') === 'active',
				'compatiblePartKeys' => array_values(array_unique($partsBySubject['component:' . $c['uuid']] ?? [])),
			];
			if (($c['parentUuid'] ?? null) !== null && isset($componentKeys[(string)$c['parentUuid']])) {
				$item['parentKey'] = $componentKeys[(string)$c['parentUuid']];
			}
			if (($c['notes'] ?? null) !== null && trim((string)$c['notes']) !== '') {
				$item['notes'] = (string)$c['notes'];
			}
			$components[] = $item;
		}

		$specifications = [];
		foreach ($this->specifications->list($context, $asset->getUuid()) as $s) {
			$componentUuid = $s['componentUuid'] ?? null;
			if ($componentUuid !== null && !isset($componentKeys[$componentUuid])) {
				continue;
			}
			$value = $s['value'];
			if (!is_scalar($value)) {
				$value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
			if ($value === '' || $value === false || $value === null) {
				continue;
			}
			$spec = ['key' => (string)$s['key'], 'label' => (string)$s['label'], 'value' => $value];
			if (($s['unit'] ?? null) !== null && $s['unit'] !== '') $spec['unit'] = (string)$s['unit'];
			if ($componentUuid !== null) $spec['componentKey'] = $componentKeys[$componentUuid];
			if (($s['regime'] ?? null) !== null && preg_match('/^[a-z0-9][a-z0-9_-]*$/D', (string)$s['regime']) === 1) $spec['regime'] = (string)$s['regime'];
			$specifications[] = $spec;
		}
		$specifications = self::uniqueSpecifications($specifications);

		$groupKeys = [];
		$groups = [];
		foreach ($this->groups->list($context, $asset->getUuid()) as $g) {
			$groupKeys[(string)$g['uuid']] = self::uniqueKey((string)$g['key'], $used['group']);
			$group = ['key' => $groupKeys[(string)$g['uuid']], 'name' => (string)$g['name'], 'sortOrder' => (int)$g['sortOrder']];
			if (($g['description'] ?? null) !== null && trim((string)$g['description']) !== '') $group['description'] = (string)$g['description'];
			$groups[] = $group;
		}

		$definitions = [];
		foreach ($this->definitions->list($context, $asset->getUuid()) as $d) {
			if (!in_array($d['status'], ['active', 'suppressed'], true)) {
				continue;
			}
			$item = [
				'key' => self::uniqueKey((string)$d['key'], $used['definition']),
				'title' => (string)$d['title'],
				'kind' => self::slug((string)$d['kind']),
				'enabledByDefault' => $d['status'] === 'active',
				'schedule' => self::profileSchedule($d['schedule'], $meterKeys),
				'compatiblePartKeys' => array_values(array_unique($partsBySubject['work_definition:' . $d['uuid']] ?? [])),
			];
			if ($d['groupUuid'] !== null && isset($groupKeys[$d['groupUuid']])) $item['groupKey'] = $groupKeys[$d['groupUuid']];
			if ($d['componentUuid'] !== null && isset($componentKeys[$d['componentUuid']])) $item['componentKey'] = $componentKeys[$d['componentUuid']];
			foreach (['instructions', 'notes'] as $field) {
				if (($d[$field] ?? null) !== null && trim((string)$d[$field]) !== '') $item[$field] = (string)$d[$field];
			}
			$definitions[] = $item;
		}

		$settings = $this->fuel->settings($context, $asset->getUuid());
		$regimes = [];
		foreach ($this->fuel->regimes($context, $asset->getUuid()) as $r) {
			$regimes[] = ['key' => self::uniqueKey((string)$r['name'], $used['regime']), 'name' => (string)$r['name'], 'isDefault' => (bool)$r['isDefault']];
		}
		if ($regimes !== [] && count(array_filter($regimes, static fn (array $r): bool => $r['isDefault'])) !== 1) {
			foreach ($regimes as $i => $r) $regimes[$i]['isDefault'] = $i === 0;
		}
		$fuelSettings = ['distanceUnit' => $settings['distanceUnit'], 'volumeUnit' => $settings['volumeUnit'], 'economyUnit' => $settings['economyUnit']];
		if ($settings['fuelType'] !== null) $fuelSettings['fuelType'] = $settings['fuelType'];

		$profile = [
			'schemaVersion' => 2,
			'id' => $meta['id'],
			'version' => $meta['version'],
			'name' => $meta['name'],
			'category' => self::slug($asset->getCategoryKey() ?: $asset->getAssetClass()),
			'description' => $meta['description'],
			'dataLicense' => 'NOASSERTION',
			'provenance' => ['author' => $meta['author']],
			'applicability' => [],
			'meters' => $meters,
			'components' => $components,
			'parts' => $parts,
			'workGroups' => $groups,
			'workDefinitions' => $definitions,
			'specifications' => $specifications,
		];
		// Fuel settings are only carried when the item uses fuel: it has
		// regimes, its own settings, or its Fuel tab is shown.
		if ($regimes !== [] || (int)$settings['revision'] > 0 || $settings['shown']) {
			$profile['fuel'] = ['settings' => $fuelSettings, 'regimes' => $regimes];
		}
		return $profile;
	}

	/**
	 * @param string|array<string,mixed> $schedule
	 * @param array<string,string> $meterKeys
	 * @return string|array<string,mixed>
	 */
	private static function profileSchedule(string|array $schedule, array $meterKeys): string|array {
		if ($schedule === 'none' || !is_array($schedule)) {
			return 'none';
		}
		$rules = [];
		foreach ($schedule['rules'] as $rule) {
			if ($rule['type'] === 'meter') {
				if (!isset($meterKeys[$rule['meterUuid']])) {
					continue;
				}
				$rules[] = ['type' => 'meter', 'meterKey' => $meterKeys[$rule['meterUuid']], 'interval' => ['value' => (string)$rule['interval']['value'], 'unit' => $rule['interval']['unit']]];
			} elseif ($rule['type'] === 'business_days') {
				$rules[] = ['type' => 'business_days', 'interval' => $rule['interval'], 'weekdays' => $rule['weekdays']];
			} else {
				$rules[] = ['type' => 'calendar', 'interval' => $rule['interval']];
			}
		}
		return $rules === [] ? 'none' : ['combination' => 'any', 'rules' => $rules];
	}

	/**
	 * @param list<array<string,mixed>> $specifications
	 * @return list<array<string,mixed>>
	 */
	private static function uniqueSpecifications(array $specifications): array {
		$seen = [];
		$out = [];
		foreach ($specifications as $spec) {
			$identity = $spec['key'] . '|' . ($spec['componentKey'] ?? '');
			if (!isset($seen[$identity])) {
				$seen[$identity] = true;
				$out[] = $spec;
			}
		}
		return $out;
	}

	/** A lowercase profile key from any text: "Oil filter #2" becomes "oil_filter_2". */
	public static function slug(string $value): string {
		$value = strtolower(trim($value));
		$value = (string)preg_replace('/[^a-z0-9_-]+/', '_', $value);
		$value = trim($value, '_-');
		$value = substr($value, 0, 56);
		return $value === '' ? 'item' : $value;
	}

	/** @param array<string,true>|null $used */
	private static function uniqueKey(string $value, ?array &$used): string {
		$used ??= [];
		$base = self::slug($value);
		$key = $base;
		for ($n = 2; isset($used[$key]); ++$n) {
			$key = $base . '_' . $n;
		}
		$used[$key] = true;
		return $key;
	}
}
