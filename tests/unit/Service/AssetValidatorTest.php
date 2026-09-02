<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\AssetValidator;
use PHPUnit\Framework\TestCase;

final class AssetValidatorTest extends TestCase {
	private AssetValidator $validator;

	protected function setUp(): void {
		$this->validator = new AssetValidator();
	}

	public function testNormalizesCreatePayloadAndAppliesDefaults(): void {
		$result = $this->validator->forCreate([
			'name' => '  Shop compressor  ',
			'manufacturer' => '  Ingersoll Rand ',
		'modelYear' => '2024',
		'currency' => '',
		'purchasePriceMinor' => null,
	]);

		self::assertSame('Shop compressor', $result['name']);
		self::assertSame('Ingersoll Rand', $result['manufacturer']);
		self::assertSame(2024, $result['modelYear']);
		self::assertSame('other', $result['category']);
		self::assertNull($result['assetClass']);
		self::assertSame('active', $result['status']);
		self::assertNull($result['currency']);
		self::assertNull($result['purchasePriceMinor']);
		self::assertNull($result['uuid']);
	}

	public function testAcceptsPairedMoneyAndProfileFields(): void {
		$result = $this->validator->forCreate([
			'uuid' => 'B913571D-5405-4A88-BB59-2D670A5F93DC',
			'name' => 'Truck',
			'category' => 'vehicle',
			'profileKey' => 'org.argentwolf.vehicle.f350',
			'profileVersion' => '1.2.0',
			'purchasePriceMinor' => '6250000',
			'currency' => 'usd',
			'acquiredOn' => '2024-06-01',
		]);

		self::assertSame('b913571d-5405-4a88-bb59-2d670a5f93dc', $result['uuid']);
		self::assertSame(6_250_000, $result['purchasePriceMinor']);
		self::assertSame('USD', $result['currency']);
		self::assertSame('2024-06-01', $result['acquiredOn']);
	}

	public function testRejectsUnknownFieldsInsteadOfMassAssigning(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Unknown asset fields: ownerUid');

		$this->validator->forCreate([
			'name' => 'Generator',
			'ownerUid' => 'someone-else',
		]);
	}

	public function testRequiresProfileKeyAndVersionTogether(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('profileKey and profileVersion');

		$this->validator->forCreate([
			'name' => 'Generator',
			'profileKey' => 'org.example.generator',
		]);
	}

	public function testRequiresMoneyAndCurrencyTogether(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('purchasePriceMinor and currency');

		$this->validator->forCreate([
			'name' => 'Generator',
			'purchasePriceMinor' => 10000,
		]);
	}

	public function testRejectsInvalidDateInsteadOfNormalizingIt(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('acquiredOn must use YYYY-MM-DD');

		$this->validator->forCreate([
			'name' => 'Generator',
			'acquiredOn' => '2026-02-30',
		]);
	}

	public function testNotesAllowLineBreaksButNotControlBytes(): void {
		$result = $this->validator->forPatch([
			'notes' => "Line one\nLine two",
		]);
		self::assertSame("Line one\nLine two", $result['notes']);

		$this->expectException(ValidationException::class);
		$this->validator->forPatch([
			'notes' => "Unsafe\x01note",
		]);
	}

	public function testPatchCanExplicitlyClearOptionalPairs(): void {
		$result = $this->validator->forPatch([
			'profileKey' => null,
			'profileVersion' => null,
			'purchasePriceMinor' => null,
			'currency' => null,
		]);

		self::assertArrayHasKey('profileKey', $result);
		self::assertNull($result['profileKey']);
		self::assertNull($result['profileVersion']);
		self::assertNull($result['purchasePriceMinor']);
		self::assertNull($result['currency']);
	}

	public function testRejectsEmptyPatch(): void {
		$this->expectException(ValidationException::class);
		$this->validator->forPatch([]);
	}
}
