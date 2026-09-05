<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

final class AuditEventCatalog {
	public const VERSION = 1;

	/**
	 * @var array<string, array{subjectType: string, level: string, detailKeys: list<string>}>
	 */
	private const EVENTS = [
		'asset.created' => ['subjectType' => 'asset', 'level' => 'info', 'detailKeys' => []],
		'asset.updated' => ['subjectType' => 'asset', 'level' => 'info', 'detailKeys' => []],
		'asset.archived' => ['subjectType' => 'asset', 'level' => 'info', 'detailKeys' => []],
		'category.created' => ['subjectType' => 'category', 'level' => 'info', 'detailKeys' => []],
		'component.created' => ['subjectType' => 'component', 'level' => 'info', 'detailKeys' => []],
		'specification.created' => ['subjectType' => 'specification', 'level' => 'info', 'detailKeys' => []],
		'relationship.created' => ['subjectType' => 'relationship', 'level' => 'info', 'detailKeys' => []],
		'relationship.updated' => ['subjectType' => 'relationship', 'level' => 'info', 'detailKeys' => []],
		'relationship.archived' => ['subjectType' => 'relationship', 'level' => 'info', 'detailKeys' => []],
		'assignment.created' => ['subjectType' => 'assignment', 'level' => 'info', 'detailKeys' => []],
		'assignment.updated' => ['subjectType' => 'assignment', 'level' => 'info', 'detailKeys' => []],
		'assignment.archived' => ['subjectType' => 'assignment', 'level' => 'info', 'detailKeys' => []],
		'workspace.member.added' => [
			'subjectType' => 'workspace_member',
			'level' => 'security',
			'detailKeys' => ['role'],
		],
		'workspace.member.role_changed' => [
			'subjectType' => 'workspace_member',
			'level' => 'security',
			'detailKeys' => ['fromRole', 'toRole'],
		],
		'workspace.member.removed' => [
			'subjectType' => 'workspace_member',
			'level' => 'security',
			'detailKeys' => ['role'],
		],
	];

	/**
	 * @return array{subjectType: string, level: string, detailKeys: list<string>}
	 */
	public function definition(string $eventType): array {
		if (!isset(self::EVENTS[$eventType])) {
			throw new \InvalidArgumentException("Unknown audit event: {$eventType}");
		}

		return self::EVENTS[$eventType];
	}

	public function eventForChange(string $entityType, string $operation, int $revision): ?string {
		if ($operation === 'delete') {
			$event = "{$entityType}.archived";
		} elseif ($operation === 'upsert' && $revision === 1) {
			$event = "{$entityType}.created";
		} elseif ($operation === 'upsert') {
			$event = "{$entityType}.updated";
		} else {
			return null;
		}

		return isset(self::EVENTS[$event]) ? $event : null;
	}

	/**
	 * @return list<string>
	 */
	public function eventTypes(): array {
		return array_keys(self::EVENTS);
	}
}
