<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Service\ProfileSnapshotBuilder;
use PHPUnit\Framework\TestCase;

final class ProfileSnapshotBuilderTest extends TestCase {
	public function testSlugMakesProfileKeys(): void {
		self::assertSame('family_car', ProfileSnapshotBuilder::slug('Family car'));
		self::assertSame('furnace_2', ProfileSnapshotBuilder::slug('  Furnace #2 '));
	}

	public function testSlugFallsBackForTextWithoutKeyCharacters(): void {
		self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9._-]*$/', ProfileSnapshotBuilder::slug('ÄÖÜ ★'));
		self::assertSame('item', ProfileSnapshotBuilder::slug('★★★'));
	}
}
