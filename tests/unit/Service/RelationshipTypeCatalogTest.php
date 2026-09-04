<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\RelationshipTypeCatalog;
use PHPUnit\Framework\TestCase;

final class RelationshipTypeCatalogTest extends TestCase {
	private RelationshipTypeCatalog $catalog;

	protected function setUp(): void {
		$this->catalog = new RelationshipTypeCatalog();
	}

	public function testTowingRequiresVehicleToTrailerDirection(): void {
		$this->catalog->assertCompatible('tows', 'vehicle', 'trailer');
		self::assertSame('towed_by', $this->catalog->definition('tows')['inverseKey']);
	}

	public function testRejectsIncompatibleTowingTarget(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('vehicle -> equipment');
		$this->catalog->assertCompatible('tows', 'vehicle', 'equipment');
	}

	public function testTrailerCanCarryEquipment(): void {
		$definition = $this->catalog->definition('carries');
		self::assertContains('trailer', $definition['sourceClasses']);
		self::assertContains('equipment', $definition['targetClasses']);
		$this->catalog->assertCompatible('carries', 'trailer', 'equipment');
	}

	public function testTrailerCanCarryVehicle(): void {
		$definition = $this->catalog->definition('carries');
		self::assertContains('trailer', $definition['sourceClasses']);
		self::assertContains('vehicle', $definition['targetClasses']);
		$this->catalog->assertCompatible('carries', 'trailer', 'vehicle');
	}

	public function testPairedWithIsSymmetric(): void {
		$definition = $this->catalog->definition('paired_with');
		self::assertTrue($definition['symmetric']);
		self::assertSame('paired_with', $definition['inverseKey']);
	}
}
