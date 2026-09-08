<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\FitmentPackValidator;
use PHPUnit\Framework\TestCase;

final class FitmentPackValidatorTest extends TestCase {
	private FitmentPackValidator $validator;
	/** @var array<string,mixed> */
	private array $example;

	protected function setUp(): void {
		$this->validator = new FitmentPackValidator();
		$raw = file_get_contents(__DIR__ . '/../../../fitment/examples/example-service-parts.json');
		self::assertIsString($raw);
		$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);
		$this->example = $decoded;
	}

	public function testExampleHasStableCanonicalHashAcrossSetOrdering(): void {
		$first = $this->validator->validate($this->example);
		self::assertSame('1b29fad0964d9f58d25a91154340779234d2b529d7d3d7ea8b05362768d88150', $first['contentHash']);
		$reordered = $this->example;
		$reordered['equipment'] = array_reverse($reordered['equipment']);
		$reordered['slots'] = array_reverse($reordered['slots']);
		$reordered['parts'] = array_reverse($reordered['parts']);
		$reordered['offers'] = array_reverse($reordered['offers']);
		$reordered['fitments'] = array_reverse($reordered['fitments']);
		$second = $this->validator->validate($reordered);
		self::assertSame($first['canonicalJson'], $second['canonicalJson']);
		self::assertSame($first['contentHash'], $second['contentHash']);
	}

	public function testConservativePartIdentityPreservesPunctuation(): void {
		self::assertSame(
			$this->validator->partIdentityHash('Example Co', 'AB-123'),
			$this->validator->partIdentityHash(' example   co ', 'ab-123'),
		);
		self::assertNotSame(
			$this->validator->partIdentityHash('Example Co', 'AB-123'),
			$this->validator->partIdentityHash('Example Co', 'AB123'),
		);
	}

	public function testVerifiedFitmentRequiresEvidence(): void {
		$pack = $this->example;
		$pack['fitments'][0]['verification'] = 'verified';
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('verified');
		$this->validator->validate($pack);
	}

	public function testRuntimeRequiresExactV1(): void {
		$pack = $this->example;
		$pack['schemaVersion'] = 2;
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('schemaVersion must be 1');
		$this->validator->validate($pack);
	}

	public function testRejectsUnitSpecificIdentifierNamespace(): void {
		$pack = $this->example;
		$pack['equipment'][0]['identifiers'][] = ['namespace' => 'org.example.vin', 'value' => 'not-a-real-vin'];
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('unit-specific');
		$this->validator->validate($pack);
	}
}
