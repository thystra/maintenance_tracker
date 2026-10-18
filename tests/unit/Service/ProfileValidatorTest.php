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

	public function testOptionalSectionsLeaveExistingHashUnchanged(): void {
		$before = $this->validator->validate($this->profile)['contentHash'];
		self::assertSame($before, $this->validator->validate($this->profile)['contentHash']);
		$withSpecs = $this->profile;
		$withSpecs['specifications'] = [['key' => 'engine_oil.capacity', 'label' => 'Engine oil capacity', 'value' => '4.5', 'unit' => 'l']];
		self::assertNotSame($before, $this->validator->validate($withSpecs)['contentHash']);
	}

	public function testSpecificationsAndFuelAreValidatedAndSummarized(): void {
		$componentKey = $this->profile['components'][0]['key'];
		$this->profile['specifications'] = [
			['key' => 'tyre.pressure', 'label' => 'Tyre pressure', 'value' => 35, 'unit' => 'psi', 'componentKey' => $componentKey],
			['key' => 'towing', 'label' => 'Towing rated', 'value' => true],
		];
		$this->profile['fuel'] = ['settings' => ['distanceUnit' => 'km', 'volumeUnit' => 'l', 'economyUnit' => 'l_100km', 'fuelType' => 'diesel'], 'regimes' => [
			['key' => 'normal', 'name' => 'Normal', 'isDefault' => true],
			['key' => 'towing', 'name' => 'Towing', 'isDefault' => false],
		]];
		$result = $this->validator->validate($this->profile);
		self::assertSame(2, $result['summary']['specifications']);
		self::assertSame(2, $result['summary']['fuelRegimes']);
		self::assertSame('diesel', $result['profile']['fuel']['settings']['fuelType']);
		self::assertSame($result['contentHash'], $this->validator->validate($result['profile'])['contentHash']);
	}

	public function testEmptyFuelSettingsAreDropped(): void {
		$this->profile['fuel'] = ['settings' => [], 'regimes' => []];
		$result = $this->validator->validate($this->profile);
		self::assertArrayNotHasKey('settings', $result['profile']['fuel']);
		self::assertSame([], $result['profile']['fuel']['regimes']);
	}

	public function testRejectsFuelRegimesWithoutExactlyOneDefault(): void {
		$this->profile['fuel'] = ['regimes' => [['key' => 'a', 'name' => 'A', 'isDefault' => false]]];
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('exactly one');
		$this->validator->validate($this->profile);
	}

	public function testRejectsDuplicateRegimeNamesIgnoringCase(): void {
		$this->profile['fuel'] = ['regimes' => [['key' => 'a', 'name' => 'Towing', 'isDefault' => true], ['key' => 'b', 'name' => 'towing', 'isDefault' => false]]];
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('duplicate name');
		$this->validator->validate($this->profile);
	}

	public function testRejectsUnknownFuelSetting(): void {
		$this->profile['fuel'] = ['settings' => ['fuelTab' => 'shown'], 'regimes' => []];
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('unknown fields');
		$this->validator->validate($this->profile);
	}

	public function testRejectsSpecificationForUnknownComponent(): void {
		$this->profile['specifications'] = [['key' => 'x', 'label' => 'X', 'value' => '1', 'componentKey' => 'no_such_component']];
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('no_such_component');
		$this->validator->validate($this->profile);
	}

	public function testRejectsNonScalarSpecificationValue(): void {
		$this->profile['specifications'] = [['key' => 'x', 'label' => 'X', 'value' => ['nested' => true]]];
		$this->expectException(ValidationException::class);
		$this->validator->validate($this->profile);
	}

	public function testSourceUrlIsOptionalButMustBeHttpsWhenPresent(): void {
		unset($this->profile['provenance']['sourceUrl']);
		$result = $this->validator->validate($this->profile);
		self::assertArrayNotHasKey('sourceUrl', $result['profile']['provenance']);
		$this->profile['provenance']['sourceUrl'] = 'http://example.com/profile.json';
		$this->expectException(ValidationException::class);
		$this->validator->validate($this->profile);
	}

	public function testEmptyApplicabilityCanonicalizesToAnObject(): void {
		$this->profile['applicability'] = [];
		$result = $this->validator->validate($this->profile);
		self::assertStringContainsString('"applicability":{}', $result['canonicalJson']);
	}
}
