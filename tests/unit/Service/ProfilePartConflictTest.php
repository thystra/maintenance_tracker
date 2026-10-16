<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Service\FitmentPackValidator;
use OCA\MaintenanceTracker\Service\PartService;
use OCA\MaintenanceTracker\Service\ProfileInstallationService;
use PHPUnit\Framework\TestCase;

final class ProfilePartConflictTest extends TestCase {
	/** @param list<array<string,mixed>> $parts @return list<string> */
	private function conflicts(array $parts): array {
		$partService = (new \ReflectionClass(PartService::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(PartService::class, 'identity'))->setValue($partService, new FitmentPackValidator());
		$installer = (new \ReflectionClass(ProfileInstallationService::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(ProfileInstallationService::class, 'parts'))->setValue($installer, $partService);
		$method = new \ReflectionMethod(ProfileInstallationService::class, 'partConflicts');
		return $method->invoke($installer, $parts);
	}

	public function testDistinctPartsWithoutOffersAreInstallable(): void {
		self::assertSame([], $this->conflicts([
			['key' => 'a', 'manufacturer' => 'Acme', 'partNumber' => 'F-1', 'description' => 'x', 'offers' => []],
			['key' => 'b', 'manufacturer' => 'Acme', 'partNumber' => 'F-2', 'description' => 'y', 'offers' => []],
		]));
	}

	public function testNormalizedDuplicateIdentityFailsClosed(): void {
		$conflicts = $this->conflicts([
			['key' => 'a', 'manufacturer' => 'Acme', 'partNumber' => 'F-1', 'description' => 'x', 'offers' => []],
			['key' => 'b', 'manufacturer' => '  ACME ', 'partNumber' => 'f-1', 'description' => 'y', 'offers' => []],
		]);
		self::assertCount(1, $conflicts);
		self::assertStringContainsString('same canonical part identity', $conflicts[0]);
	}

	public function testPunctuationIsNotStrippedFromIdentity(): void {
		self::assertSame([], $this->conflicts([
			['key' => 'a', 'manufacturer' => 'Acme', 'partNumber' => 'F-1', 'description' => 'x', 'offers' => []],
			['key' => 'b', 'manufacturer' => 'Acme', 'partNumber' => 'F1', 'description' => 'y', 'offers' => []],
		]));
	}
}
