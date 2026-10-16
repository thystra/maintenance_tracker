<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\CatalogInput;
use PHPUnit\Framework\TestCase;

final class CatalogInputTest extends TestCase {
	public function testTextIsTrimmedAndBounded(): void {
		self::assertSame('Oil filter', CatalogInput::text('  Oil filter ', 'name', 20));
		$this->expectException(ValidationException::class);
		CatalogInput::text(str_repeat('a', 21), 'name', 20);
	}

	public function testTextRejectsControlCharactersAndNonStrings(): void {
		foreach (["bad\nline", "n\0ul", 12, null, '   '] as $value) {
			try {
				CatalogInput::text($value, 'name', 50);
				self::fail('Expected rejection');
			} catch (ValidationException) {
				self::assertTrue(true);
			}
		}
	}

	public function testOptionalTextAllowsNewlinesOnlyWhenRequested(): void {
		self::assertNull(CatalogInput::optionalText('', 'notes', 10));
		self::assertSame("a\nb", CatalogInput::optionalText("a\nb", 'notes', 10, true));
		$this->expectException(ValidationException::class);
		CatalogInput::optionalText("a\nb", 'notes', 10);
	}

	public function testUnknownFieldsAreRejected(): void {
		$this->expectException(ValidationException::class);
		CatalogInput::known(['name' => 'x', 'ownerUid' => 'admin'], ['name'], 'vendor');
	}

	public function testUuidIsNormalized(): void {
		self::assertSame('3f0f6a5e-9a8b-4c1d-8e2f-0a1b2c3d4e5f', CatalogInput::uuid(' 3F0F6A5E-9A8B-4C1D-8E2F-0A1B2C3D4E5F ', 'uuid'));
		$this->expectException(ValidationException::class);
		CatalogInput::uuid('not-a-uuid', 'uuid');
	}

	public function testDatesAreStrictCalendarDates(): void {
		self::assertSame('2024-02-29', CatalogInput::date('2024-02-29', 'incurredOn'));
		foreach (['2023-02-29', '2024-2-01', '2024-02-01T00:00:00Z', 20240201] as $value) {
			try {
				CatalogInput::date($value, 'incurredOn');
				self::fail('Expected rejection');
			} catch (ValidationException) {
				self::assertTrue(true);
			}
		}
	}
}
