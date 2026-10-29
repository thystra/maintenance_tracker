<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Service\CostPolicy;
use OCA\MaintenanceTracker\Service\CurrencyCatalog;
use OCA\MaintenanceTracker\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class CostPolicyTest extends TestCase {
	private const SINGLE = ['defaultCurrency' => 'USD', 'taxEnabled' => false, 'multiCurrency' => false];
	private const FULL = ['defaultCurrency' => 'USD', 'taxEnabled' => true, 'multiCurrency' => true];

	public function testDefaultsAreSingleCurrencyWithoutTax(): void {
		$d = CostPolicy::defaults();
		self::assertFalse($d['taxEnabled']);
		self::assertFalse($d['multiCurrency']);
		self::assertSame('USD', $d['defaultCurrency']);
	}

	public function testAmountsAreExactMinorUnitsPerCurrencyExponent(): void {
		self::assertSame(1250, CostPolicy::amountMinor('12.50', 'USD'));
		self::assertSame(1250, CostPolicy::amountMinor('12.5', 'EUR'));
		self::assertSame(1200, CostPolicy::amountMinor('1200', 'JPY'));
		self::assertSame(1234, CostPolicy::amountMinor('1.234', 'KWD'));
		self::assertSame('12.50', CostPolicy::formatAmount(1250, 'USD'));
		self::assertSame('1200', CostPolicy::formatAmount(1200, 'JPY'));
	}

	public function testZeroAmountsAreRecorded(): void {
		self::assertSame(0, CostPolicy::amountMinor('0', 'USD'));
		self::assertSame(0, CostPolicy::amountMinor('0.00', 'USD'));
		self::assertSame(0, CostPolicy::amountMinor(0, 'JPY'));
		self::assertSame('0.00', CostPolicy::formatAmount(0, 'USD'));
	}

	public function testConsumablesIsAChargeCategory(): void {
		self::assertSame(['lineType' => 'charge', 'category' => 'consumables'], CostPolicy::classify(null, 'consumables', self::SINGLE));
	}

	public function testFloatsAndExcessPrecisionAreRejected(): void {
		foreach ([[12.5, 'USD'], ['1.005', 'USD'], ['1.5', 'JPY'], ['-3', 'USD'], ['-0.01', 'USD'], ['1e3', 'USD']] as [$amount, $currency]) {
			try {
				CostPolicy::amountMinor($amount, $currency);
				self::fail('accepted ' . var_export($amount, true) . ' ' . $currency);
			} catch (ValidationException) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testSingleCurrencyModeRejectsOtherCurrencies(): void {
		self::assertSame('USD', CostPolicy::currency(null, self::SINGLE));
		self::assertSame('USD', CostPolicy::currency('usd', self::SINGLE));
		$this->expectException(ValidationException::class);
		CostPolicy::currency('EUR', self::SINGLE);
	}

	public function testMultiCurrencyAcceptsOnlyIsoCodes(): void {
		self::assertSame('EUR', CostPolicy::currency('EUR', self::FULL));
		$this->expectException(ValidationException::class);
		CostPolicy::currency('XYZ', self::FULL);
	}

	public function testTaxLinesRequireTheWorkspaceSetting(): void {
		self::assertSame(['lineType' => 'tax', 'category' => 'tax'], CostPolicy::classify('tax', null, self::FULL));
		self::assertSame(['lineType' => 'charge', 'category' => 'other'], CostPolicy::classify(null, null, self::SINGLE));
		$this->expectException(ValidationException::class);
		CostPolicy::classify('tax', null, self::SINGLE);
	}

	public function testChargeLinesCannotUseTaxCategory(): void {
		$this->expectException(ValidationException::class);
		CostPolicy::classify('charge', 'tax', self::FULL);
	}

	public function testTotalsAreSeparatedPerCurrencyWithoutConversion(): void {
		$totals = CostPolicy::totals([
			['currency' => 'USD', 'lineType' => 'charge', 'amountMinor' => 1000],
			['currency' => 'USD', 'lineType' => 'tax', 'amountMinor' => 80],
			['currency' => 'EUR', 'lineType' => 'charge', 'amountMinor' => 500],
		]);
		self::assertSame(['EUR', 'USD'], array_column($totals, 'currency'));
		self::assertSame('5.00', $totals[0]['total']);
		self::assertSame('10.80', $totals[1]['total']);
		self::assertSame('0.80', $totals[1]['tax']);
		self::assertSame(2, $totals[1]['count']);
	}

	public function testTotalsOverflowFailsClosed(): void {
		$this->expectException(\OverflowException::class);
		CostPolicy::totals([
			['currency' => 'USD', 'lineType' => 'charge', 'amountMinor' => PHP_INT_MAX],
			['currency' => 'USD', 'lineType' => 'charge', 'amountMinor' => 1],
		]);
	}

	public function testCurrencyCatalogExponents(): void {
		self::assertSame(2, CurrencyCatalog::exponent('USD'));
		self::assertSame(0, CurrencyCatalog::exponent('JPY'));
		self::assertSame(3, CurrencyCatalog::exponent('BHD'));
		self::assertFalse(CurrencyCatalog::isKnown('usd'));
		self::assertFalse(CurrencyCatalog::isKnown('XXX'));
	}
}
