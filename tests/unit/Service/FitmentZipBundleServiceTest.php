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
use OCA\MaintenanceTracker\Service\FitmentZipBundleService;
use PHPUnit\Framework\TestCase;

final class FitmentZipBundleServiceTest extends TestCase {
	private FitmentPackValidator $validator;
	private FitmentZipBundleService $service;
	/** @var array<string,mixed> */
	private array $example;

	protected function setUp(): void {
		if (!class_exists(\ZipArchive::class)) {
			self::markTestSkipped('PHP zip extension is not available in this unit-test runtime.');
		}
		$this->validator = new FitmentPackValidator();
		$csv = new FitmentCsvBundleService($this->validator);
		$this->service = new FitmentZipBundleService($csv, $this->validator);
		$raw = file_get_contents(__DIR__ . '/../../../fitment/examples/example-service-parts.json');
		self::assertIsString($raw);
		$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);
		$this->example = $decoded;
	}

	public function testGeneratedZipRoundTripsCanonicalContent(): void {
		$expected = $this->validator->validate($this->example);
		$bytes = $this->service->exportBytes($this->example);
		self::assertLessThanOrEqual(FitmentZipBundleService::MAX_COMPRESSED_BYTES, strlen($bytes));
		$path = $this->tempZip($bytes);
		try {
			$actual = $this->service->importPath($path);
			self::assertSame($expected['contentHash'], $actual['contentHash']);
			self::assertSame($expected['canonicalJson'], $actual['canonicalJson']);
		} finally {
			@unlink($path);
		}
	}

	public function testUnsafeArchiveEntryIsRejectedBeforeReadingContent(): void {
		$bytes = $this->service->exportBytes($this->example);
		$path = $this->tempZip($bytes);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path) === true);
		self::assertTrue($zip->addFromString('../unexpected.csv', "bad\n"));
		self::assertTrue($zip->close());
		try {
			$this->expectException(ValidationException::class);
			$this->expectExceptionMessage('unknown or unsafe entry');
			$this->service->importPath($path);
		} finally {
			@unlink($path);
		}
	}

	public function testUnixSymlinkEntryIsRejected(): void {
		$bytes = $this->service->exportBytes($this->example);
		$path = $this->tempZip($bytes);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path) === true);
		self::assertTrue($zip->setExternalAttributesName('parts.csv', \ZipArchive::OPSYS_UNIX, 0120777 << 16));
		self::assertTrue($zip->close());
		try {
			$this->expectException(ValidationException::class);
			$this->expectExceptionMessage('must not be a symlink');
			$this->service->importPath($path);
		} finally {
			@unlink($path);
		}
	}

	public function testArchiveCommentIsRejected(): void {
		$bytes = $this->service->exportBytes($this->example);
		$path = $this->tempZip($bytes);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path) === true);
		self::assertTrue($zip->setArchiveComment('hidden metadata'));
		self::assertTrue($zip->close());
		try {
			$this->expectException(ValidationException::class);
			$this->expectExceptionMessage('archive comments');
			$this->service->importPath($path);
		} finally {
			@unlink($path);
		}
	}

	private function tempZip(string $bytes): string {
		$path = tempnam(sys_get_temp_dir(), 'maint-fitment-test-');
		self::assertIsString($path);
		self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
		return $path;
	}
}
