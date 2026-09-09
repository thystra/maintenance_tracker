<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use JsonException;
use OCA\MaintenanceTracker\Exception\ValidationException;

final class FitmentCsvBundleService {
	public const FORMAT = 'maintenance-tracker-fitment-csv-bundle';
	public const FORMAT_VERSION = 1;
	public const SPREADSHEET_ESCAPE = 'leading-apostrophe-v1';
	public const MAX_EXPANDED_BYTES = 32 * 1024 * 1024;
	public const MAX_TOTAL_DATA_ROWS = 250000;

	/** @var list<string> */
	private const SPREADSHEET_DANGEROUS_PREFIXES = ['=', '+', '-', '@', "'", "\t", "\r", "\n", '＝', '＋', '－', '＠'];

	/** @var array<string,list<string>> */
	private const HEADERS = [
		'equipment.csv' => ['key','assetClass','manufacturer','model','yearFrom','yearTo'],
		'equipment_aliases.csv' => ['equipmentKey','field','alias'],
		'equipment_identifiers.csv' => ['equipmentKey','namespace','value'],
		'equipment_qualifiers.csv' => ['equipmentKey','key','value','match'],
		'qualifier_aliases.csv' => ['equipmentKey','qualifierKey','alias'],
		'slots.csv' => ['key','label','kind'],
		'slot_aliases.csv' => ['slotKey','alias'],
		'parts.csv' => ['key','manufacturer','partNumber','description'],
		'offers.csv' => ['key','partKey','label','sku','url'],
		'fitments.csv' => ['equipmentKey','slotKey','partKey','relation','verification','notesPresent','notes'],
		'fitment_evidence.csv' => ['equipmentKey','slotKey','partKey','kind','referenceUrl','note'],
	];

	/** @var array<string,int> */
	private const ROW_LIMITS = [
		'equipment.csv' => 4096,
		'equipment_aliases.csv' => 250000,
		'equipment_identifiers.csv' => 65536,
		'equipment_qualifiers.csv' => 250000,
		'qualifier_aliases.csv' => 250000,
		'slots.csv' => 2048,
		'slot_aliases.csv' => 131072,
		'parts.csv' => 20000,
		'offers.csv' => 50000,
		'fitments.csv' => 100000,
		'fitment_evidence.csv' => 250000,
	];

	public function __construct(private FitmentPackValidator $validator) {
	}

	/** @return list<string> */
	public function fileNames(): array {
		return ['manifest.json', ...array_keys(self::HEADERS)];
	}

	/**
	 * @param array<string,mixed> $pack
	 * @return array<string,string>
	 */
	public function exportFiles(array $pack): array {
		$v = $this->validator->validate($pack);
		try {
			/** @var mixed $canonical */
			$canonical = json_decode($v['canonicalJson'], true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new \LogicException('Canonical fitment JSON could not be decoded', 0, $exception);
		}
		if (!is_array($canonical) || array_is_list($canonical)) {
			throw new \LogicException('Canonical fitment JSON is not an object');
		}
		$pack = $canonical;

		$manifest = [
			'format' => self::FORMAT,
			'formatVersion' => self::FORMAT_VERSION,
			'schemaVersion' => 1,
			'vocabularyVersion' => 1,
			'pack' => [
				'id' => $pack['id'],
				'version' => $pack['version'],
				'name' => $pack['name'],
				'description' => $pack['description'],
				'dataLicense' => $pack['dataLicense'],
				'provenance' => $pack['provenance'],
			],
			'csv' => [
				'encoding' => 'UTF-8',
				'delimiter' => ',',
				'enclosure' => '"',
				'quoting' => 'rfc4180-double-quote',
				'recordSeparator' => 'LF-or-CRLF',
				'spreadsheetEscape' => self::SPREADSHEET_ESCAPE,
			],
		];
		try {
			$manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		} catch (JsonException $exception) {
			throw new \LogicException('Fitment bundle manifest could not be encoded', 0, $exception);
		}

		/** @var array<string,list<list<string>>> $rows */
		$rows = [];
		foreach (self::HEADERS as $name => $_header) {
			$rows[$name] = [];
		}

		foreach ($pack['equipment'] as $equipment) {
			$rows['equipment.csv'][] = [
				(string)$equipment['key'],
				(string)$equipment['assetClass'],
				(string)$equipment['manufacturer'],
				(string)$equipment['model'],
				array_key_exists('yearFrom', $equipment) ? (string)$equipment['yearFrom'] : '',
				array_key_exists('yearTo', $equipment) ? (string)$equipment['yearTo'] : '',
			];
			foreach ($equipment['aliases']['manufacturer'] as $alias) {
				$rows['equipment_aliases.csv'][] = [(string)$equipment['key'], 'manufacturer', (string)$alias];
			}
			foreach ($equipment['aliases']['model'] as $alias) {
				$rows['equipment_aliases.csv'][] = [(string)$equipment['key'], 'model', (string)$alias];
			}
			foreach ($equipment['identifiers'] as $identifier) {
				$rows['equipment_identifiers.csv'][] = [(string)$equipment['key'], (string)$identifier['namespace'], (string)$identifier['value']];
			}
			foreach ($equipment['qualifiers'] as $qualifier) {
				$rows['equipment_qualifiers.csv'][] = [(string)$equipment['key'], (string)$qualifier['key'], (string)$qualifier['value'], (string)$qualifier['match']];
				foreach ($qualifier['aliases'] as $alias) {
					$rows['qualifier_aliases.csv'][] = [(string)$equipment['key'], (string)$qualifier['key'], (string)$alias];
				}
			}
		}
		foreach ($pack['slots'] as $slot) {
			$rows['slots.csv'][] = [(string)$slot['key'], (string)$slot['label'], (string)$slot['kind']];
			foreach ($slot['aliases'] as $alias) {
				$rows['slot_aliases.csv'][] = [(string)$slot['key'], (string)$alias];
			}
		}
		foreach ($pack['parts'] as $part) {
			$rows['parts.csv'][] = [(string)$part['key'], (string)$part['manufacturer'], (string)$part['partNumber'], (string)$part['description']];
		}
		foreach ($pack['offers'] as $offer) {
			$rows['offers.csv'][] = [(string)$offer['key'], (string)$offer['partKey'], (string)$offer['label'], array_key_exists('sku', $offer) ? (string)$offer['sku'] : '', (string)$offer['url']];
		}
		foreach ($pack['fitments'] as $fitment) {
			$rows['fitments.csv'][] = [
				(string)$fitment['equipmentKey'],
				(string)$fitment['slotKey'],
				(string)$fitment['partKey'],
				(string)$fitment['relation'],
				(string)$fitment['verification'],
				array_key_exists('notes', $fitment) ? '1' : '0',
				array_key_exists('notes', $fitment) ? (string)$fitment['notes'] : '',
			];
			foreach ($fitment['evidence'] as $evidence) {
				$rows['fitment_evidence.csv'][] = [
					(string)$fitment['equipmentKey'],
					(string)$fitment['slotKey'],
					(string)$fitment['partKey'],
					(string)$evidence['kind'],
					array_key_exists('referenceUrl', $evidence) ? (string)$evidence['referenceUrl'] : '',
					array_key_exists('note', $evidence) ? (string)$evidence['note'] : '',
				];
			}
		}

		$files = ['manifest.json' => $manifestJson];
		foreach (self::HEADERS as $name => $header) {
			$files[$name] = $this->encodeCsv($header, $rows[$name]);
		}
		$this->assertExpandedBound($files);
		return $files;
	}

	/**
	 * @param array<string,string> $files
	 * @return array{pack:array<string,mixed>,canonicalJson:string,contentHash:string,summary:array<string,int>}
	 */
	public function importFiles(array $files): array {
		$this->assertExactFiles($files);
		$this->assertExpandedBound($files);
		$manifest = $this->manifest($files['manifest.json']);

		$totalRows = 0;
		$tables = [];
		foreach (self::HEADERS as $name => $header) {
			$tables[$name] = $this->decodeCsv($name, $files[$name], $header, self::ROW_LIMITS[$name]);
			$totalRows += count($tables[$name]);
			if ($totalRows > self::MAX_TOTAL_DATA_ROWS) {
				throw new ValidationException('Fitment CSV bundle exceeds the reviewed aggregate data-row bound');
			}
		}

		$equipment = [];
		$equipmentIndex = [];
		foreach ($tables['equipment.csv'] as $row) {
			$key = $row['key'];
			if (isset($equipmentIndex[$key])) {
				throw new ValidationException("equipment.csv contains duplicate key {$key}");
			}
			$item = [
				'key' => $key,
				'assetClass' => $row['assetClass'],
				'manufacturer' => $row['manufacturer'],
				'model' => $row['model'],
				'aliases' => ['manufacturer' => [], 'model' => []],
				'identifiers' => [],
				'qualifiers' => [],
			];
			if (($row['yearFrom'] === '') !== ($row['yearTo'] === '')) {
				throw new ValidationException("equipment.csv row {$key} must set yearFrom and yearTo together");
			}
			if ($row['yearFrom'] !== '') {
				$item['yearFrom'] = $this->decimalInteger($row['yearFrom'], "equipment.csv {$key} yearFrom");
				$item['yearTo'] = $this->decimalInteger($row['yearTo'], "equipment.csv {$key} yearTo");
			}
			$equipmentIndex[$key] = count($equipment);
			$equipment[] = $item;
		}

		foreach ($tables['equipment_aliases.csv'] as $row) {
			$index = $this->lookup($equipmentIndex, $row['equipmentKey'], 'equipment_aliases.csv equipmentKey');
			if (!in_array($row['field'], ['manufacturer','model'], true)) {
				throw new ValidationException('equipment_aliases.csv field must be manufacturer or model');
			}
			$equipment[$index]['aliases'][$row['field']][] = $row['alias'];
		}
		foreach ($tables['equipment_identifiers.csv'] as $row) {
			$index = $this->lookup($equipmentIndex, $row['equipmentKey'], 'equipment_identifiers.csv equipmentKey');
			$equipment[$index]['identifiers'][] = ['namespace' => $row['namespace'], 'value' => $row['value']];
		}
		$qualifierIndex = [];
		foreach ($tables['equipment_qualifiers.csv'] as $row) {
			$index = $this->lookup($equipmentIndex, $row['equipmentKey'], 'equipment_qualifiers.csv equipmentKey');
			$compound = $row['equipmentKey'] . "\0" . $row['key'];
			if (isset($qualifierIndex[$compound])) {
				throw new ValidationException('equipment_qualifiers.csv contains a duplicate equipment/qualifier key');
			}
			$equipment[$index]['qualifiers'][] = ['key' => $row['key'], 'value' => $row['value'], 'aliases' => [], 'match' => $row['match']];
			$qualifierIndex[$compound] = [$index, count($equipment[$index]['qualifiers']) - 1];
		}
		foreach ($tables['qualifier_aliases.csv'] as $row) {
			$compound = $row['equipmentKey'] . "\0" . $row['qualifierKey'];
			if (!isset($qualifierIndex[$compound])) {
				throw new ValidationException('qualifier_aliases.csv references an unknown equipment qualifier');
			}
			[$equipmentPosition, $qualifierPosition] = $qualifierIndex[$compound];
			$equipment[$equipmentPosition]['qualifiers'][$qualifierPosition]['aliases'][] = $row['alias'];
		}

		$slots = [];
		$slotIndex = [];
		foreach ($tables['slots.csv'] as $row) {
			$key = $row['key'];
			if (isset($slotIndex[$key])) {
				throw new ValidationException("slots.csv contains duplicate key {$key}");
			}
			$slotIndex[$key] = count($slots);
			$slots[] = ['key' => $key, 'label' => $row['label'], 'kind' => $row['kind'], 'aliases' => []];
		}
		foreach ($tables['slot_aliases.csv'] as $row) {
			$index = $this->lookup($slotIndex, $row['slotKey'], 'slot_aliases.csv slotKey');
			$slots[$index]['aliases'][] = $row['alias'];
		}

		$parts = [];
		$partIndex = [];
		foreach ($tables['parts.csv'] as $row) {
			$key = $row['key'];
			if (isset($partIndex[$key])) {
				throw new ValidationException("parts.csv contains duplicate key {$key}");
			}
			$partIndex[$key] = count($parts);
			$parts[] = ['key' => $key, 'manufacturer' => $row['manufacturer'], 'partNumber' => $row['partNumber'], 'description' => $row['description']];
		}

		$offers = [];
		foreach ($tables['offers.csv'] as $row) {
			$item = ['key' => $row['key'], 'partKey' => $row['partKey'], 'label' => $row['label'], 'url' => $row['url']];
			if ($row['sku'] !== '') {
				$item['sku'] = $row['sku'];
			}
			$offers[] = $item;
		}

		$fitments = [];
		$fitmentIndex = [];
		foreach ($tables['fitments.csv'] as $row) {
			$tuple = $this->fitmentTuple($row['equipmentKey'], $row['slotKey'], $row['partKey']);
			if (isset($fitmentIndex[$tuple])) {
				throw new ValidationException('fitments.csv contains a duplicate equipment/slot/part tuple');
			}
			if (!in_array($row['notesPresent'], ['0','1'], true)) {
				throw new ValidationException('fitments.csv notesPresent must be 0 or 1');
			}
			if ($row['notesPresent'] === '0' && $row['notes'] !== '') {
				throw new ValidationException('fitments.csv notes must be empty when notesPresent is 0');
			}
			$item = [
				'equipmentKey' => $row['equipmentKey'],
				'slotKey' => $row['slotKey'],
				'partKey' => $row['partKey'],
				'relation' => $row['relation'],
				'verification' => $row['verification'],
				'evidence' => [],
			];
			if ($row['notesPresent'] === '1') {
				$item['notes'] = $row['notes'];
			}
			$fitmentIndex[$tuple] = count($fitments);
			$fitments[] = $item;
		}
		foreach ($tables['fitment_evidence.csv'] as $row) {
			$tuple = $this->fitmentTuple($row['equipmentKey'], $row['slotKey'], $row['partKey']);
			if (!isset($fitmentIndex[$tuple])) {
				throw new ValidationException('fitment_evidence.csv references an unknown fitment tuple');
			}
			$item = ['kind' => $row['kind']];
			if ($row['referenceUrl'] !== '') {
				$item['referenceUrl'] = $row['referenceUrl'];
			}
			if ($row['note'] !== '') {
				$item['note'] = $row['note'];
			}
			$fitments[$fitmentIndex[$tuple]]['evidence'][] = $item;
		}

		$pack = [
			'schemaVersion' => $manifest['schemaVersion'],
			'vocabularyVersion' => $manifest['vocabularyVersion'],
			'id' => $manifest['pack']['id'],
			'version' => $manifest['pack']['version'],
			'name' => $manifest['pack']['name'],
			'description' => $manifest['pack']['description'],
			'dataLicense' => $manifest['pack']['dataLicense'],
			'provenance' => $manifest['pack']['provenance'],
			'equipment' => $equipment,
			'slots' => $slots,
			'parts' => $parts,
			'offers' => $offers,
			'fitments' => $fitments,
		];
		return $this->validator->validate($pack);
	}

	/** @param array<string,string> $files */
	private function assertExactFiles(array $files): void {
		$expected = $this->fileNames();
		$actual = array_keys($files);
		sort($expected, SORT_STRING);
		sort($actual, SORT_STRING);
		if ($actual !== $expected) {
			$missing = array_values(array_diff($expected, $actual));
			$unknown = array_values(array_diff($actual, $expected));
			$details = [];
			if ($missing !== []) {
				$details[] = 'missing: ' . implode(', ', $missing);
			}
			if ($unknown !== []) {
				$details[] = 'unknown: ' . implode(', ', $unknown);
			}
			throw new ValidationException('Fitment CSV bundle file set is invalid' . ($details === [] ? '' : ' (' . implode('; ', $details) . ')'));
		}
	}

	/** @param array<string,string> $files */
	private function assertExpandedBound(array $files): void {
		$total = 0;
		foreach ($files as $name => $content) {
			if (!is_string($content)) {
				throw new ValidationException("Fitment CSV bundle file {$name} is not text");
			}
			$total += strlen($content);
			if ($total > self::MAX_EXPANDED_BYTES) {
				throw new ValidationException('Fitment CSV bundle exceeds the 32 MiB reviewed expanded-content bound');
			}
		}
	}

	/** @return array<string,mixed> */
	private function manifest(string $json): array {
		$this->utf8($json, 'manifest.json');
		if (str_starts_with($json, "\xEF\xBB\xBF")) {
			throw new ValidationException('manifest.json must be UTF-8 without a BOM');
		}
		try {
			/** @var mixed $manifest */
			$manifest = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new ValidationException('manifest.json is not valid JSON', 0, $exception);
		}
		if (!is_array($manifest) || array_is_list($manifest)) {
			throw new ValidationException('manifest.json must contain an object');
		}
		$this->exactKeys($manifest, ['format','formatVersion','schemaVersion','vocabularyVersion','pack','csv'], 'manifest.json');
		if (($manifest['format'] ?? null) !== self::FORMAT || ($manifest['formatVersion'] ?? null) !== self::FORMAT_VERSION) {
			throw new ValidationException('manifest.json declares an unsupported fitment CSV bundle format/version');
		}
		if (($manifest['schemaVersion'] ?? null) !== 1 || ($manifest['vocabularyVersion'] ?? null) !== 1) {
			throw new ValidationException('manifest.json requires schemaVersion 1 and vocabularyVersion 1');
		}
		$pack = $manifest['pack'] ?? null;
		$csv = $manifest['csv'] ?? null;
		if (!is_array($pack) || array_is_list($pack) || !is_array($csv) || array_is_list($csv)) {
			throw new ValidationException('manifest.json pack and csv values must be objects');
		}
		$this->exactKeys($pack, ['id','version','name','description','dataLicense','provenance'], 'manifest.json pack');
		$this->exactKeys($csv, ['encoding','delimiter','enclosure','quoting','recordSeparator','spreadsheetEscape'], 'manifest.json csv');
		$expectedCsv = [
			'encoding' => 'UTF-8',
			'delimiter' => ',',
			'enclosure' => '"',
			'quoting' => 'rfc4180-double-quote',
			'recordSeparator' => 'LF-or-CRLF',
			'spreadsheetEscape' => self::SPREADSHEET_ESCAPE,
		];
		if ($csv !== $expectedCsv) {
			throw new ValidationException('manifest.json declares an unsupported CSV dialect or spreadsheet-escape scheme');
		}
		return $manifest;
	}

	/**
	 * @param list<string> $header
	 * @param list<list<string>> $rows
	 */
	private function encodeCsv(array $header, array $rows): string {
		$handle = fopen('php://temp', 'w+b');
		if ($handle === false) {
			throw new \RuntimeException('Could not allocate fitment CSV buffer');
		}
		try {
			$this->writeCsvRow($handle, $header, false);
			foreach ($rows as $row) {
				$this->writeCsvRow($handle, $row, true);
			}
			rewind($handle);
			$content = stream_get_contents($handle);
			if (!is_string($content)) {
				throw new \RuntimeException('Could not read fitment CSV buffer');
			}
			return $content;
		} finally {
			fclose($handle);
		}
	}

	/** @param resource $handle @param list<string> $row */
	private function writeCsvRow($handle, array $row, bool $escape): void {
		if ($escape) {
			$row = array_map(fn (string $value): string => $this->spreadsheetEscape($value), $row);
		}
		if (fputcsv($handle, $row, ',', '"', '', "\n") === false) {
			throw new \RuntimeException('Could not write fitment CSV row');
		}
	}

	/**
	 * @param list<string> $header
	 * @return list<array<string,string>>
	 */
	private function decodeCsv(string $name, string $content, array $header, int $maxRows): array {
		$this->utf8($content, $name);
		if (str_starts_with($content, "\xEF\xBB\xBF")) {
			throw new ValidationException("{$name} must be UTF-8 without a BOM");
		}
		$handle = fopen('php://temp', 'w+b');
		if ($handle === false) {
			throw new \RuntimeException('Could not allocate fitment CSV parser buffer');
		}
		try {
			if (fwrite($handle, $content) !== strlen($content)) {
				throw new \RuntimeException('Could not stage fitment CSV content');
			}
			rewind($handle);
			$actualHeader = fgetcsv($handle, 0, ',', '"', '');
			if (!is_array($actualHeader) || array_map(static fn (mixed $v): string => (string)$v, $actualHeader) !== $header) {
				throw new ValidationException("{$name} header does not exactly match the v1 contract");
			}
			$rows = [];
			$line = 1;
			while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
				$line++;
				if ($row === [null] || $row === []) {
					throw new ValidationException("{$name} contains a blank record at logical row {$line}");
				}
				if (count($row) !== count($header)) {
					throw new ValidationException("{$name} row {$line} has the wrong number of columns");
				}
				if (count($rows) >= $maxRows) {
					throw new ValidationException("{$name} exceeds the reviewed row bound");
				}
				$values = [];
				foreach ($row as $value) {
					$values[] = $this->spreadsheetUnescape((string)$value);
				}
				/** @var array<string,string> $combined */
				$combined = array_combine($header, $values);
				$rows[] = $combined;
			}
			return $rows;
		} finally {
			fclose($handle);
		}
	}

	private function spreadsheetEscape(string $value): string {
		if ($this->hasSpreadsheetDangerousPrefix($value)) {
			return "'" . $value;
		}
		return $value;
	}

	private function spreadsheetUnescape(string $value): string {
		if (str_starts_with($value, "'") && $this->hasSpreadsheetDangerousPrefix(substr($value, 1))) {
			return substr($value, 1);
		}
		return $value;
	}

	private function hasSpreadsheetDangerousPrefix(string $value): bool {
		foreach (self::SPREADSHEET_DANGEROUS_PREFIXES as $prefix) {
			if (str_starts_with($value, $prefix)) {
				return true;
			}
		}
		return false;
	}

	private function utf8(string $value, string $field): void {
		if (!function_exists('mb_check_encoding')) {
			throw new ValidationException('Fitment CSV bundle validation requires the PHP mbstring extension');
		}
		if (!mb_check_encoding($value, 'UTF-8')) {
			throw new ValidationException("{$field} is not valid UTF-8");
		}
	}

	/** @param array<string,mixed> $value @param list<string> $expected */
	private function exactKeys(array $value, array $expected, string $field): void {
		$actual = array_keys($value);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		if ($actual !== $expected) {
			throw new ValidationException("{$field} fields do not exactly match the v1 contract");
		}
	}

	/** @param array<string,int> $index */
	private function lookup(array $index, string $key, string $field): int {
		if (!array_key_exists($key, $index)) {
			throw new ValidationException("{$field} references unknown key {$key}");
		}
		return $index[$key];
	}

	private function decimalInteger(string $value, string $field): int {
		if (preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
			throw new ValidationException("{$field} must be an unsigned decimal integer");
		}
		return (int)$value;
	}

	private function fitmentTuple(string $equipmentKey, string $slotKey, string $partKey): string {
		return $equipmentKey . "\0" . $slotKey . "\0" . $partKey;
	}
}
