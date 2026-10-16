<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\Unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\AuditEventCatalog;
use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\OwnershipTransferPolicy as P;
use PHPUnit\Framework\TestCase;

final class OwnershipTransferPolicyTest extends TestCase {
	public function testStepsAdvanceInOrderAndRolesSwapOnlyAfterFilesMoved(): void {
		$step = 'offered';
		$seen = [];
		while ($step !== 'done') {
			$step = P::nextStep($step);
			$seen[] = $step;
		}
		self::assertSame(['snapshot', 'unshare', 'move', 'repoint', 'swap', 'share', 'done'], $seen);
		self::assertLessThan(array_search('swap', P::STEPS, true), array_search('move', P::STEPS, true));
		self::assertNotContains('repoint', P::ABORTABLE_STEPS);
		self::assertNotContains('swap', P::ABORTABLE_STEPS);
		$this->expectException(\LogicException::class);
		P::nextStep('done');
	}

	public function testExpiryBounds(): void {
		self::assertSame(7, P::expiryDays(null));
		self::assertSame(30, P::expiryDays(30));
		foreach ([0, 31, '7', 1.5] as $bad) {
			try {
				P::expiryDays($bad);
				self::fail('accepted ' . var_export($bad, true));
			} catch (ValidationException) {
			}
		}
		self::assertTrue(P::isExpired(['status' => 'pending', 'expires_at' => 100], 100));
		self::assertFalse(P::isExpired(['status' => 'pending', 'expires_at' => 101], 100));
		self::assertFalse(P::isExpired(['status' => 'in_progress', 'expires_at' => 1], 100));
	}

	public function testQuotaDecision(): void {
		self::assertTrue(P::fits(10, null));
		self::assertTrue(P::fits(10, -3));
		self::assertTrue(P::fits(10, 10));
		self::assertFalse(P::fits(11, 10));
	}

	public function testSuccessorIsLongestStandingManagerOnly(): void {
		$members = [
			['userUid' => 'owner', 'role' => 'owner', 'createdAt' => 1, 'id' => 1],
			['userUid' => 'early-contributor', 'role' => 'contributor', 'createdAt' => 2, 'id' => 2],
			['userUid' => 'late-manager', 'role' => 'manager', 'createdAt' => 9, 'id' => 3],
			['userUid' => 'tie-b', 'role' => 'manager', 'createdAt' => 5, 'id' => 6],
			['userUid' => 'tie-a', 'role' => 'manager', 'createdAt' => 5, 'id' => 5],
		];
		self::assertSame('tie-a', P::successor($members, 'owner'));
		self::assertNull(P::successor([$members[0], $members[1]], 'owner'));
		self::assertSame(P::DELETION_ORPHAN, P::deletionPolicy('orphan'));
		self::assertSame(P::DELETION_TRANSFER, P::deletionPolicy('anything-else'));
	}

	public function testOnlyOwnersMayTransferAndEventsAreAudited(): void {
		$catalog = new AuthorizationCatalog();
		self::assertTrue($catalog->allows('owner', AuthorizationCatalog::WORKSPACE_OWNERSHIP_TRANSFER));
		foreach (['manager', 'contributor', 'viewer'] as $role) {
			self::assertFalse($catalog->allows($role, AuthorizationCatalog::WORKSPACE_OWNERSHIP_TRANSFER));
		}
		$events = (new AuditEventCatalog())->eventTypes();
		foreach (['offered', 'cancelled', 'declined', 'expired', 'accepted', 'aborted', 'transferred'] as $event) {
			self::assertContains('workspace.ownership.' . $event, $events);
		}
		self::assertContains('workspace.orphaned', $events);
		self::assertContains('workspace.owner.assigned', $events);
	}
}
