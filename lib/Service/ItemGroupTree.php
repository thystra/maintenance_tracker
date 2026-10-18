<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

/**
 * Pure tree rules for item groups. A tree is given as a map of group id to
 * parent id (null for a top-level group). Groups may nest to any depth; the
 * only structural rule is that a group can never become its own ancestor.
 */
final class ItemGroupTree {
	/** Upper bound on active groups per workspace, which also bounds every tree walk. */
	public const MAX_GROUPS = 1000;

	/**
	 * Whether moving $groupId under $newParentId would create a cycle.
	 *
	 * @param array<int, int|null> $parentOf
	 */
	public static function wouldCycle(array $parentOf, int $groupId, ?int $newParentId): bool {
		$seen = [];
		$current = $newParentId;
		while ($current !== null) {
			if ($current === $groupId || isset($seen[$current])) {
				return true;
			}
			$seen[$current] = true;
			$current = $parentOf[$current] ?? null;
		}
		return false;
	}

	/** @param array<int, int|null> $parentOf */
	public static function assertMove(array $parentOf, int $groupId, ?int $newParentId): void {
		if (self::wouldCycle($parentOf, $groupId, $newParentId)) {
			throw new ValidationException('A group cannot be moved into itself or one of its subgroups');
		}
	}

	/**
	 * The group and all of its descendants, parents before children.
	 *
	 * @param array<int, int|null> $parentOf
	 * @return list<int>
	 */
	public static function subtree(array $parentOf, int $groupId): array {
		$children = [];
		foreach ($parentOf as $id => $parent) {
			if ($parent !== null) {
				$children[$parent][] = $id;
			}
		}
		$out = [];
		$queue = [$groupId];
		$seen = [];
		while ($queue !== []) {
			$id = array_shift($queue);
			if (isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$out[] = $id;
			foreach ($children[$id] ?? [] as $child) {
				$queue[] = $child;
			}
		}
		return $out;
	}

	/**
	 * Order rows so every parent precedes its children (used by restore, where
	 * a moved group can have a smaller id than its new parent). Rows whose
	 * parent is not in the list keep their relative order at the front.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	public static function parentsFirst(array $rows, string $parentColumn = 'parent_id'): array {
		$ids = [];
		foreach ($rows as $row) {
			if (isset($row['id'])) {
				$ids[(int)$row['id']] = true;
			}
		}
		$placed = [];
		$out = [];
		$pending = $rows;
		while ($pending !== []) {
			$next = [];
			foreach ($pending as $row) {
				$parent = $row[$parentColumn] ?? null;
				if ($parent === null || !isset($ids[(int)$parent]) || isset($placed[(int)$parent])) {
					$out[] = $row;
					if (isset($row['id'])) {
						$placed[(int)$row['id']] = true;
					}
				} else {
					$next[] = $row;
				}
			}
			if (count($next) === count($pending)) {
				throw new ValidationException('Item groups in the export form a cycle');
			}
			$pending = $next;
		}
		return $out;
	}

	public static function name(mixed $value): string {
		return CatalogInput::text($value, 'name', 120);
	}

	public static function sort(mixed $value): int {
		if (!is_int($value) || $value < -1000000 || $value > 1000000) {
			throw new ValidationException('sort must be an integer between -1000000 and 1000000');
		}
		return $value;
	}
}
