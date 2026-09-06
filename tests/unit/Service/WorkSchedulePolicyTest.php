<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\MeterValueConverter;
use OCA\MaintenanceTracker\Service\WorkSchedulePolicy;
use PHPUnit\Framework\TestCase;

final class WorkSchedulePolicyTest extends TestCase {
	private WorkSchedulePolicy $policy;

	protected function setUp(): void {
		$this->policy = new WorkSchedulePolicy(new MeterValueConverter());
	}

	public function testExplicitNoneIsSupportedButNullIsRejected(): void {
		self::assertSame(
			['type' => 'none', 'combination' => null, 'rules' => []],
			$this->policy->normalize('none', 7, static fn (string $uuid): array => throw new \LogicException($uuid)),
		);

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('schedule must be "none" or a schedule policy object');
		$this->policy->normalize(null, 7, static fn (string $uuid): array => throw new \LogicException($uuid));
	}

	public function testCalendarAndBusinessDaysRulesNormalize(): void {
		$schedule = $this->policy->normalize([
			'combination' => 'any',
			'rules' => [
				['type' => 'calendar', 'interval' => ['value' => 6, 'unit' => 'month']],
				[
					'type' => 'business_days',
					'interval' => ['value' => 10, 'unit' => 'business_day'],
					'weekdays' => ['mon', 'tue', 'wed', 'thu', 'fri'],
				],
			],
		], 7, static fn (string $uuid): array => throw new \LogicException($uuid));

		self::assertSame('rules', $schedule['type']);
		self::assertSame('any', $schedule['combination']);
		self::assertSame('calendar', $schedule['rules'][0]['type']);
		self::assertSame('6', $schedule['rules'][0]['intervalValue']);
		self::assertSame(62, $schedule['rules'][1]['weekdayMask']);
		self::assertSame(['mon', 'tue', 'wed', 'thu', 'fri'], $this->policy->weekdaysFromMask(62));
	}

	public function testBusinessDaysSupportSundayThroughThursdayWeek(): void {
		$schedule = $this->policy->normalize([
			'combination' => 'any',
			'rules' => [[
				'type' => 'business_days',
				'interval' => ['value' => 15, 'unit' => 'business_day'],
				'weekdays' => ['sun', 'mon', 'tue', 'wed', 'thu'],
			]],
		], 7, static fn (string $uuid): array => throw new \LogicException($uuid));

		self::assertSame(31, $schedule['rules'][0]['weekdayMask']);
		self::assertSame(
			['sun', 'mon', 'tue', 'wed', 'thu'],
			$this->policy->weekdaysFromMask(31),
		);
	}

	public function testMeterRuleUsesCanonicalMeterUnits(): void {
		$meter = $this->meter(42, 7, 'distance');
		$schedule = $this->policy->normalize([
			'combination' => 'any',
			'rules' => [[
				'type' => 'meter',
				'meterUuid' => '9c7f24c0-0d3a-4c6f-9c11-0b6f3e1e5e10',
				'interval' => ['value' => 7500, 'unit' => 'mi'],
			]],
		], 7, static fn (string $uuid): array => $meter);

		self::assertSame('7500', $schedule['rules'][0]['intervalValue']);
		self::assertSame('mi', $schedule['rules'][0]['intervalUnit']);
		self::assertSame(12070080000, $schedule['rules'][0]['canonicalValue']);
		self::assertSame(42, $schedule['rules'][0]['meterId']);
	}

	public function testMeterRuleRejectsWrongAssetAndIncompatibleUnit(): void {
		$wrongAsset = $this->meter(42, 8, 'distance');
		try {
			$this->policy->normalize([
				'combination' => 'any',
				'rules' => [[
					'type' => 'meter',
					'meterUuid' => '9c7f24c0-0d3a-4c6f-9c11-0b6f3e1e5e10',
					'interval' => ['value' => 1, 'unit' => 'mi'],
				]],
			], 7, static fn (string $uuid): array => $wrongAsset);
			self::fail('Expected wrong-asset schedule to be rejected');
		} catch (ValidationException $exception) {
			self::assertSame('Scheduled meter must belong to the same asset as the work definition', $exception->getMessage());
		}

		$distance = $this->meter(42, 7, 'distance');
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('unit is not supported for this meter dimension');
		$this->policy->normalize([
			'combination' => 'any',
			'rules' => [[
				'type' => 'meter',
				'meterUuid' => '9c7f24c0-0d3a-4c6f-9c11-0b6f3e1e5e10',
				'interval' => ['value' => 1, 'unit' => 'hour'],
			]],
		], 7, static fn (string $uuid): array => $distance);
	}

	public function testBusinessDayWeekdaysMustBeUnique(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('business_days weekdays must be unique');
		$this->policy->normalize([
			'combination' => 'any',
			'rules' => [[
				'type' => 'business_days',
				'interval' => ['value' => 5, 'unit' => 'business_day'],
				'weekdays' => ['mon', 'mon'],
			]],
		], 7, static fn (string $uuid): array => throw new \LogicException($uuid));
	}

	/** @return array{id:int,assetId:int,dimension:string} */
	private function meter(int $id, int $assetId, string $dimension): array {
		return [
			'id' => $id,
			'assetId' => $assetId,
			'dimension' => $dimension,
		];
	}
}
