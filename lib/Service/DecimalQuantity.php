<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

/**
 * Exact decimal parsing into scaled integers. Floats are never used: values
 * are accepted only as decimal strings (or integers) and converted digit by
 * digit, so 0.1 + 0.2 style binary rounding cannot occur.
 */
final class DecimalQuantity {
	/** Largest accepted scaled magnitude; far inside the signed 64-bit range. */
	public const MAX_SCALED = 999_999_999_999_999;

	/**
	 * @param int $scale number of fractional digits the result is scaled by
	 * @param bool $allowNegative accept a leading minus sign
	 */
	public static function parse(mixed $value, int $scale, string $field, bool $allowNegative = false, bool $allowZero = false): int {
		if (is_int($value)) {
			$value = (string)$value;
		}
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be a decimal string");
		}
		$value = trim($value);
		$pattern = $scale > 0 ? '/^(-?)(\d{1,15})(?:\.(\d{1,' . $scale . '}))?$/D' : '/^(-?)(\d{1,15})$/D';
		if (preg_match($pattern, $value, $m) !== 1) {
			throw new ValidationException($scale > 0
				? "{$field} must be a decimal number with at most {$scale} fractional digits"
				: "{$field} must be a whole number for this currency");
		}
		if ($m[1] === '-' && !$allowNegative) {
			throw new ValidationException("{$field} cannot be negative");
		}
		$fraction = str_pad($m[3] ?? '', $scale, '0');
		$digits = ltrim($m[2] . $fraction, '0');
		if (strlen($digits) > 15) {
			throw new ValidationException("{$field} is too large");
		}
		$scaled = $digits === '' ? 0 : (int)$digits;
		if ($scaled > self::MAX_SCALED) {
			throw new ValidationException("{$field} is too large");
		}
		if ($scaled === 0 && !$allowZero) {
			throw new ValidationException("{$field} must be greater than zero");
		}
		return $m[1] === '-' ? -$scaled : $scaled;
	}

	public static function format(int $scaled, int $scale, bool $trimTrailingZeros = false): string {
		$negative = $scaled < 0;
		$digits = (string)abs($scaled);
		if ($scale > 0) {
			$digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
			$whole = substr($digits, 0, -$scale);
			$fraction = substr($digits, -$scale);
			if ($trimTrailingZeros) {
				$fraction = rtrim($fraction, '0');
			}
			$digits = $fraction === '' ? $whole : $whole . '.' . $fraction;
		}
		return ($negative ? '-' : '') . $digits;
	}
}
