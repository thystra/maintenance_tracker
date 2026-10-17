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
		'meter.created' => ['subjectType' => 'meter', 'level' => 'info', 'detailKeys' => []],
		'meter.updated' => ['subjectType' => 'meter', 'level' => 'info', 'detailKeys' => []],
		'meter.archived' => ['subjectType' => 'meter', 'level' => 'info', 'detailKeys' => []],
		'reading.created' => ['subjectType' => 'reading', 'level' => 'info', 'detailKeys' => []],
		'reading.corrected' => [
			'subjectType' => 'reading',
			'level' => 'info',
			'detailKeys' => ['supersedesReadingUuid'],
		],
		'work_group.created' => ['subjectType' => 'work_group', 'level' => 'info', 'detailKeys' => []],
		'work_group.updated' => ['subjectType' => 'work_group', 'level' => 'info', 'detailKeys' => []],
		'work_group.archived' => ['subjectType' => 'work_group', 'level' => 'info', 'detailKeys' => []],
		'work_definition.created' => ['subjectType' => 'work_definition', 'level' => 'info', 'detailKeys' => []],
		'work_definition.updated' => ['subjectType' => 'work_definition', 'level' => 'info', 'detailKeys' => []],
		'work_definition.archived' => ['subjectType' => 'work_definition', 'level' => 'info', 'detailKeys' => []],
		'activity.created' => ['subjectType' => 'activity', 'level' => 'info', 'detailKeys' => []],
		'activity.updated' => ['subjectType' => 'activity', 'level' => 'info', 'detailKeys' => []],
		'activity.archived' => ['subjectType' => 'activity', 'level' => 'info', 'detailKeys' => []],
		'reminder_policy.created' => ['subjectType' => 'reminder_policy', 'level' => 'info', 'detailKeys' => []],
		'reminder_policy.updated' => ['subjectType' => 'reminder_policy', 'level' => 'info', 'detailKeys' => []],
		'profile.installed' => ['subjectType' => 'profile_installation', 'level' => 'info', 'detailKeys' => ['profileKey', 'profileVersion', 'contentHash']],
		'fitment_pack.imported' => ['subjectType' => 'fitment_pack', 'level' => 'info', 'detailKeys' => ['packKey', 'packVersion', 'contentHash']],
		'fitment_mapping.created' => ['subjectType' => 'fitment_mapping', 'level' => 'info', 'detailKeys' => ['targetKey', 'matchState']],
		'part.created' => ['subjectType' => 'part', 'level' => 'info', 'detailKeys' => []],
		'part.updated' => ['subjectType' => 'part', 'level' => 'info', 'detailKeys' => []],
		'part.archived' => ['subjectType' => 'part', 'level' => 'info', 'detailKeys' => []],
		'vendor.created' => ['subjectType' => 'vendor', 'level' => 'info', 'detailKeys' => []],
		'vendor.updated' => ['subjectType' => 'vendor', 'level' => 'info', 'detailKeys' => []],
		'vendor.archived' => ['subjectType' => 'vendor', 'level' => 'info', 'detailKeys' => []],
		'cost.created' => ['subjectType' => 'cost', 'level' => 'info', 'detailKeys' => []],
		'cost.updated' => ['subjectType' => 'cost', 'level' => 'info', 'detailKeys' => []],
		'cost.archived' => ['subjectType' => 'cost', 'level' => 'info', 'detailKeys' => []],
		'cost_settings.updated' => ['subjectType' => 'workspace', 'level' => 'info', 'detailKeys' => ['defaultCurrency', 'taxEnabled', 'multiCurrency']],
		'link.created' => ['subjectType' => 'link', 'level' => 'info', 'detailKeys' => []],
		'link.archived' => ['subjectType' => 'link', 'level' => 'info', 'detailKeys' => []],
		'evidence.created' => ['subjectType' => 'evidence', 'level' => 'info', 'detailKeys' => []],
		'evidence.updated' => ['subjectType' => 'evidence', 'level' => 'info', 'detailKeys' => []],
		'evidence.archived' => ['subjectType' => 'evidence', 'level' => 'info', 'detailKeys' => []],
		'evidence.linked' => ['subjectType' => 'evidence', 'level' => 'info', 'detailKeys' => ['targetType', 'targetUuid']],
		'workspace.imported' => ['subjectType' => 'workspace', 'level' => 'security', 'detailKeys' => ['formatVersion', 'sections', 'rows']],
		'workspace.exported' => ['subjectType' => 'workspace', 'level' => 'security', 'detailKeys' => ['format', 'includeFiles', 'sections']],
		'evidence.unlinked' => ['subjectType' => 'evidence', 'level' => 'info', 'detailKeys' => ['targetType', 'targetUuid']],
		'workspace.ownership.offered' => ['subjectType' => 'ownership_transfer', 'level' => 'security', 'detailKeys' => ['toUid', 'expiresAt', 'leaveAfter']],
		'workspace.ownership.cancelled' => ['subjectType' => 'ownership_transfer', 'level' => 'security', 'detailKeys' => []],
		'workspace.ownership.declined' => ['subjectType' => 'ownership_transfer', 'level' => 'security', 'detailKeys' => []],
		'workspace.ownership.expired' => ['subjectType' => 'ownership_transfer', 'level' => 'security', 'detailKeys' => []],
		'workspace.ownership.accepted' => ['subjectType' => 'ownership_transfer', 'level' => 'security', 'detailKeys' => ['reason']],
		'workspace.ownership.aborted' => ['subjectType' => 'ownership_transfer', 'level' => 'security', 'detailKeys' => ['step']],
		'workspace.ownership.transferred' => ['subjectType' => 'ownership_transfer', 'level' => 'security', 'detailKeys' => ['fromUid', 'toUid', 'reason', 'previousOwner']],
		'workspace.orphaned' => ['subjectType' => 'workspace', 'level' => 'security', 'detailKeys' => ['formerOwnerUid']],
		'workspace.owner.assigned' => ['subjectType' => 'workspace', 'level' => 'security', 'detailKeys' => ['toUid', 'fromUid']],
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
