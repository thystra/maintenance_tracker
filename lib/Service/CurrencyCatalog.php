<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

/**
 * Active ISO 4217 currency codes and their minor-unit exponents. Fund,
 * precious-metal, testing, and "no currency" codes (for example XAU, XTS,
 * XXX) are deliberately excluded because they are not ordinary money.
 */
final class CurrencyCatalog {
	/** Codes whose minor unit is not 2 decimal places. */
	private const EXPONENTS = [
		'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
		'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
		'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
		'CLF' => 4, 'UYW' => 4,
	];

	private const CODES = 'AED AFN ALL AMD ANG AOA ARS AUD AWG AZN BAM BBD BDT BGN BHD BIF BMD BND BOB BOV BRL BSD BTN BWP BYN BZD '
		. 'CAD CDF CHE CHF CHW CLF CLP CNY COP COU CRC CUP CVE CZK DJF DKK DOP DZD EGP ERN ETB EUR FJD FKP GBP GEL GHS GIP GMD GNF '
		. 'GTQ GYD HKD HNL HTG HUF IDR ILS INR IQD IRR ISK JMD JOD JPY KES KGS KHR KMF KPW KRW KWD KYD KZT LAK LBP LKR LRD LSL LYD '
		. 'MAD MDL MGA MKD MMK MNT MOP MRU MUR MVR MWK MXN MXV MYR MZN NAD NGN NIO NOK NPR NZD OMR PAB PEN PGK PHP PKR PLN PYG QAR '
		. 'RON RSD RUB RWF SAR SBD SCR SDG SEK SGD SHP SLE SOS SRD SSP STN SVC SYP SZL THB TJS TMT TND TOP TRY TTD TWD TZS UAH UGX '
		. 'USD USN UYI UYU UYW UZS VED VES VND VUV WST XAF XCD XCG XOF XPF YER ZAR ZMW ZWG';

	public static function isKnown(string $code): bool {
		return preg_match('/^[A-Z]{3}$/D', $code) === 1 && in_array($code, explode(' ', self::CODES), true);
	}

	public static function normalize(mixed $value, string $field): string {
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be an ISO 4217 currency code");
		}
		$code = strtoupper(trim($value));
		if (!self::isKnown($code)) {
			throw new ValidationException("{$field} must be an active ISO 4217 currency code");
		}
		return $code;
	}

	public static function exponent(string $code): int {
		if (!self::isKnown($code)) {
			throw new ValidationException('Unknown currency code');
		}
		return self::EXPONENTS[$code] ?? 2;
	}
}
