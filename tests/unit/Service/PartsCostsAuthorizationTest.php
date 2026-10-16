<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Service\AuditEventCatalog;
use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Explicit v0.1.10 parts/vendor/cost capability matrix. Every cell is spelled
 * out so a future bundle change is a deliberate test edit.
 */
final class PartsCostsAuthorizationTest extends TestCase {
	/** @return array<string, array{string, string, bool}> */
	public static function matrix(): array {
		$grants = [
			'part.read' => ['owner' => true, 'manager' => true, 'contributor' => true, 'viewer' => true],
			'part.manage' => ['owner' => true, 'manager' => true, 'contributor' => false, 'viewer' => false],
			'vendor.read' => ['owner' => true, 'manager' => true, 'contributor' => true, 'viewer' => true],
			'vendor.manage' => ['owner' => true, 'manager' => true, 'contributor' => false, 'viewer' => false],
		];
		$cases = [];
		foreach ($grants as $capability => $roles) {
			foreach ($roles as $role => $allowed) {
				$cases["{$role} {$capability}"] = [$role, $capability, $allowed];
			}
		}
		return $cases;
	}

	#[DataProvider('matrix')]
	public function testCapabilityMatrix(string $role, string $capability, bool $allowed): void {
		self::assertSame($allowed, (new AuthorizationCatalog())->allows($role, $capability));
	}

	public function testReadCapabilitiesAreNotWrites(): void {
		$catalog = new AuthorizationCatalog();
		foreach (['part.read', 'vendor.read'] as $capability) {
			self::assertFalse($catalog->isWrite($capability));
		}
		foreach (['part.manage', 'vendor.manage'] as $capability) {
			self::assertTrue($catalog->isWrite($capability));
		}
	}

	public function testAuditVocabularyCoversCatalogMutations(): void {
		$catalog = new AuditEventCatalog();
		foreach (['part', 'vendor'] as $entity) {
			self::assertSame("{$entity}.created", $catalog->eventForChange($entity, 'upsert', 1));
			self::assertSame("{$entity}.updated", $catalog->eventForChange($entity, 'upsert', 2));
			self::assertSame("{$entity}.archived", $catalog->eventForChange($entity, 'delete', 3));
			self::assertSame([], $catalog->definition("{$entity}.created")['detailKeys']);
		}
	}
}
