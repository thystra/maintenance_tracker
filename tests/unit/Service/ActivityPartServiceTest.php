<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\ActivityPartService;
use PHPUnit\Framework\TestCase;

final class ActivityPartServiceTest extends TestCase {
	private ActivityPartService $service;

	protected function setUp(): void {
		// validateList() is pure; repositories are not touched.
		$this->service = (new \ReflectionClass(ActivityPartService::class))->newInstanceWithoutConstructor();
	}

	public function testValidatesAndNormalizesPartUsage(): void {
		$parts = $this->service->validateList([[
			'uuid' => '5E1A2B3C-4D5E-4F60-8A7B-1C2D3E4F5A6B',
			'partUuid' => '6f2b3c4d-5e6f-4a70-9b8c-2d3e4f5a6b7c',
			'quantity' => '5.5',
			'unit' => 'qt',
			'notes' => "Topped off\nafter run",
		]]);
		self::assertSame('5e1a2b3c-4d5e-4f60-8a7b-1c2d3e4f5a6b', $parts[0]['uuid']);
		self::assertSame(5500, $parts[0]['quantityMilli']);
		self::assertNull($parts[0]['vendorUuid']);
	}

	public function testMissingPartsIsEmpty(): void {
		self::assertSame([], $this->service->validateList(null));
	}

	public function testRejectsUnknownFieldsUnitsAndDuplicates(): void {
		$base = ['uuid' => '5e1a2b3c-4d5e-4f60-8a7b-1c2d3e4f5a6b', 'partUuid' => '6f2b3c4d-5e6f-4a70-9b8c-2d3e4f5a6b7c', 'quantity' => '1', 'unit' => 'each'];
		foreach ([
			[$base + ['costMinor' => 100]],
			[array_merge($base, ['unit' => 'barrel'])],
			[array_merge($base, ['quantity' => '0'])],
			[array_merge($base, ['quantity' => 1.5])],
			[$base, $base],
			['not-an-object'],
			['key' => $base],
		] as $payload) {
			try {
				$this->service->validateList($payload);
				self::fail('Expected rejection of ' . json_encode($payload));
			} catch (ValidationException) {
				self::assertTrue(true);
			}
		}
	}
}
