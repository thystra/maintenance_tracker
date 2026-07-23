<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Service\UuidGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UuidGeneratorTest extends TestCase {
	public function testGeneratesUniqueVersionFourUuids(): void {
		$generator = new UuidGenerator();
		$values = [];

		for ($index = 0; $index < 100; $index++) {
			$uuid = $generator->generate();
			self::assertTrue(UuidGenerator::isValid($uuid));
			$values[] = $uuid;
		}

		self::assertCount(100, array_unique($values));
	}

	#[DataProvider('invalidUuidProvider')]
	public function testRejectsInvalidUuids(string $uuid): void {
		self::assertFalse(UuidGenerator::isValid($uuid));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidUuidProvider(): iterable {
		yield 'empty' => [''];
		yield 'not a uuid' => ['maintenance-tracker'];
		yield 'version one' => ['6ba7b810-9dad-11d1-80b4-00c04fd430c8'];
		yield 'invalid variant' => ['123e4567-e89b-42d3-72d3-426614174000'];
	}
}
