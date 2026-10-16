<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\Unit\Service;

use OCA\MaintenanceTracker\Service\AuditEventCatalog;
use OCA\MaintenanceTracker\Service\IntegrationEventMap;
use PHPUnit\Framework\TestCase;

final class IntegrationEventMapTest extends TestCase {
	public function testEveryMappedEventExistsInTheAuditVocabulary(): void {
		$catalog = new AuditEventCatalog();
		$map = new IntegrationEventMap();
		foreach ($map->mappedEvents() as $event) {
			self::assertContains($event, $catalog->eventTypes(), $event);
			self::assertContains($map->activity($event)['type'], $map->activityTypes());
		}
		foreach (['workspace.ownership.accepted', 'workspace.ownership.declined', 'workspace.ownership.expired', 'workspace.ownership.cancelled'] as $event) {
			self::assertContains($event, $catalog->eventTypes());
		}
	}

	public function testRequiredIntegrationScopeIsCovered(): void {
		$map = new IntegrationEventMap();
		self::assertSame(IntegrationEventMap::TYPE_MAINTENANCE, $map->activity('activity.created')['type']);
		self::assertSame(IntegrationEventMap::TYPE_MAINTENANCE, $map->activity('work_definition.created')['type']);
		self::assertSame(IntegrationEventMap::TYPE_EVIDENCE, $map->activity('evidence.linked')['type']);
		self::assertSame(IntegrationEventMap::TYPE_MEMBERSHIP, $map->activity('workspace.member.added')['type']);
		self::assertSame(IntegrationEventMap::TYPE_OWNERSHIP, $map->activity('workspace.ownership.transferred')['type']);
		self::assertNull($map->activity('reading.created'));
		self::assertNull($map->activity('cost.created'));
	}

	public function testOfferIsPrivateToParticipantsButTransferIsVisibleToMembers(): void {
		$map = new IntegrationEventMap();
		self::assertSame(['a', 'b'], $map->recipients('workspace.ownership.offered', 'uuid', ['a', 'b', 'c'], ['b', 'a']));
		self::assertSame(['a', 'b', 'c'], $map->recipients('workspace.ownership.transferred', 'uuid', ['c', 'b'], ['a', 'b']));
		self::assertSame([], $map->recipients('reading.created', 'uuid', ['a'], []));
	}

	public function testRemovedMemberIsToldAndSystemIsNeverARecipient(): void {
		$map = new IntegrationEventMap();
		self::assertSame(['a', 'gone'], $map->recipients('workspace.member.removed', 'gone', ['a'], []));
		self::assertSame(['a'], $map->recipients('workspace.owner.assigned', 'uuid', ['a'], ['system', '']));
	}

	public function testNotificationEffects(): void {
		$map = new IntegrationEventMap();
		self::assertSame(['send' => ['subject' => 'transfer_offered', 'recipient' => 'to'], 'closesOffer' => false], $map->notification('workspace.ownership.offered'));
		self::assertSame(['send' => ['subject' => 'transfer_declined', 'recipient' => 'from'], 'closesOffer' => true], $map->notification('workspace.ownership.declined'));
		self::assertSame(['send' => ['subject' => 'transfer_expired', 'recipient' => 'from'], 'closesOffer' => true], $map->notification('workspace.ownership.expired'));
		self::assertSame(['send' => null, 'closesOffer' => true], $map->notification('workspace.ownership.accepted'));
		self::assertSame(['send' => null, 'closesOffer' => false], $map->notification('asset.created'));
	}

	public function testOnlyBoundedDetailKeysTravelIntoActivity(): void {
		$map = new IntegrationEventMap();
		self::assertSame(
			['role' => 'manager', 'targetType' => 'asset'],
			$map->safeDetails(['role' => 'manager', 'targetType' => 'asset', 'targetUuid' => 'x', 'contentHash' => 'h', 'toUid' => 'u']),
		);
		self::assertSame(['role' => str_repeat('r', 64)], $map->safeDetails(['role' => str_repeat('r', 500)]));
		self::assertSame([], $map->safeDetails(['role' => ['nested']]));
	}
}
