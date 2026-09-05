<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\MeterValueConverter;
use PHPUnit\Framework\TestCase;

final class MeterValueConverterTest extends TestCase {
	private MeterValueConverter $converter;

	protected function setUp(): void {
		$this->converter = new MeterValueConverter();
	}

	public function testDistanceUsesIntegerMillimetresAndRetainsOriginalValue(): void {
		$result = $this->converter->toCanonical('distance', '12345.6', 'mi');
		self::assertSame(19868317286, $result['canonicalValue']);
		self::assertSame('12345.6', $result['originalValue']);
		self::assertSame('mi', $result['originalUnit']);
	}

	public function testRuntimeHoursConvertToSeconds(): void {
		$result = $this->converter->toCanonical('runtime', '1.25', 'hour');
		self::assertSame(4500, $result['canonicalValue']);
		self::assertSame('hour', $result['originalUnit']);
	}

	public function testUsageCountMustBeWhole(): void {
		$this->expectException(ValidationException::class);
		$this->converter->toCanonical('usage_count', '1.5', 'use');
	}

	public function testCanonicalValueMustRemainJsonSafe(): void {
		$this->expectException(ValidationException::class);
		$this->converter->toCanonical('usage_count', '9007199254740992', 'use');
	}

	public function testDisplayUnitMustMatchDimension(): void {
		$this->expectException(ValidationException::class);
		$this->converter->validateDisplayUnit('runtime', 'mi');
	}
}
