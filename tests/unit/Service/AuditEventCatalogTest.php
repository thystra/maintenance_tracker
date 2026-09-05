<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Service\AuditEventCatalog;
use PHPUnit\Framework\TestCase;

final class AuditEventCatalogTest extends TestCase {
	public function testImplementedMutationVocabularyIsStable(): void {
		$catalog = new AuditEventCatalog();
		foreach ([
			'asset.created',
			'asset.updated',
			'asset.archived',
			'category.created',
			'component.created',
			'specification.created',
			'relationship.created',
			'relationship.updated',
			'relationship.archived',
			'assignment.created',
			'assignment.updated',
			'assignment.archived',
			'meter.created',
			'meter.updated',
			'meter.archived',
			'reading.created',
			'reading.corrected',
			'workspace.member.added',
			'workspace.member.role_changed',
			'workspace.member.removed',
		] as $eventType) {
			self::assertContains($eventType, $catalog->eventTypes());
		}
	}

	public function testChangeJournalMappingUsesRevisionForCreateVsUpdate(): void {
		$catalog = new AuditEventCatalog();
		self::assertSame('asset.created', $catalog->eventForChange('asset', 'upsert', 1));
		self::assertSame('asset.updated', $catalog->eventForChange('asset', 'upsert', 2));
		self::assertSame('asset.archived', $catalog->eventForChange('asset', 'delete', 3));
		self::assertNull($catalog->eventForChange('unknown', 'upsert', 1));
	}

	public function testReadingCorrectionAuditOnlyCarriesSupersededUuid(): void {
		$catalog = new AuditEventCatalog();
		self::assertSame(
			['supersedesReadingUuid'],
			$catalog->definition('reading.corrected')['detailKeys'],
		);
	}

	public function testMembershipAuditDetailsAreBoundedToRoleMetadata(): void {
		$catalog = new AuditEventCatalog();
		self::assertSame(
			['fromRole', 'toRole'],
			$catalog->definition('workspace.member.role_changed')['detailKeys'],
		);
		self::assertSame(
			'security',
			$catalog->definition('workspace.member.removed')['level'],
		);
	}
}
