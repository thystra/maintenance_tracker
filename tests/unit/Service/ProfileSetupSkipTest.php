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

/**
 * Overlapping work-definition keys are skipped on apply, not hard conflicts.
 * Part identity collisions inside a profile document remain hard conflicts.
 */
final class ProfileSetupSkipTest extends TestCase {
	public function testPartIdentityConflictsStillHardBlock(): void {
		$partService = (new \ReflectionClass(PartService::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(PartService::class, 'identity'))->setValue($partService, new FitmentPackValidator());
		$installer = (new \ReflectionClass(ProfileInstallationService::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(ProfileInstallationService::class, 'parts'))->setValue($installer, $partService);
		$method = new \ReflectionMethod(ProfileInstallationService::class, 'partConflicts');
		$conflicts = $method->invoke($installer, [
			['key' => 'a', 'manufacturer' => 'Acme', 'partNumber' => 'F-1', 'description' => 'x', 'offers' => []],
			['key' => 'b', 'manufacturer' => 'Acme', 'partNumber' => 'F-1', 'description' => 'y', 'offers' => []],
		]);
		self::assertNotSame([], $conflicts);
	}

	public function testSetupSkipsDuplicateKeysInsteadOfHardConflict(): void {
		$source = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ProfileInstallationService.php');
		self::assertIsString($source);
		self::assertStringContainsString('function setupSkips', $source);
		self::assertStringContainsString('Task already present, skipped:', $source);
		self::assertStringContainsString("'skipped' => \$skips", $source);
		self::assertStringNotContainsString(
			"\$out[] = 'Work-definition key already exists on asset:'",
			$source,
		);
	}
}
