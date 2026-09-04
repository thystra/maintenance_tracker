<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\RelationshipInputValidator;
use PHPUnit\Framework\TestCase;

final class RelationshipInputValidatorTest extends TestCase {
	private RelationshipInputValidator $validator;

	protected function setUp(): void {
		$this->validator = new RelationshipInputValidator();
	}

	public function testNormalizesUuidAndAcceptsSemanticContext(): void {
		self::assertSame(
			'b913571d-5405-4a88-bb59-2d670a5f93dc',
			$this->validator->uuid('B913571D-5405-4A88-BB59-2D670A5F93DC', 'uuid'),
		);
		self::assertSame('fuel_trip', $this->validator->key('fuel_trip', 'context'));
	}

	public function testRejectsImpossibleCalendarDate(): void {
		$this->expectException(ValidationException::class);
		$this->validator->date('2026-02-30', 'effectiveFrom');
	}

	public function testRejectsReversedEffectiveRange(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('effectiveUntil');
		$this->validator->assertDateRange('2026-09-03', '2026-09-02');
	}

	public function testAcceptsOpenEndedEffectiveRange(): void {
		$this->validator->assertDateRange('2026-09-03', null);
		self::assertTrue(true);
	}
}
