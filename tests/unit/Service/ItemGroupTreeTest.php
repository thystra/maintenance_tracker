<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\AuditEventCatalog;
use OCA\MaintenanceTracker\Service\ExportPolicy;
use OCA\MaintenanceTracker\Service\ImportPolicy;
use OCA\MaintenanceTracker\Service\ItemGroupTree;
use PHPUnit\Framework\TestCase;

final class ItemGroupTreeTest extends TestCase {
	/** Vehicles(1) > Fleet 1(2) > Trucks(3); House(4) > Basement(5). */
	private const TREE = [1 => null, 2 => 1, 3 => 2, 4 => null, 5 => 4];

	public function testMovesThatWouldCreateACycleAreRefused(): void {
		self::assertTrue(ItemGroupTree::wouldCycle(self::TREE, 1, 1));
		self::assertTrue(ItemGroupTree::wouldCycle(self::TREE, 1, 3));
		self::assertTrue(ItemGroupTree::wouldCycle(self::TREE, 2, 3));
		self::assertFalse(ItemGroupTree::wouldCycle(self::TREE, 3, 1));
		self::assertFalse(ItemGroupTree::wouldCycle(self::TREE, 3, null));
		self::assertFalse(ItemGroupTree::wouldCycle(self::TREE, 2, 5));
		$this->expectException(ValidationException::class);
		ItemGroupTree::assertMove(self::TREE, 1, 2);
	}

	public function testCorruptLoopsTerminate(): void {
		self::assertTrue(ItemGroupTree::wouldCycle([1 => 2, 2 => 1, 3 => null], 3, 1));
	}

	public function testSubtreeListsParentsBeforeChildren(): void {
		self::assertSame([1, 2, 3], ItemGroupTree::subtree(self::TREE, 1));
		self::assertSame([5], ItemGroupTree::subtree(self::TREE, 5));
	}

	public function testRestoreOrdersParentsFirst(): void {
		$rows = [['id' => 1, 'parent_id' => 3], ['id' => 2, 'parent_id' => null], ['id' => 3, 'parent_id' => 2]];
		self::assertSame([2, 3, 1], array_column(ItemGroupTree::parentsFirst($rows), 'id'));
		$this->expectException(ValidationException::class);
		ItemGroupTree::parentsFirst([['id' => 1, 'parent_id' => 2], ['id' => 2, 'parent_id' => 1]]);
	}

	public function testNamesAndSortAreBounded(): void {
		self::assertSame('Fleet 1', ItemGroupTree::name('  Fleet 1 '));
		self::assertSame(5, ItemGroupTree::sort(5));
		foreach ([fn () => ItemGroupTree::name(''), fn () => ItemGroupTree::name(str_repeat('x', 121)), fn () => ItemGroupTree::name("a\nb"), fn () => ItemGroupTree::sort('1'), fn () => ItemGroupTree::sort(2000000)] as $bad) {
			try {
				$bad();
				self::fail('expected a validation error');
			} catch (ValidationException) {
				self::assertTrue(true);
			}
		}
	}

	public function testGroupsAreExportedRestoredAndAudited(): void {
		self::assertSame('maint_item_groups', ExportPolicy::TABLES['item_groups']);
		self::assertSame('maint_asset_grp', ExportPolicy::TABLES['asset_groups']);
		$position = array_flip(ImportPolicy::ORDER);
		self::assertLessThan($position['asset_groups'], $position['item_groups']);
		self::assertLessThan($position['asset_groups'], $position['assets']);
		self::assertSame('item_groups', ImportPolicy::reference('asset_groups', 'group_id'));
		self::assertSame('item_groups', ImportPolicy::reference('item_groups', 'parent_id'));
		self::assertSame('work_groups', ImportPolicy::reference('work_definitions', 'group_id'));
		$catalog = new AuditEventCatalog();
		self::assertSame(['parentUuid'], $catalog->definition('item_group.moved')['detailKeys']);
		self::assertSame(['groupUuid'], $catalog->definition('asset.group_changed')['detailKeys']);
		self::assertSame('item_group.created', $catalog->eventForChange('item_group', 'upsert', 1));
		self::assertSame('item_group.archived', $catalog->eventForChange('item_group', 'delete', 4));
	}
}
