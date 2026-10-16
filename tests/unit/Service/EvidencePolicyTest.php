<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\AuditEventCatalog;
use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\EvidenceFolderService;
use OCA\MaintenanceTracker\Service\EvidencePolicy;
use OCP\Constants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidencePolicyTest extends TestCase {
	/** @return array<string, array{string, string, bool}> */
	public static function mimeCases(): array {
		return [
			'photo jpeg' => ['photo', 'image/jpeg', true],
			'photo pdf' => ['photo', 'application/pdf', false],
			'video mp4' => ['video', 'video/mp4', true],
			'video image' => ['video', 'image/png', false],
			'receipt pdf' => ['receipt', 'application/pdf', true],
			'receipt photo' => ['receipt', 'image/heic', true],
			'invoice text' => ['invoice', 'text/plain', true],
			'invoice zip' => ['invoice', 'application/zip', false],
			'document anything' => ['document', 'application/vnd.oasis.opendocument.text', true],
			'other anything' => ['other', 'application/octet-stream', true],
			'folder never' => ['other', 'httpd/unix-directory', false],
		];
	}

	#[DataProvider('mimeCases')]
	public function testKindMimeCompatibility(string $kind, string $mime, bool $ok): void {
		if (!$ok) {
			$this->expectException(ValidationException::class);
		}
		EvidencePolicy::assertMimeFits($kind, $mime);
		self::assertTrue($ok);
	}

	public function testKindsAndTargetsAreClosedVocabularies(): void {
		self::assertSame(['photo', 'video', 'receipt', 'invoice', 'document', 'other'], EvidencePolicy::KINDS);
		self::assertSame('cost', EvidencePolicy::targetType('cost'));
		$this->expectException(ValidationException::class);
		EvidencePolicy::targetType('workspace');
	}

	public function testUploadNamesAreCollisionResistantAndSafe(): void {
		self::assertSame('2026-10-16-abcdef12-Oil change receipt.pdf', EvidencePolicy::uploadName('Oil change receipt.PDF', '2026-10-16', 'ABCDEF1234'));
		self::assertSame('2026-10-16-abcdef12-evidence', EvidencePolicy::uploadName('../..', '2026-10-16', 'abcdef12'));
		self::assertSame('2026-10-16-abcdef12-a b c.jpg', EvidencePolicy::uploadName('a/b\\c.jpg', '2026-10-16', 'abcdef12'));
	}

	public function testFolderSegmentsNeverEscapeTheirParent(): void {
		self::assertSame('Lawn mower', EvidenceFolderService::segment('  Lawn/mower  ', 'Asset'));
		self::assertSame('Asset', EvidenceFolderService::segment('..', 'Asset'));
		self::assertSame('Asset', EvidenceFolderService::segment("\0\n", 'Asset'));
		self::assertSame(64, mb_strlen(EvidenceFolderService::segment(str_repeat('x', 300), 'Asset')));
	}

	public function testShareTiersFollowRoles(): void {
		self::assertSame(Constants::PERMISSION_READ, EvidenceFolderService::ROLE_PERMISSIONS['viewer']);
		self::assertSame(0, EvidenceFolderService::ROLE_PERMISSIONS['contributor'] & Constants::PERMISSION_DELETE);
		self::assertSame(Constants::PERMISSION_DELETE, EvidenceFolderService::ROLE_PERMISSIONS['manager'] & Constants::PERMISSION_DELETE);
		foreach (EvidenceFolderService::ROLE_PERMISSIONS as $permissions) {
			self::assertSame(0, $permissions & Constants::PERMISSION_SHARE, 'members must not reshare the evidence folder');
		}
		self::assertArrayNotHasKey('owner', EvidenceFolderService::ROLE_PERMISSIONS);
	}

	/** @return array<string, array{string, string, bool}> */
	public static function matrix(): array {
		$grants = [
			'evidence.read' => ['owner' => true, 'manager' => true, 'contributor' => true, 'viewer' => true],
			'evidence.create' => ['owner' => true, 'manager' => true, 'contributor' => true, 'viewer' => false],
			'evidence.manage' => ['owner' => true, 'manager' => true, 'contributor' => false, 'viewer' => false],
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

	public function testAuditVocabulary(): void {
		$catalog = new AuditEventCatalog();
		self::assertSame('evidence.created', $catalog->eventForChange('evidence', 'upsert', 1));
		self::assertSame('evidence.archived', $catalog->eventForChange('evidence', 'delete', 2));
		self::assertSame(['targetType', 'targetUuid'], $catalog->definition('evidence.linked')['detailKeys']);
	}

	public function testLinkedDefaultTitleIsKindLabelNotFileName(): void {
		self::assertSame('Receipt', EvidencePolicy::defaultTitle('receipt'));
		self::assertSame('Receipt', EvidencePolicy::title('', EvidencePolicy::defaultTitle('receipt')));
	}
}
