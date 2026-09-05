<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

final class MeterValueConverter {
	public const MAX_CANONICAL_VALUE = 9007199254740991;
	/** @var array<string, array{canonicalUnit:string, displayUnits:list<string>, factors:array<string,int>}> */
	private const DIMENSIONS = [
		'distance' => [
			'canonicalUnit' => 'mm',
			'displayUnits' => ['mi', 'km', 'm', 'mm'],
			'factors' => [
				'mm' => 1,
				'm' => 1000,
				'km' => 1000000,
				'mi' => 1609344,
			],
		],
		'runtime' => [
			'canonicalUnit' => 's',
			'displayUnits' => ['hour', 'min', 's'],
			'factors' => [
				's' => 1,
				'min' => 60,
				'hour' => 3600,
				'h' => 3600,
			],
		],
		'usage_count' => [
			'canonicalUnit' => 'count',
			'displayUnits' => ['use'],
			'factors' => [
				'use' => 1,
				'count' => 1,
			],
		],
	];

	/** @return list<string> */
	public function dimensions(): array {
		return array_keys(self::DIMENSIONS);
	}

	public function canonicalUnit(string $dimension): string {
		return $this->definition($dimension)['canonicalUnit'];
	}

	/** @return list<string> */
	public function displayUnits(string $dimension): array {
		return $this->definition($dimension)['displayUnits'];
	}

	public function validateDisplayUnit(string $dimension, mixed $unit): string {
		if (!is_string($unit)) {
			throw new ValidationException('displayUnit must be a string');
		}
		$unit = strtolower(trim($unit));
		if (!in_array($unit, $this->displayUnits($dimension), true)) {
			throw new ValidationException('displayUnit is not supported for this meter dimension');
		}

		return $unit;
	}

	/**
	 * @return array{canonicalValue:int, originalValue:string, originalUnit:string}
	 */
	public function toCanonical(string $dimension, mixed $value, mixed $unit): array {
		$definition = $this->definition($dimension);
		if (!is_string($unit)) {
			throw new ValidationException('unit must be a string');
		}
		$unit = strtolower(trim($unit));
		$factor = $definition['factors'][$unit] ?? null;
		if ($factor === null) {
			throw new ValidationException('unit is not supported for this meter dimension');
		}

		$original = $this->normalizeDecimal($value);
		if ($dimension === 'usage_count' && str_contains($original, '.')) {
			throw new ValidationException('Usage-count readings must be whole numbers');
		}
		$canonical = $this->multiplyRounded($original, $factor);

		return [
			'canonicalValue' => $canonical,
			'originalValue' => $original,
			'originalUnit' => $unit === 'h' ? 'hour' : ($unit === 'count' ? 'use' : $unit),
		];
	}

	/** @return array{canonicalUnit:string, displayUnits:list<string>, factors:array<string,int>} */
	private function definition(string $dimension): array {
		$definition = self::DIMENSIONS[$dimension] ?? null;
		if ($definition === null) {
			throw new ValidationException('Unsupported meter dimension');
		}

		return $definition;
	}

	private function normalizeDecimal(mixed $value): string {
		if (is_int($value)) {
			if ($value < 0) {
				throw new ValidationException('Meter values must not be negative');
			}
			return (string)$value;
		}
		if (is_float($value)) {
			if (!is_finite($value) || $value < 0) {
				throw new ValidationException('Meter values must be finite and non-negative');
			}
			$value = rtrim(rtrim(sprintf('%.9F', $value), '0'), '.');
		}
		if (!is_string($value)) {
			throw new ValidationException('value must be a decimal number or decimal string');
		}
		$value = trim($value);
		if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,9})?$/D', $value) !== 1) {
			throw new ValidationException('value must be a non-negative decimal with at most 9 fractional digits');
		}

		[$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
		$whole = ltrim($whole, '0');
		$whole = $whole === '' ? '0' : $whole;
		$fraction = rtrim($fraction, '0');

		return $fraction === '' ? $whole : $whole . '.' . $fraction;
	}

	private function multiplyRounded(string $decimal, int $factor): int {
		[$wholeText, $fractionText] = array_pad(explode('.', $decimal, 2), 2, '');
		if (strlen($wholeText) > 18) {
			throw new ValidationException('Meter value is too large');
		}
		$whole = (int)$wholeText;
		if ($whole > intdiv(self::MAX_CANONICAL_VALUE, $factor)) {
			throw new ValidationException('Meter value is too large');
		}
		$result = $whole * $factor;
		if ($fractionText === '') {
			return $result;
		}

		$scale = 10 ** strlen($fractionText);
		$fraction = (int)$fractionText;
		$scaled = $fraction * $factor;
		$rounded = intdiv($scaled + intdiv($scale, 2), $scale);
		if ($result > self::MAX_CANONICAL_VALUE - $rounded) {
			throw new ValidationException('Meter value is too large');
		}

		return $result + $rounded;
	}
}
