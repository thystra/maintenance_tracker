<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Asset;

final class AssetFitmentDescriptorService {
	public function __construct(private FitmentPackValidator $validator) {
	}

	/**
	 * Build the privacy-safe generalized descriptor used for fitment matching.
	 * Local UUID, display name, serial number, notes and financial/history data
	 * are intentionally absent.
	 *
	 * @return array{assetClass:string,manufacturer:string|null,model:string|null,modelYear:int|null,qualifiers:array<string,string>}
	 */
	public function describe(Asset $asset): array {
		return [
			'assetClass' => $asset->getAssetClass(),
			'manufacturer' => $asset->getManufacturer(),
			'model' => $asset->getModel(),
			'modelYear' => $asset->getModelYear(),
			'qualifiers' => [],
		];
	}

	/**
	 * @param array<string,mixed> $target normalized/imported target descriptor
	 * @return array{state:string,reasons:list<array{field:string,result:string,detail:string}>}
	 */
	public function match(Asset $asset, array $target): array {
		$descriptor = $this->describe($asset);
		$reasons = [];
		$conflict = false;
		$insufficient = false;
		$candidate = false;

		if ($descriptor['assetClass'] !== ($target['assetClass'] ?? null)) {
			$conflict = true;
			$reasons[] = $this->reason('assetClass', 'conflict', 'Asset class differs from the fitment target');
		} else {
			$reasons[] = $this->reason('assetClass', 'match', 'Asset class matches');
		}

		$manufacturer = $descriptor['manufacturer'];
		if ($manufacturer === null || trim($manufacturer) === '') {
			$insufficient = true;
			$reasons[] = $this->reason('manufacturer', 'missing', 'Local asset manufacturer is not known');
		} else {
			$expected = (string)($target['manufacturer'] ?? '');
			if ($this->same($manufacturer, $expected)) {
				$reasons[] = $this->reason('manufacturer', 'match', 'Manufacturer matches');
			} elseif ($this->matchesAlias($manufacturer, $target['aliases']['manufacturer'] ?? [])) {
				$candidate = true;
				$reasons[] = $this->reason('manufacturer', 'alias', 'Manufacturer matches an imported alias');
			} else {
				$conflict = true;
				$reasons[] = $this->reason('manufacturer', 'conflict', 'Manufacturer differs from the fitment target');
			}
		}

		$model = $descriptor['model'];
		if ($model === null || trim($model) === '') {
			$insufficient = true;
			$reasons[] = $this->reason('model', 'missing', 'Local asset model is not known');
		} else {
			$expected = (string)($target['model'] ?? '');
			if ($this->same($model, $expected)) {
				$reasons[] = $this->reason('model', 'match', 'Model matches');
			} elseif ($this->matchesAlias($model, $target['aliases']['model'] ?? [])) {
				$candidate = true;
				$reasons[] = $this->reason('model', 'alias', 'Model matches an imported alias');
			} else {
				$conflict = true;
				$reasons[] = $this->reason('model', 'conflict', 'Model differs from the fitment target');
			}
		}

		$yearFrom = $target['yearFrom'] ?? null;
		$yearTo = $target['yearTo'] ?? null;
		if ($yearFrom !== null || $yearTo !== null) {
			$year = $descriptor['modelYear'];
			if ($year === null) {
				$insufficient = true;
				$reasons[] = $this->reason('modelYear', 'missing', 'Local asset model year is not known');
			} elseif (($yearFrom !== null && $year < (int)$yearFrom) || ($yearTo !== null && $year > (int)$yearTo)) {
				$conflict = true;
				$reasons[] = $this->reason('modelYear', 'conflict', 'Model year is outside the fitment target range');
			} else {
				$reasons[] = $this->reason('modelYear', 'match', 'Model year is within the fitment target range');
			}
		}

		$localQualifiers = $descriptor['qualifiers'];
		foreach (($target['qualifiers'] ?? []) as $qualifier) {
			if (!is_array($qualifier) || !isset($qualifier['key'], $qualifier['value'])) {
				continue;
			}
			$key = (string)$qualifier['key'];
			$matchPolicy = (string)($qualifier['match'] ?? 'required');
			$actual = $localQualifiers[$key] ?? null;
			if ($actual === null) {
				if ($matchPolicy === 'required') {
					$candidate = true;
					$reasons[] = $this->reason('qualifier:' . $key, 'unknown', 'Required fitment qualifier is not represented by the local asset yet');
				}
				continue;
			}
			if ($this->same($actual, (string)$qualifier['value']) || $this->matchesAlias($actual, $qualifier['aliases'] ?? [])) {
				$reasons[] = $this->reason('qualifier:' . $key, 'match', 'Fitment qualifier matches');
			} elseif ($matchPolicy === 'required') {
				$conflict = true;
				$reasons[] = $this->reason('qualifier:' . $key, 'conflict', 'Required fitment qualifier differs');
			} else {
				$candidate = true;
				$reasons[] = $this->reason('qualifier:' . $key, 'different', 'Optional fitment qualifier differs');
			}
		}

		$state = $conflict ? 'conflict' : ($insufficient ? 'insufficient' : ($candidate ? 'candidate' : 'exact'));
		return ['state' => $state, 'reasons' => $reasons];
	}

	private function same(string $left, string $right): bool {
		return hash_equals($this->validator->normalizeIdentity($left), $this->validator->normalizeIdentity($right));
	}

	/** @param mixed $aliases */
	private function matchesAlias(string $value, mixed $aliases): bool {
		if (!is_array($aliases)) {
			return false;
		}
		foreach ($aliases as $alias) {
			if (is_string($alias) && $this->same($value, $alias)) {
				return true;
			}
		}
		return false;
	}

	/** @return array{field:string,result:string,detail:string} */
	private function reason(string $field, string $result, string $detail): array {
		return ['field' => $field, 'result' => $result, 'detail' => $detail];
	}
}
