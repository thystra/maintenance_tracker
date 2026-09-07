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
		self::assertTrue($this->catalog->allows('manager', 'meter.manage'));
		self::assertTrue($this->catalog->allows('manager', 'reading.correct'));
		self::assertTrue($this->catalog->allows('manager', 'maintenance_definition.read'));
		self::assertTrue($this->catalog->allows('manager', 'maintenance_definition.manage'));
		self::assertTrue($this->catalog->allows('manager', 'activity.read'));
		self::assertTrue($this->catalog->allows('manager', 'activity.create'));
		self::assertTrue($this->catalog->allows('manager', 'activity.manage'));
		self::assertTrue($this->catalog->allows('manager', 'maintenance_occurrence.reconcile'));
		self::assertTrue($this->catalog->allows('manager', 'reminder_policy.manage'));
		self::assertTrue($this->catalog->allows('manager', 'profile.read'));
		self::assertTrue($this->catalog->allows('manager', 'profile.install'));
	}

	public function testContributorCanReadButCannotConfigureInventory(): void {
		self::assertTrue($this->catalog->allows('contributor', 'inventory.read'));
		self::assertFalse($this->catalog->allows('contributor', 'inventory.manage'));
		self::assertFalse($this->catalog->allows('contributor', 'audit.read'));
		self::assertTrue($this->catalog->allows('contributor', 'meter.read'));
		self::assertTrue($this->catalog->allows('contributor', 'reading.create'));
		self::assertFalse($this->catalog->allows('contributor', 'meter.manage'));
		self::assertFalse($this->catalog->allows('contributor', 'reading.correct'));
		self::assertTrue($this->catalog->allows('contributor', 'maintenance_definition.read'));
		self::assertFalse($this->catalog->allows('contributor', 'maintenance_definition.manage'));
		self::assertTrue($this->catalog->allows('contributor', 'activity.read'));
		self::assertTrue($this->catalog->allows('contributor', 'activity.create'));
		self::assertFalse($this->catalog->allows('contributor', 'activity.manage'));
		self::assertTrue($this->catalog->allows('contributor', 'maintenance_forecast.read'));
		self::assertTrue($this->catalog->allows('contributor', 'maintenance_occurrence.read'));
		self::assertFalse($this->catalog->allows('contributor', 'maintenance_occurrence.reconcile'));
		self::assertFalse($this->catalog->allows('contributor', 'reminder_policy.manage'));
		self::assertTrue($this->catalog->allows('contributor', 'profile.read'));
		self::assertFalse($this->catalog->allows('contributor', 'profile.install'));
	}

	public function testViewerIsReadOnlyForImplementedSurface(): void {
		self::assertTrue($this->catalog->allows('viewer', 'workspace.read'));
		self::assertTrue($this->catalog->allows('viewer', 'inventory.read'));
		self::assertFalse($this->catalog->allows('viewer', 'inventory.manage'));
		self::assertTrue($this->catalog->allows('viewer', 'meter.read'));
		self::assertFalse($this->catalog->allows('viewer', 'reading.create'));
		self::assertTrue($this->catalog->allows('viewer', 'maintenance_definition.read'));
		self::assertFalse($this->catalog->allows('viewer', 'maintenance_definition.manage'));
		self::assertTrue($this->catalog->allows('viewer', 'activity.read'));
		self::assertFalse($this->catalog->allows('viewer', 'activity.create'));
		self::assertFalse($this->catalog->allows('viewer', 'activity.manage'));
		self::assertTrue($this->catalog->allows('viewer', 'maintenance_forecast.read'));
		self::assertTrue($this->catalog->allows('viewer', 'maintenance_occurrence.read'));
		self::assertFalse($this->catalog->allows('viewer', 'maintenance_occurrence.reconcile'));
		self::assertTrue($this->catalog->allows('viewer', 'reminder_policy.read'));
		self::assertTrue($this->catalog->allows('viewer', 'profile.read'));
		self::assertFalse($this->catalog->allows('viewer', 'profile.install'));
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
