<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\DecimalQuantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalQuantityTest extends TestCase {
	public function testParsesExactScaledIntegers(): void {
		self::assertSame(4500, DecimalQuantity::parse('4.5', 3, 'q'));
		self::assertSame(1, DecimalQuantity::parse('0.001', 3, 'q'));
		self::assertSame(3000, DecimalQuantity::parse(3, 3, 'q'));
		self::assertSame(1234, DecimalQuantity::parse('12.34', 2, 'amount'));
		self::assertSame(-1234, DecimalQuantity::parse('-12.34', 2, 'amount', true));
		self::assertSame(0, DecimalQuantity::parse('0.00', 2, 'amount', false, true));
		self::assertSame(1500, DecimalQuantity::parse('1500', 0, 'amount'));
		self::assertSame(30, DecimalQuantity::parse('0.3', 2, 'amount'));
	}

	/** @return array<string, array{mixed, int}> */
	public static function invalid(): array {
		return [
			'float type' => [0.1, 2],
			'too many decimals' => ['1.234', 2],
			'decimal for zero-exponent currency' => ['10.5', 0],
			'exponent notation' => ['1e3', 2],
			'comma decimal' => ['1,5', 2],
			'zero' => ['0', 2],
			'negative' => ['-1', 2],
			'leading plus' => ['+1', 2],
			'too large' => ['9999999999999999', 2],
			'empty' => ['', 2],
			'null' => [null, 2],
		];
	}

	#[DataProvider('invalid')]
	public function testRejectsUnsafeInput(mixed $value, int $scale): void {
		$this->expectException(ValidationException::class);
		DecimalQuantity::parse($value, $scale, 'q');
	}

	public function testFormatsWithoutFloatingPoint(): void {
		self::assertSame('12.34', DecimalQuantity::format(1234, 2));
		self::assertSame('0.05', DecimalQuantity::format(5, 2));
		self::assertSame('-0.05', DecimalQuantity::format(-5, 2));
		self::assertSame('1500', DecimalQuantity::format(1500, 0));
		self::assertSame('4.5', DecimalQuantity::format(4500, 3, true));
		self::assertSame('4', DecimalQuantity::format(4000, 3, true));
		self::assertSame('1.250', DecimalQuantity::format(1250, 3));
	}
}
