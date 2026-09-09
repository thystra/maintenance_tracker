<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\FitmentCsvBundleService;
use OCA\MaintenanceTracker\Service\FitmentPackValidator;
use PHPUnit\Framework\TestCase;

final class FitmentCsvBundleServiceTest extends TestCase {
	private FitmentPackValidator $validator;
	private FitmentCsvBundleService $service;
	/** @var array<string,mixed> */
	private array $example;

	protected function setUp(): void {
		$this->validator = new FitmentPackValidator();
		$this->service = new FitmentCsvBundleService($this->validator);
		$raw = file_get_contents(__DIR__ . '/../../../fitment/examples/example-service-parts.json');
		self::assertIsString($raw);
		$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);
		$this->example = $decoded;
	}

	public function testExampleRoundTripsWithoutChangingCanonicalHash(): void {
		$before = $this->validator->validate($this->example);
		$files = $this->service->exportFiles($this->example);
		$after = $this->service->importFiles($files);

		self::assertSame($this->service->fileNames(), array_keys($files));
		self::assertArrayHasKey('equipment_identifiers.csv', $files);
		self::assertSame($before['contentHash'], $after['contentHash']);
		self::assertSame($before['canonicalJson'], $after['canonicalJson']);
	}

	public function testProjectionPreservesIdentifiersOptionalEmptyNotesAndSpreadsheetSensitiveText(): void {
		$pack = $this->example;
		$pack['equipment'][0]['manufacturer'] = '=Example Motors';
		$pack['equipment'][0]['aliases']['model'][] = "'W2500 Spreadsheet";
		$pack['equipment'][0]['identifiers'][] = ['namespace' => 'org.example.platform-code', 'value' => '-W2500'];
		$pack['description'] = "First line\nSecond line";
		$pack['parts'][0]['description'] = "+Example description\nwith a second line";
		$pack['fitments'][1]['notes'] = "\t=tab-prefixed formula candidate";
		$pack['fitments'][2]['notes'] = "＝full-width formula candidate";
		$pack['fitments'][0]['verification'] = 'verified';
		$pack['fitments'][0]['notes'] = '';
		$pack['fitments'][0]['evidence'] = [['kind' => 'other', 'note' => '@Reviewed manually']];

		$before = $this->validator->validate($pack);
		$files = $this->service->exportFiles($pack);
		self::assertStringContainsString("'=Example Motors", $files['equipment.csv']);
		self::assertStringContainsString("''W2500 Spreadsheet", $files['equipment_aliases.csv']);
		self::assertStringContainsString("'-W2500", $files['equipment_identifiers.csv']);
		self::assertStringContainsString("'+Example description", $files['parts.csv']);
		self::assertStringContainsString("'@Reviewed manually", $files['fitment_evidence.csv']);
		self::assertStringContainsString("'\t=tab-prefixed formula candidate", $files['fitments.csv']);
		self::assertStringContainsString("'＝full-width formula candidate", $files['fitments.csv']);

		$after = $this->service->importFiles($files);
		self::assertSame($before['contentHash'], $after['contentHash']);
		self::assertSame($before['canonicalJson'], $after['canonicalJson']);
		$fitment = null;
		foreach ($after['pack']['fitments'] as $candidate) {
			if ($candidate['slotKey'] === 'engine.oil_filter') {
				$fitment = $candidate;
				break;
			}
		}
		self::assertIsArray($fitment);
		self::assertArrayHasKey('notes', $fitment);
		self::assertSame('', $fitment['notes']);
	}

	public function testMissingFileFailsClosed(): void {
		$files = $this->service->exportFiles($this->example);
		unset($files['parts.csv']);
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('missing: parts.csv');
		$this->service->importFiles($files);
	}

	public function testUnknownFileFailsClosed(): void {
		$files = $this->service->exportFiles($this->example);
		$files['../unexpected.csv'] = "bad\n";
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('unknown: ../unexpected.csv');
		$this->service->importFiles($files);
	}

	public function testHeaderMustMatchExactly(): void {
		$files = $this->service->exportFiles($this->example);
		$files['parts.csv'] = str_replace('key,manufacturer,partNumber,description', 'key,manufacturer,description,partNumber', $files['parts.csv']);
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('parts.csv header');
		$this->service->importFiles($files);
	}

	public function testImporterOnlyReversesDeclaredSpreadsheetEscapeScheme(): void {
		$files = $this->service->exportFiles($this->example);
		$manifest = json_decode($files['manifest.json'], true, 16, JSON_THROW_ON_ERROR);
		self::assertIsArray($manifest);
		$manifest['csv']['spreadsheetEscape'] = 'unknown-v2';
		$files['manifest.json'] = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('unsupported CSV dialect');
		$this->service->importFiles($files);
	}

	public function testNotesPresenceMarkerCannotHideNonemptyNotes(): void {
		$pack = $this->example;
		$pack['fitments'][0]['notes'] = 'should-not-be-here';
		$files = $this->service->exportFiles($pack);
		$files['fitments.csv'] = str_replace(
			',asserted,1,should-not-be-here',
			',asserted,0,should-not-be-here',
			$files['fitments.csv'],
			$count,
		);
		self::assertSame(1, $count);
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('notes must be empty');
		$this->service->importFiles($files);
	}
}
