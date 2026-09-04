<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use PHPUnit\Framework\TestCase;

final class AuthorizationCatalogTest extends TestCase {
	private AuthorizationCatalog $catalog;

	protected function setUp(): void {
		$this->catalog = new AuthorizationCatalog();
	}

	public function testManagerCanManageInventoryButNotMembership(): void {
		self::assertTrue($this->catalog->allows('manager', 'inventory.manage'));
		self::assertTrue($this->catalog->allows('manager', 'workspace.members.read'));
		self::assertFalse($this->catalog->allows('manager', 'workspace.members.manage'));
	}

	public function testContributorCanReadButCannotConfigureInventory(): void {
		self::assertTrue($this->catalog->allows('contributor', 'inventory.read'));
		self::assertFalse($this->catalog->allows('contributor', 'inventory.manage'));
		self::assertFalse($this->catalog->allows('contributor', 'audit.read'));
	}

	public function testViewerIsReadOnlyForImplementedSurface(): void {
		self::assertTrue($this->catalog->allows('viewer', 'workspace.read'));
		self::assertTrue($this->catalog->allows('viewer', 'inventory.read'));
		self::assertFalse($this->catalog->allows('viewer', 'inventory.manage'));
	}

	public function testLegacyEditorNormalizesToManager(): void {
		self::assertSame('manager', $this->catalog->normalizeRole('editor'));
		self::assertTrue($this->catalog->allows('editor', 'inventory.manage'));
		self::assertFalse($this->catalog->allows('editor', 'workspace.members.manage'));
	}

	public function testReservedExternalCapabilitiesDoNotAuthorizeAnythingYet(): void {
		$definitions = $this->catalog->definitions();
		foreach ([
			'report.share.create',
			'report.share.revoke',
			'external_submission.read',
			'external_submission.review',
			'maintenance_definition.*',
			'activity.*',
			'evidence.*',
		] as $capability) {
			self::assertArrayHasKey($capability, $definitions);
			self::assertFalse($definitions[$capability]['implemented']);
			self::assertFalse($this->catalog->allows('owner', $capability));
		}
	}
}
