<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\ExportPolicy;
use OCA\MaintenanceTracker\Service\ImportPolicy;
use PHPUnit\Framework\TestCase;

final class ImportPolicyTest extends TestCase {
	private const A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
	private const B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

	/** @param array<string, mixed> $override */
	private static function doc(array $override = []): array {
		return $override + ['format' => ExportPolicy::FORMAT, 'formatVersion' => 1, 'role' => 'owner', 'tables' => [], 'evidence' => []];
	}

	public function testEveryExportedSectionIsRestoredOrDeliberatelySkipped(): void {
		foreach (array_keys(ExportPolicy::TABLES) as $section) {
			self::assertTrue(in_array($section, ImportPolicy::ORDER, true) || in_array($section, ImportPolicy::NOT_RESTORED, true), $section);
		}
		self::assertSame(count(ImportPolicy::ORDER), count(array_unique(ImportPolicy::ORDER)));
	}

	public function testReferencedSectionsComeFirst(): void {
		$position = array_flip(ImportPolicy::ORDER);
		$columns = array_merge(...array_values(ImportPolicy::REFERENCES));
		foreach (ImportPolicy::REFERENCES as $section => $references) {
			foreach ($references as $target) {
				if ($section === '*') {
					continue;
				}
				self::assertLessThanOrEqual($position[$section], $position[$target], "$section -> $target");
			}
		}
		self::assertArrayHasKey('asset_id', $columns);
		self::assertLessThan($position['activity_parts'], $position['parts']);
		self::assertLessThan($position['activity_parts'], $position['vendors']);
		self::assertLessThan($position['part_compatibility'], $position['asset_profiles']);
		self::assertLessThan($position['activity_meters'], $position['readings']);
		self::assertLessThan($position['fitments'], $position['fitment_targets']);
	}

	public function testDocumentEnvelopeIsValidated(): void {
		self::assertSame('owner', ImportPolicy::document(self::doc())['role']);
		foreach ([
			self::doc(['format' => 'other']),
			self::doc(['formatVersion' => 2]),
			self::doc(['role' => 'viewer']),
			self::doc(['tables' => ['secrets' => []]]),
			self::doc(['tables' => ['assets' => [['bad column' => 1]]]]),
			self::doc(['tables' => ['assets' => [['name' => ['nested']]]]]),
		] as $document) {
			try {
				ImportPolicy::document($document);
				self::fail('accepted ' . json_encode($document));
			} catch (ValidationException) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testRemapRewritesIdsUuidsAndRejectsUnknownColumns(): void {
		$map = ImportPolicy::uuidMap(['assets' => [['uuid' => self::A]]], [], fn (): string => self::B);
		self::assertSame([self::A => self::B], $map);
		$types = ['uuid' => 'string', 'asset_id' => 'int', 'definition_uuid' => 'string', 'monotonic' => 'bool', 'revision' => 'int'];
		$row = ImportPolicy::remap('meters', ['id' => 7, 'workspace_id' => 3, 'uuid' => self::A, 'asset_id' => '5', 'monotonic' => 't', 'revision' => '2'], ['assets' => [5 => 50]], $map, $types);
		self::assertSame(['uuid' => self::B, 'asset_id' => 50, 'monotonic' => true, 'revision' => 2], $row);
		self::assertSame('x ' . self::B, ImportPolicy::rewrite('x ' . strtoupper(self::A), $map));

		$this->expectException(ValidationException::class);
		ImportPolicy::remap('meters', ['secret' => 1], [], $map, $types);
	}

	public function testDanglingAndUnclassifiedReferencesFail(): void {
		$types = ['asset_id' => 'int', 'file_id' => 'int'];
		foreach ([['asset_id' => 9], ['file_id' => 1]] as $row) {
			try {
				ImportPolicy::remap('meters', $row, [], [], $types);
				self::fail('accepted ' . json_encode($row));
			} catch (ValidationException) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testPolymorphicLinksAndVerbatimContent(): void {
		$types = ['subject_type' => 'string', 'subject_id' => 'int', 'content_json' => 'string'];
		self::assertSame(['subject_type' => 'vendor', 'subject_id' => 4], ImportPolicy::remap('links', ['subject_type' => 'vendor', 'subject_id' => 2], ['vendors' => [2 => 4]], [], $types));
		$content = '{"id":"' . self::A . '"}';
		self::assertSame($content, ImportPolicy::remap('profile_revisions', ['content_json' => $content], [], [self::A => self::B], $types)['content_json']);
	}
}
