<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

/**
 * Pure fuel rules: unit parsing/conversion and fuel economy.
 *
 * Volumes are stored in microlitres and distances in millimetres (integers).
 * Economy is measured only between two full fills ("segments"). A segment is
 * valid when no fill in it carries the missed-previous flag and the odometer
 * increased. Every fill after the opening full fill up to and including the
 * closing full fill contributes its volume. A fill's regime describes the
 * driving since the previous fill, so a segment whose fills carry different
 * regimes is "mixed": it counts in the overall figures but in no regime.
 */
final class FuelEconomy {
	public const DISTANCE_UNITS = ['mi' => 1609344, 'km' => 1000000];
	/** Nanolitres per unit; US gallon is exactly 3.785411784 L. */
	public const VOLUME_UNITS = ['gal' => 3785411784, 'l' => 1000000000];
	public const ECONOMY_UNITS = ['mpg', 'km_l', 'l_100km'];
	public const FUEL_TYPES = ['gasoline', 'diesel', 'e85', 'lpg', 'cng', 'other'];
	public const PERIODS = ['30d', '90d', '365d', 'year', 'all', 'custom'];
	public const MAX_VOLUME_SCALED = 10_000_000; // 10 000 units at 3 decimals

	/** @return array{value:string,ul:int} exact entered value and microlitres */
	public static function volume(mixed $value, string $unit): array {
		$nl = self::VOLUME_UNITS[$unit] ?? throw new ValidationException('volumeUnit must be gal or l');
		$thousandths = DecimalQuantity::parse(is_float($value) ? rtrim(rtrim(sprintf('%.3F', $value), '0'), '.') : $value, 3, 'volume');
		if ($thousandths > self::MAX_VOLUME_SCALED) {
			throw new ValidationException('volume is too large');
		}
		// thousandths * nL/unit / 1000 (to units) / 1000 (nL to µL), rounded half up.
		$ul = intdiv($thousandths * $nl + 500000, 1000000);
		return ['value' => DecimalQuantity::format($thousandths, 3, true), 'ul' => $ul];
	}

	public static function distanceUnit(mixed $unit, string $field = 'distanceUnit'): string {
		if (!is_string($unit) || !isset(self::DISTANCE_UNITS[$unit])) {
			throw new ValidationException("{$field} must be mi or km");
		}
		return $unit;
	}

	public static function volumeUnit(mixed $unit, string $field = 'volumeUnit'): string {
		if (!is_string($unit) || !isset(self::VOLUME_UNITS[$unit])) {
			throw new ValidationException("{$field} must be gal or l");
		}
		return $unit;
	}

	public static function economyUnit(mixed $unit): string {
		if (!is_string($unit) || !in_array($unit, self::ECONOMY_UNITS, true)) {
			throw new ValidationException('economyUnit must be one of ' . implode(', ', self::ECONOMY_UNITS));
		}
		return $unit;
	}

	public static function fuelType(mixed $type): ?string {
		if ($type === null || $type === '') {
			return null;
		}
		if (!is_string($type) || !in_array($type, self::FUEL_TYPES, true)) {
			throw new ValidationException('fuelType must be one of ' . implode(', ', self::FUEL_TYPES));
		}
		return $type;
	}

	/** Economy in the requested unit; null when it cannot be computed. */
	public static function economy(int $distanceMm, int $volumeUl, string $unit): ?float {
		if ($distanceMm <= 0 || $volumeUl <= 0) {
			return null;
		}
		return match ($unit) {
			'mpg' => round(($distanceMm / self::DISTANCE_UNITS['mi']) / ($volumeUl * 1000 / self::VOLUME_UNITS['gal']), 2),
			'km_l' => round($distanceMm / $volumeUl, 2),
			'l_100km' => round(100 * $volumeUl / $distanceMm, 2),
			default => throw new ValidationException('Unsupported economy unit'),
		};
	}

	public static function distanceIn(int $distanceMm, string $unit): float {
		return round($distanceMm / self::DISTANCE_UNITS[$unit], 1);
	}

	public static function volumeIn(int $volumeUl, string $unit): float {
		return round($volumeUl * 1000 / self::VOLUME_UNITS[$unit], 3);
	}

	/**
	 * Split fills into full-to-full segments.
	 *
	 * @param list<array{uuid:string,filledAt:int,odometerMm:int,volumeUl:int,full:bool,missed:bool,regime:?string,costMinor:?int,currency:?string}> $fills ordered by time, then odometer
	 * @return array{segments:list<array<string,mixed>>,openFills:int,beforeFirstFull:int}
	 */
	public static function segments(array $fills): array {
		$segments = [];
		$anchor = null;
		$pending = [];
		$before = 0;
		foreach ($fills as $fill) {
			if ($anchor === null) {
				if ($fill['full']) {
					$anchor = $fill;
				} else {
					++$before;
				}
				continue;
			}
			$pending[] = $fill;
			if (!$fill['full']) {
				continue;
			}
			$distance = $fill['odometerMm'] - $anchor['odometerMm'];
			$volume = 0;
			$missed = false;
			$regimes = [];
			foreach ($pending as $p) {
				$volume += $p['volumeUl'];
				$missed = $missed || $p['missed'];
				$regimes[$p['regime'] ?? ''] = true;
			}
			$reason = $missed ? 'missed_fill' : ($distance <= 0 ? 'odometer' : null);
			$mixed = count($regimes) > 1;
			$regime = $mixed ? null : (array_key_first($regimes) === '' ? null : (string)array_key_first($regimes));
			$segments[] = [
				'fromUuid' => $anchor['uuid'],
				'toUuid' => $fill['uuid'],
				'startAt' => $anchor['filledAt'],
				'endAt' => $fill['filledAt'],
				'distanceMm' => max(0, $distance),
				'volumeUl' => $volume,
				'fills' => array_map(static fn (array $p): string => $p['uuid'], $pending),
				'regime' => $regime,
				'mixed' => $mixed,
				'valid' => $reason === null,
				'invalidReason' => $reason,
				'cost' => self::segmentCost($pending),
			];
			$anchor = $fill;
			$pending = [];
		}
		return ['segments' => $segments, 'openFills' => count($pending), 'beforeFirstFull' => $before];
	}

	/**
	 * Summary statistics over valid segments ending in [from, to] and fills in
	 * that period.
	 *
	 * @param list<array<string,mixed>> $segments from segments()
	 * @param list<array{filledAt:int,volumeUl:int,costMinor:?int,currency:?string}> $fills
	 * @param array<string,string> $regimeNames uuid => name
	 * @return array<string,mixed>
	 */
	public static function stats(array $segments, array $fills, int $from, int $to, string $economyUnit, string $distanceUnit, string $volumeUnit, array $regimeNames): array {
		$inPeriod = array_values(array_filter($segments, static fn (array $s): bool => $s['valid'] && $s['endAt'] >= $from && $s['endAt'] <= $to));
		$overall = self::aggregate($inPeriod, $economyUnit, $distanceUnit);
		$byRegime = [];
		foreach ($inPeriod as $s) {
			if (!$s['mixed']) {
				$byRegime[$s['regime'] ?? ''][] = $s;
			}
		}
		$regimes = [];
		foreach ($byRegime as $uuid => $list) {
			$regimes[] = ['regimeUuid' => $uuid === '' ? null : (string)$uuid, 'name' => $uuid === '' ? null : ($regimeNames[(string)$uuid] ?? null)] + self::aggregate($list, $economyUnit, $distanceUnit);
		}
		usort($regimes, static fn (array $a, array $b): int => $b['distance'] <=> $a['distance']);

		$spend = [];
		$volume = 0;
		$count = 0;
		foreach ($fills as $f) {
			if ($f['filledAt'] < $from || $f['filledAt'] > $to) {
				continue;
			}
			++$count;
			$volume += $f['volumeUl'];
			if ($f['costMinor'] !== null && $f['currency'] !== null) {
				$spend[$f['currency']] = ($spend[$f['currency']] ?? 0) + $f['costMinor'];
			}
		}
		ksort($spend);
		$costPerDistance = [];
		$costed = [];
		foreach ($inPeriod as $s) {
			if ($s['cost'] !== null) {
				$c = $s['cost']['currency'];
				$costed[$c] ??= ['minor' => 0, 'distance' => 0];
				$costed[$c]['minor'] += $s['cost']['minor'];
				$costed[$c]['distance'] += $s['distanceMm'];
			}
		}
		ksort($costed);
		foreach ($costed as $currency => $c) {
			$exp = CurrencyCatalog::exponent($currency);
			$perUnit = ($c['minor'] / (10 ** $exp)) / ($c['distance'] / self::DISTANCE_UNITS[$distanceUnit]);
			$costPerDistance[] = ['currency' => $currency, 'amount' => number_format($perUnit, $exp + 2, '.', ''), 'distance' => self::distanceIn($c['distance'], $distanceUnit)];
		}
		return [
			'from' => $from <= 0 ? null : gmdate('Y-m-d', $from),
			'to' => $to === PHP_INT_MAX ? null : gmdate('Y-m-d', $to),
			'economyUnit' => $economyUnit,
			'distanceUnit' => $distanceUnit,
			'volumeUnit' => $volumeUnit,
			'overall' => $overall,
			'regimes' => $regimes,
			'mixedSegments' => count(array_filter($inPeriod, static fn (array $s): bool => $s['mixed'])),
			'fills' => $count,
			'volume' => self::volumeIn($volume, $volumeUnit),
			'spend' => array_map(static fn (string $currency, int $minor): array => ['currency' => $currency, 'amount' => CostPolicy::formatAmount($minor, $currency), 'amountMinor' => $minor], array_keys($spend), array_values($spend)),
			'costPerDistance' => $costPerDistance,
		];
	}

	/**
	 * Resolve a period name to an inclusive [from, to] timestamp range.
	 *
	 * @return array{0:int,1:int}
	 */
	public static function period(string $period, ?string $from, ?string $to, int $now): array {
		$day = 86400;
		return match ($period) {
			'30d' => [$now - 30 * $day, $now],
			'90d' => [$now - 90 * $day, $now],
			'365d' => [$now - 365 * $day, $now],
			'year' => [(int)gmmktime(0, 0, 0, 1, 1, (int)gmdate('Y', $now)), $now],
			'all' => [0, PHP_INT_MAX],
			'custom' => self::custom($from, $to),
			default => throw new ValidationException('period must be one of ' . implode(', ', self::PERIODS)),
		};
	}

	/** @return array{0:int,1:int} */
	private static function custom(?string $from, ?string $to): array {
		if ($from === null || $to === null) {
			throw new ValidationException('A custom period needs from and to dates');
		}
		$start = strtotime(CatalogInput::date($from, 'from') . 'T00:00:00Z');
		$end = strtotime(CatalogInput::date($to, 'to') . 'T23:59:59Z');
		if ($start === false || $end === false || $end < $start) {
			throw new ValidationException('to must not be before from');
		}
		return [$start, $end];
	}

	/**
	 * @param list<array<string,mixed>> $segments
	 * @return array{economy:?float,best:?float,worst:?float,segments:int,distance:float}
	 */
	private static function aggregate(array $segments, string $economyUnit, string $distanceUnit): array {
		$distance = 0;
		$volume = 0;
		$best = null;
		$worst = null;
		foreach ($segments as $s) {
			$distance += $s['distanceMm'];
			$volume += $s['volumeUl'];
			// Compare by distance per volume so "best" is right for L/100km too.
			$efficiency = $s['distanceMm'] / $s['volumeUl'];
			if ($best === null || $efficiency > $best['e']) {
				$best = ['e' => $efficiency, 's' => $s];
			}
			if ($worst === null || $efficiency < $worst['e']) {
				$worst = ['e' => $efficiency, 's' => $s];
			}
		}
		return [
			'economy' => self::economy($distance, $volume, $economyUnit),
			'best' => $best === null ? null : self::economy($best['s']['distanceMm'], $best['s']['volumeUl'], $economyUnit),
			'worst' => $worst === null ? null : self::economy($worst['s']['distanceMm'], $worst['s']['volumeUl'], $economyUnit),
			'segments' => count($segments),
			'distance' => self::distanceIn($distance, $distanceUnit),
		];
	}

	/**
	 * Total cost of a segment when every fill in it has a cost in one currency.
	 *
	 * @param list<array{costMinor:?int,currency:?string}> $fills
	 * @return array{minor:int,currency:string}|null
	 */
	private static function segmentCost(array $fills): ?array {
		$currency = null;
		$minor = 0;
		foreach ($fills as $f) {
			if ($f['costMinor'] === null || $f['currency'] === null || ($currency !== null && $currency !== $f['currency'])) {
				return null;
			}
			$currency = $f['currency'];
			$minor += $f['costMinor'];
		}
		return $currency === null ? null : ['minor' => $minor, 'currency' => $currency];
	}
}
