<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Db\Asset;
use OCA\MaintenanceTracker\Service\AssetFitmentDescriptorService;
use OCA\MaintenanceTracker\Service\FitmentPackValidator;
use PHPUnit\Framework\TestCase;

final class AssetFitmentDescriptorServiceTest extends TestCase {
	private AssetFitmentDescriptorService $service;

	protected function setUp(): void {
		$this->service = new AssetFitmentDescriptorService(new FitmentPackValidator());
	}

	public function testExactBaseMatchWithoutRequiredQualifiers(): void {
		$match = $this->service->match($this->asset(), $this->target([]));
		self::assertSame('exact', $match['state']);
	}

	public function testMissingRequiredQualifierIsCandidate(): void {
		$match = $this->service->match($this->asset(), $this->target([
			['key' => 'engine', 'value' => '6.7L Example Diesel', 'aliases' => [], 'match' => 'required'],
		]));
		self::assertSame('candidate', $match['state']);
	}

	public function testAliasMatchRequiresExplicitCandidateReview(): void {
		$asset = $this->asset();
		$asset->setModel('W2500');
		$target = $this->target([]);
		$target['aliases']['model'] = ['W2500'];
		self::assertSame('candidate', $this->service->match($asset, $target)['state']);
	}

	public function testKnownClassOrYearMismatchIsConflict(): void {
		$asset = $this->asset();
		$asset->setAssetClass('equipment');
		self::assertSame('conflict', $this->service->match($asset, $this->target([]))['state']);
		$asset = $this->asset();
		$asset->setModelYear(2025);
		self::assertSame('conflict', $this->service->match($asset, $this->target([]))['state']);
	}

	public function testDescriptorOmitsLocalInstanceAndPrivateFields(): void {
		$asset = $this->asset();
		$asset->setUuid('11111111-1111-4111-8111-111111111111');
		$asset->setName('Private nickname');
		$asset->setSerialNumber('SECRET');
		$asset->setNotes('private');
		$descriptor = $this->service->describe($asset);
		self::assertSame(['assetClass','manufacturer','model','modelYear','qualifiers'], array_keys($descriptor));
	}

	private function asset(): Asset {
		$asset = new Asset();
		$asset->setAssetClass('vehicle');
		$asset->setManufacturer('Example Motors');
		$asset->setModel('Workhorse 2500');
		$asset->setModelYear(2020);
		return $asset;
	}

	/** @param list<array<string,mixed>> $qualifiers @return array<string,mixed> */
	private function target(array $qualifiers): array {
		return [
			'key' => 'workhorse2500-2020-diesel',
			'assetClass' => 'vehicle',
			'manufacturer' => 'Example Motors',
			'model' => 'Workhorse 2500',
			'yearFrom' => 2020,
			'yearTo' => 2021,
			'aliases' => ['manufacturer' => [], 'model' => []],
			'identifiers' => [],
			'qualifiers' => $qualifiers,
		];
	}
}
