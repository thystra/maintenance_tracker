<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Service\AuditEventCatalog;
use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\ExportPolicy;
use PHPUnit\Framework\TestCase;

final class ExportPolicyTest extends TestCase {
	private function policy(): ExportPolicy {
		return new ExportPolicy(new AuthorizationCatalog());
	}

	public function testExportCapabilityIsExplicitForEveryRoleAndReadOnly(): void {
		$catalog = new AuthorizationCatalog();
		foreach (['owner', 'manager', 'contributor', 'viewer'] as $role) {
			self::assertTrue($catalog->allows($role, AuthorizationCatalog::WORKSPACE_EXPORT), $role);
		}
	}

	public function testSectionsFollowEachSectionsReadCapability(): void {
		$catalog = new AuthorizationCatalog();
		foreach (['owner', 'manager', 'contributor', 'viewer'] as $role) {
			$sections = $this->policy()->sections($role, true);
			foreach (ExportPolicy::CAPABILITY as $section => $capability) {
				self::assertSame($catalog->allows($role, $capability), in_array($section, $sections['included'], true), $role . ':' . $section);
			}
		}
		self::assertContains('audit', $this->policy()->sections('owner', true)['included']);
	}

	public function testDisabledOutsideLinksOmitLinksAndBlankOfferUrls(): void {
		$sections = $this->policy()->sections('owner', false);
		self::assertNotContains('links', $sections['included']);
		self::assertContains(['section' => 'links', 'reason' => 'outside_links_disabled'], $sections['omitted']);
		foreach (ExportPolicy::URL_SECTIONS as $section => $column) {
			$row = $this->policy()->row($section, ['id' => 1, $column => 'https://example.test/x'], false);
			self::assertNull($row[$column]);
		}
	}

	public function testRowsDropInternalColumns(): void {
		$row = $this->policy()->row('assets', ['id' => 3, 'workspace_id' => 9, 'write_lock_token' => 'x', 'name' => 'Car'], true);
		self::assertSame(['id' => 3, 'name' => 'Car'], $row);
	}

	public function testEveryExportedTableIsAWorkspaceTableAndNothingSecretIsExported(): void {
		foreach (ExportPolicy::TABLES as $table) {
			self::assertStringStartsWith('maint_', $table);
			self::assertNotContains($table, ExportPolicy::EXCLUDED_TABLES);
		}
		foreach (array_keys(ExportPolicy::TABLES) as $section) {
			self::assertArrayHasKey($section, ExportPolicy::CAPABILITY);
		}
	}

	public function testSpreadsheetFormulaCellsAreEscaped(): void {
		foreach (['=SUM(A1)', '+1', '-2', '@x', "'q", "\tx", "\rx", '＝1'] as $value) {
			self::assertSame("'" . $value, ExportPolicy::spreadsheetCell($value));
		}
		self::assertSame('Oil change', ExportPolicy::spreadsheetCell('Oil change'));
		self::assertSame('', ExportPolicy::spreadsheetCell(null));
		self::assertSame('true', ExportPolicy::spreadsheetCell(true));
	}

	public function testCsvUnionsColumnsAndQuotes(): void {
		$csv = ExportPolicy::csv([['a' => 'x,y', 'b' => '=1'], ['a' => "line\nbreak", 'c' => 2]]);
		self::assertSame("a,b,c\n\"x,y\",'=1,\n\"line\nbreak\",,2\n", $csv);
	}

	public function testFileEntriesCannotEscapeTheFilesDirectory(): void {
		self::assertSame('files/u-passwd', ExportPolicy::fileEntry('u', '../../etc/passwd'));
		self::assertSame('files/u-file', ExportPolicy::fileEntry('u', '...'));
		self::assertSame('files/u-a_b.pdf', ExportPolicy::fileEntry('u', 'a b.pdf'));
	}

	public function testExportAuditEventIsCatalogued(): void {
		self::assertSame(['format', 'includeFiles', 'sections'], (new AuditEventCatalog())->definition('workspace.exported')['detailKeys']);
	}
}
