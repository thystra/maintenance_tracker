<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use JsonException;
use OCA\MaintenanceTracker\Exception\ValidationException;

final class ProfileCatalog {
	public function __construct(private ProfileValidator $validator) {
	}

	/** @return list<array<string,mixed>> */
	public function listBundled(): array {
		$items = [];
		foreach ($this->bundledProfiles() as $entry) {
			$v = $entry['validated'];
			$items[] = [
				'id' => $v['profile']['id'],
				'version' => $v['profile']['version'],
				'name' => $v['profile']['name'],
				'category' => $v['profile']['category'],
				'description' => $v['profile']['description'],
				'dataLicense' => $v['profile']['dataLicense'],
				'provenance' => $v['profile']['provenance'],
				'applicability' => $v['profile']['applicability'],
				'contentHash' => $v['contentHash'],
				'summary' => $v['summary'],
				'origin' => 'bundled',
				'trustState' => 'first_party',
				'profile' => $v['profile'],
			];
		}
		usort($items, static fn (array $a, array $b): int => [$a['name'],$a['id'],$a['version']] <=> [$b['name'],$b['id'],$b['version']]);
		return $items;
	}

	/** @param array<string,mixed> $validatedProfile @return array{origin:string,trustState:string} */
	public function classify(array $validatedProfile, string $contentHash): array {
		foreach ($this->bundledProfiles() as $entry) {
			$v = $entry['validated'];
			if ($v['profile']['id'] === $validatedProfile['id']
				&& $v['profile']['version'] === $validatedProfile['version']
				&& hash_equals($v['contentHash'], $contentHash)) {
				return ['origin' => 'bundled', 'trustState' => 'first_party'];
			}
		}
		return ['origin' => 'local', 'trustState' => 'local'];
	}

	/** @return list<array{path:string,validated:array<string,mixed>}> */
	private function bundledProfiles(): array {
		$root = dirname(__DIR__, 2) . '/profiles';
		$paths = glob($root . '/*.json') ?: [];
		sort($paths, SORT_STRING);
		$out = [];
		foreach ($paths as $path) {
			$raw = file_get_contents($path);
			if (!is_string($raw)) {
				throw new \RuntimeException('Unable to read bundled profile ' . basename($path));
			}
			if (strlen($raw) > ProfileValidator::MAX_PROFILE_BYTES) {
				throw new \RuntimeException('Bundled profile exceeds the reviewed size bound: ' . basename($path));
			}
			try {
				$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
			} catch (JsonException $exception) {
				throw new \RuntimeException('Bundled profile contains invalid JSON: ' . basename($path), 0, $exception);
			}
			if (!is_array($decoded) || array_is_list($decoded)) {
				throw new ValidationException('Bundled profile root must be an object');
			}
			$out[] = ['path' => $path, 'validated' => $this->validator->validate($decoded)];
		}
		return $out;
	}
}
