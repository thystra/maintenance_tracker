<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\AuditEventCatalog;
use OCA\MaintenanceTracker\Service\CostPolicy;
use OCA\MaintenanceTracker\Service\EvidencePolicy;
use OCA\MaintenanceTracker\Service\ExportPolicy;
use OCA\MaintenanceTracker\Service\FuelEconomy;
use OCA\MaintenanceTracker\Service\ImportPolicy;
use PHPUnit\Framework\TestCase;

final class FuelEconomyTest extends TestCase {
	private const MI = 1609344;
	private const DAY = 86400;

	/** @return array{uuid:string,filledAt:int,odometerMm:int,volumeUl:int,full:bool,missed:bool,regime:?string,costMinor:?int,currency:?string} */
	private static function fill(string $uuid, int $day, int $miles, string $gallons, bool $full = true, bool $missed = false, ?string $regime = 'empty', ?int $cost = null): array {
		return [
			'uuid' => $uuid, 'filledAt' => $day * self::DAY, 'odometerMm' => $miles * self::MI,
			'volumeUl' => FuelEconomy::volume($gallons, 'gal')['ul'], 'full' => $full, 'missed' => $missed,
			'regime' => $regime, 'costMinor' => $cost, 'currency' => $cost === null ? null : 'USD',
		];
	}

	public function testVolumesAreParsedExactlyIntoMicrolitres(): void {
		self::assertSame(['value' => '10', 'ul' => 37854118], FuelEconomy::volume('10', 'gal'));
		self::assertSame(['value' => '45.5', 'ul' => 45500000], FuelEconomy::volume('45.500', 'l'));
		$this->expectException(ValidationException::class);
		FuelEconomy::volume('0', 'l');
	}

	public function testEconomyUnitsConvertBetweenEachOther(): void {
		$d = 300 * self::MI;
		$v = FuelEconomy::volume('10', 'gal')['ul'];
		self::assertSame(30.0, FuelEconomy::economy($d, $v, 'mpg'));
		self::assertSame(12.75, FuelEconomy::economy($d, $v, 'km_l'));
		self::assertSame(7.84, FuelEconomy::economy($d, $v, 'l_100km'));
		self::assertNull(FuelEconomy::economy(0, $v, 'mpg'));
	}

	public function testEconomyIsMeasuredOnlyBetweenFullFills(): void {
		$result = FuelEconomy::segments([
			self::fill('a', 1, 1000, '12', true),   // opening full fill: not counted
			self::fill('b', 5, 1150, '5', false),   // partial: counted in the next segment
			self::fill('c', 9, 1300, '5', true),    // closes a 300 mi / 10 gal segment
			self::fill('d', 12, 1400, '4', false),  // trailing partial: open
		]);
		self::assertCount(1, $result['segments']);
		$segment = $result['segments'][0];
		self::assertTrue($segment['valid']);
		self::assertSame(['b', 'c'], $segment['fills']);
		self::assertSame(30.0, FuelEconomy::economy($segment['distanceMm'], $segment['volumeUl'], 'mpg'));
		self::assertSame(1, $result['openFills']);
	}

	public function testMissedFillsAndOdometerErrorsInvalidateASegment(): void {
		$result = FuelEconomy::segments([
			self::fill('a', 1, 1000, '10'),
			self::fill('b', 5, 1300, '10', true, true),
			self::fill('c', 9, 1300, '10'),
			self::fill('d', 12, 1600, '10'),
		]);
		self::assertSame(['missed_fill', 'odometer', null], array_column($result['segments'], 'invalidReason'));
		self::assertSame([false, false, true], array_column($result['segments'], 'valid'));
	}

	public function testMixedRegimeSegmentsCountOverallButInNoRegime(): void {
		$fills = [
			self::fill('a', 1, 1000, '10', true, false, 'empty', 4000),
			self::fill('b', 5, 1300, '10', true, false, 'empty', 4000),   // 30 mpg empty
			self::fill('c', 9, 1450, '5', false, false, 'towing', 2000),
			self::fill('d', 12, 1600, '10', true, false, 'empty', 4000),  // mixed: 300 mi / 15 gal
			self::fill('e', 15, 1750, '10', true, false, 'towing', 4000), // 15 mpg towing
		];
		$segments = FuelEconomy::segments($fills)['segments'];
		self::assertSame([false, true, false], array_column($segments, 'mixed'));
		$stats = FuelEconomy::stats($segments, $fills, 0, 100 * self::DAY, 'mpg', 'mi', 'gal', ['empty' => 'Empty', 'towing' => 'Towing']);
		self::assertSame(3, $stats['overall']['segments']);
		self::assertSame(750.0, $stats['overall']['distance']);
		self::assertSame(21.43, $stats['overall']['economy']);
		self::assertSame(30.0, $stats['overall']['best']);
		self::assertSame(15.0, $stats['overall']['worst']);
		self::assertSame(1, $stats['mixedSegments']);
		$byName = array_column($stats['regimes'], null, 'name');
		self::assertSame(30.0, $byName['Empty']['economy']);
		self::assertSame(1, $byName['Empty']['segments']);
		self::assertSame(15.0, $byName['Towing']['economy']);
		self::assertSame([['currency' => 'USD', 'amount' => '180.00', 'amountMinor' => 18000]], $stats['spend']);
		// 140.00 over 750 mi of costed segments.
		self::assertSame('0.1867', $stats['costPerDistance'][0]['amount']);
		// Each regime gets its own cost per distance from its own stretches;
		// the mixed stretch counts only in the overall figure.
		self::assertSame([['currency' => 'USD', 'amount' => '0.1333', 'distance' => 300.0]], $byName['Empty']['costPerDistance']);
		self::assertSame([['currency' => 'USD', 'amount' => '0.2667', 'distance' => 150.0]], $byName['Towing']['costPerDistance']);
	}

	public function testRegimeCostPerDistanceSkipsUncostedStretches(): void {
		$fills = [
			self::fill('a', 1, 1000, '10', true, false, 'empty', 4000),
			self::fill('b', 5, 1300, '10', true, false, 'empty', 3000),   // empty, costed: 30.00 / 300 mi
			self::fill('c', 9, 1600, '10', true, false, 'empty'),         // empty, no price: left out
			self::fill('d', 12, 1750, '10', true, false, 'towing'),       // towing, no price at all
			self::fill('e', 15, 1900, '10', true, false, 'towing', 0),    // towing, free fill: 0.00 / 150 mi
		];
		$segments = FuelEconomy::segments($fills)['segments'];
		$stats = FuelEconomy::stats($segments, $fills, 0, 100 * self::DAY, 'mpg', 'mi', 'gal', ['empty' => 'Empty', 'towing' => 'Towing']);
		$byName = array_column($stats['regimes'], null, 'name');
		self::assertSame([['currency' => 'USD', 'amount' => '0.1000', 'distance' => 300.0]], $byName['Empty']['costPerDistance']);
		self::assertSame(600.0, $byName['Empty']['distance']);
		self::assertSame([['currency' => 'USD', 'amount' => '0.0000', 'distance' => 150.0]], $byName['Towing']['costPerDistance']);
		self::assertSame('0.0667', $stats['costPerDistance'][0]['amount']);

		// Only the unpriced towing stretch ends in this period.
		$uncosted = FuelEconomy::stats($segments, $fills, 10 * self::DAY, 12 * self::DAY, 'mpg', 'mi', 'gal', []);
		self::assertCount(1, $uncosted['regimes']);
		self::assertSame('towing', $uncosted['regimes'][0]['regimeUuid']);
		self::assertSame([], $uncosted['regimes'][0]['costPerDistance']);
		self::assertSame([], $uncosted['costPerDistance']);
	}

	public function testBestAndWorstFollowEfficiencyForLitresPer100Km(): void {
		$fills = [self::fill('a', 1, 1000, '10'), self::fill('b', 2, 1300, '10'), self::fill('c', 3, 1450, '10')];
		$stats = FuelEconomy::stats(FuelEconomy::segments($fills)['segments'], $fills, 0, 10 * self::DAY, 'l_100km', 'km', 'l', []);
		self::assertLessThan($stats['overall']['worst'], $stats['overall']['best']);
	}

	public function testPeriodsResolveToInclusiveRanges(): void {
		$now = 1_800_000_000;
		self::assertSame([$now - 30 * self::DAY, $now], FuelEconomy::period('30d', null, null, $now));
		self::assertSame([gmmktime(0, 0, 0, 1, 1, (int)gmdate('Y', $now)), $now], FuelEconomy::period('year', null, null, $now));
		self::assertSame([strtotime('2026-01-01T00:00:00Z'), strtotime('2026-03-31T23:59:59Z')], FuelEconomy::period('custom', '2026-01-01', '2026-03-31', $now));
		$this->expectException(ValidationException::class);
		FuelEconomy::period('custom', '2026-03-01', '2026-01-01', $now);
	}

	public function testFuelIsWiredIntoCostsEvidenceAuditAndRestore(): void {
		self::assertContains('fuel', CostPolicy::CATEGORIES);
		self::assertContains('fuel', EvidencePolicy::TARGET_TYPES);
		self::assertSame('maint_fuel', ExportPolicy::TABLES['fuel']);
		self::assertSame('fuel_regimes', ImportPolicy::reference('fuel', 'context_id'));
		self::assertSame('costs', ImportPolicy::reference('fuel', 'cost_id'));
		self::assertSame('readings', ImportPolicy::reference('fuel', 'reading_id'));
		$order = array_flip(ImportPolicy::ORDER);
		self::assertLessThan($order['fuel'], $order['costs']);
		self::assertLessThan($order['fuel'], $order['fuel_regimes']);
		$catalog = new AuditEventCatalog();
		self::assertSame('fuel.created', $catalog->eventForChange('fuel', 'upsert', 1));
		self::assertSame('fuel_regime.archived', $catalog->eventForChange('fuel_regime', 'delete', 2));
	}
}
