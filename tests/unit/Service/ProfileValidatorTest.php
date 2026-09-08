<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\MeterValueConverter;
use OCA\MaintenanceTracker\Service\ProfileValidator;
use PHPUnit\Framework\TestCase;

final class ProfileValidatorTest extends TestCase {
	private ProfileValidator $validator;
	/** @var array<string,mixed> */
	private array $profile;

	protected function setUp(): void {
		$this->validator = new ProfileValidator(new MeterValueConverter());
		$profile = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/profiles/generic-car.json'), true);
		self::assertIsArray($profile);
		$this->profile = $profile;
	}

	public function testBundledProfileValidatesAndHasStableCanonicalHash(): void {
		$first = $this->validator->validate($this->profile);
		$second = $this->validator->validate($first['profile']);
		self::assertSame('0.3.0', $first['profile']['version']);
		self::assertSame(7, $first['summary']['components']);
		self::assertSame(6, $first['summary']['workDefinitions']);
		self::assertSame($first['canonicalJson'], $second['canonicalJson']);
		self::assertSame($first['contentHash'], $second['contentHash']);
	}

	public function testEquivalentMeterIntervalNumbersHaveOneCanonicalHash(): void {
		$asInteger = $this->profile;
		$asFloat = $this->profile;
		$asString = $this->profile;
		$asInteger['workDefinitions'][1]['schedule']['rules'][0]['interval']['value'] = 7500;
		$asFloat['workDefinitions'][1]['schedule']['rules'][0]['interval']['value'] = 7500.0;
		$asString['workDefinitions'][1]['schedule']['rules'][0]['interval']['value'] = '7500.000000000';

		$integer = $this->validator->validate($asInteger);
		$float = $this->validator->validate($asFloat);
		$string = $this->validator->validate($asString);

		self::assertSame('7500', $integer['profile']['workDefinitions'][1]['schedule']['rules'][0]['interval']['value']);
		self::assertSame($integer['canonicalJson'], $float['canonicalJson']);
		self::assertSame($integer['canonicalJson'], $string['canonicalJson']);
		self::assertSame($integer['contentHash'], $float['contentHash']);
		self::assertSame($integer['contentHash'], $string['contentHash']);
	}

	public function testRejectsMeterIntervalBelowOneCanonicalUnit(): void {
		$this->profile['workDefinitions'][1]['schedule']['rules'][0]['interval']['value'] = '0.000000001';
		$this->profile['workDefinitions'][1]['schedule']['rules'][0]['interval']['unit'] = 'mm';
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('rounds below one canonical unit');
		$this->validator->validate($this->profile);
	}

	public function testScheduleNoneIsExplicitAndPreserved(): void {
		$result = $this->validator->validate($this->profile);
		$repair = array_values(array_filter($result['profile']['workDefinitions'], static fn (array $d): bool => $d['key'] === 'record_unscheduled_repair'))[0];
		self::assertSame('none', $repair['schedule']);
	}

	public function testRuntimeInstallationRejectsV1(): void {
		$this->profile['schemaVersion'] = 1;
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('schemaVersion must be 2');
		$this->validator->validate($this->profile);
	}

	public function testRejectsUnknownFields(): void {
		$this->profile['script'] = 'alert(1)';
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('unknown fields');
		$this->validator->validate($this->profile);
	}

	public function testRejectsDuplicateKeys(): void {
		$this->profile['meters'][] = $this->profile['meters'][0];
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('duplicate key');
		$this->validator->validate($this->profile);
	}

	public function testRejectsAmbiguousMultiInstanceComponentTarget(): void {
		$this->profile['workDefinitions'][0]['componentKey'] = 'tire';
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('ambiguous');
		$this->validator->validate($this->profile);
	}

	public function testRejectsAmbiguousMultiInstanceParent(): void {
		$this->profile['components'][2]['parentKey'] = 'tire';
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('parentKey tire is ambiguous');
		$this->validator->validate($this->profile);
	}

	public function testRejectsComponentParentCycle(): void {
		$this->profile['components'][0]['quantity'] = 1;
		$this->profile['components'][1]['quantity'] = 1;
		$this->profile['components'][0]['parentKey'] = 'wiper_blade';
		$this->profile['components'][1]['parentKey'] = 'tire';
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('cycle');
		$this->validator->validate($this->profile);
	}

	public function testRejectsUsageCountCanonicalUnitAsDisplayUnit(): void {
		$this->profile['meters'][0]['dimension'] = 'usage_count';
		$this->profile['meters'][0]['displayUnit'] = 'count';
		$this->profile['workDefinitions'][1]['schedule']['rules'][0]['interval']['unit'] = 'count';
		$this->profile['workDefinitions'][4]['schedule']['rules'][1]['interval']['unit'] = 'count';
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('displayUnit');
		$this->validator->validate($this->profile);
	}

	public function testRejectsMeterUnitDimensionMismatch(): void {
		$this->profile['workDefinitions'][1]['schedule']['rules'][0]['interval']['unit'] = 'hour';
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('incompatible');
		$this->validator->validate($this->profile);
	}
}
