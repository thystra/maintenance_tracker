<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

/**
 * Pure cost-ledger rules: currency/tax gating against workspace settings,
 * exact amount parsing into minor units, and per-currency totals. No method
 * here uses floating point and no foreign-exchange conversion exists.
 */
final class CostPolicy {
	public const DEFAULT_CURRENCY = 'USD';
	public const LINE_TYPES = ['charge', 'tax'];
	public const CATEGORIES = ['parts', 'labor', 'fluids', 'fuel', 'service', 'fees', 'tax', 'other'];

	/** @return array{defaultCurrency:string,taxEnabled:bool,multiCurrency:bool,revision:int} */
	public static function defaults(): array {
		return ['defaultCurrency' => self::DEFAULT_CURRENCY, 'taxEnabled' => false, 'multiCurrency' => false, 'revision' => 0];
	}

	/**
	 * Resolve and gate the entry currency.
	 *
	 * @param array{defaultCurrency:string,taxEnabled:bool,multiCurrency:bool} $settings
	 */
	public static function currency(mixed $requested, array $settings): string {
		if ($requested === null || $requested === '') {
			return $settings['defaultCurrency'];
		}
		$code = CurrencyCatalog::normalize($requested, 'currency');
		if (!$settings['multiCurrency'] && $code !== $settings['defaultCurrency']) {
			throw new ValidationException('This workspace records costs only in ' . $settings['defaultCurrency'] . '; enable multi-currency in cost settings to use ' . $code);
		}
		return $code;
	}

	/**
	 * @param array{defaultCurrency:string,taxEnabled:bool,multiCurrency:bool} $settings
	 * @return array{lineType:string,category:string}
	 */
	public static function classify(mixed $lineType, mixed $category, array $settings): array {
		$lineType = $lineType ?? 'charge';
		if (!is_string($lineType) || !in_array($lineType, self::LINE_TYPES, true)) {
			throw new ValidationException('lineType must be charge or tax');
		}
		if ($lineType === 'tax') {
			if (!$settings['taxEnabled']) {
				throw new ValidationException('Tax lines are disabled for this workspace; enable them in cost settings');
			}
			if ($category !== null && $category !== 'tax') {
				throw new ValidationException('Tax lines use the tax category');
			}
			return ['lineType' => 'tax', 'category' => 'tax'];
		}
		$category = $category ?? 'other';
		if (!is_string($category) || !in_array($category, self::CATEGORIES, true) || $category === 'tax') {
			throw new ValidationException('category must be one of parts, labor, fluids, fuel, service, fees, other');
		}
		return ['lineType' => 'charge', 'category' => $category];
	}

	/** Positive exact amount in the currency's minor units. */
	public static function amountMinor(mixed $amount, string $currency): int {
		return DecimalQuantity::parse($amount, CurrencyCatalog::exponent($currency), 'amount');
	}

	public static function formatAmount(int $minor, string $currency): string {
		return DecimalQuantity::format($minor, CurrencyCatalog::exponent($currency));
	}

	/**
	 * Per-currency totals split into charges and tax. Currencies are never
	 * combined or converted.
	 *
	 * @param iterable<array{currency:string,lineType:string,amountMinor:int}> $entries
	 * @return list<array{currency:string,exponent:int,chargesMinor:int,taxMinor:int,totalMinor:int,charges:string,tax:string,total:string,count:int}>
	 */
	public static function totals(iterable $entries): array {
		$by = [];
		foreach ($entries as $e) {
			$c = $e['currency'];
			$by[$c] ??= ['charges' => 0, 'tax' => 0, 'count' => 0];
			$key = $e['lineType'] === 'tax' ? 'tax' : 'charges';
			$by[$c][$key] = self::add($by[$c][$key], $e['amountMinor']);
			++$by[$c]['count'];
		}
		ksort($by, SORT_STRING);
		$out = [];
		foreach ($by as $currency => $t) {
			$total = self::add($t['charges'], $t['tax']);
			$out[] = [
				'currency' => $currency,
				'exponent' => CurrencyCatalog::exponent($currency),
				'chargesMinor' => $t['charges'],
				'taxMinor' => $t['tax'],
				'totalMinor' => $total,
				'charges' => self::formatAmount($t['charges'], $currency),
				'tax' => self::formatAmount($t['tax'], $currency),
				'total' => self::formatAmount($total, $currency),
				'count' => $t['count'],
			];
		}
		return $out;
	}

	private static function add(int $a, int $b): int {
		$sum = $a + $b;
		if (!is_int($sum)) {
			throw new \OverflowException('Cost total exceeds the supported integer range');
		}
		return $sum;
	}
}
