<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

/**
 * Maps append-only audit events onto Nextcloud Activity types and
 * notification effects. The audit trail is the single source; Activity and
 * notifications are projections of it. Only bounded identifiers and
 * reviewed detail keys travel into an activity event; titles, names, notes
 * and file metadata are resolved at render time for the viewing user.
 */
final class IntegrationEventMap {
	public const TYPE_MAINTENANCE = 'maintenance_tracker_maintenance';
	public const TYPE_EVIDENCE = 'maintenance_tracker_evidence';
	public const TYPE_MEMBERSHIP = 'maintenance_tracker_membership';
	public const TYPE_OWNERSHIP = 'maintenance_tracker_ownership';

	public const AUDIENCE_MEMBERS = 'members';
	public const AUDIENCE_PARTICIPANTS = 'participants';

	/** Detail keys that may be copied into activity parameters (never free text). */
	private const SAFE_DETAIL_KEYS = ['role', 'fromRole', 'toRole', 'targetType', 'reason', 'previousOwner'];

	/** @var array<string, array{type: string, audience: string}> */
	private const EVENTS = [
		'activity.created' => ['type' => self::TYPE_MAINTENANCE, 'audience' => self::AUDIENCE_MEMBERS],
		'activity.updated' => ['type' => self::TYPE_MAINTENANCE, 'audience' => self::AUDIENCE_MEMBERS],
		'activity.archived' => ['type' => self::TYPE_MAINTENANCE, 'audience' => self::AUDIENCE_MEMBERS],
		'work_definition.created' => ['type' => self::TYPE_MAINTENANCE, 'audience' => self::AUDIENCE_MEMBERS],
		'work_definition.updated' => ['type' => self::TYPE_MAINTENANCE, 'audience' => self::AUDIENCE_MEMBERS],
		'work_definition.archived' => ['type' => self::TYPE_MAINTENANCE, 'audience' => self::AUDIENCE_MEMBERS],
		'evidence.created' => ['type' => self::TYPE_EVIDENCE, 'audience' => self::AUDIENCE_MEMBERS],
		'evidence.archived' => ['type' => self::TYPE_EVIDENCE, 'audience' => self::AUDIENCE_MEMBERS],
		'evidence.linked' => ['type' => self::TYPE_EVIDENCE, 'audience' => self::AUDIENCE_MEMBERS],
		'evidence.unlinked' => ['type' => self::TYPE_EVIDENCE, 'audience' => self::AUDIENCE_MEMBERS],
		'workspace.member.added' => ['type' => self::TYPE_MEMBERSHIP, 'audience' => self::AUDIENCE_MEMBERS],
		'workspace.member.role_changed' => ['type' => self::TYPE_MEMBERSHIP, 'audience' => self::AUDIENCE_MEMBERS],
		'workspace.member.removed' => ['type' => self::TYPE_MEMBERSHIP, 'audience' => self::AUDIENCE_MEMBERS],
		'workspace.ownership.offered' => ['type' => self::TYPE_OWNERSHIP, 'audience' => self::AUDIENCE_PARTICIPANTS],
		'workspace.ownership.cancelled' => ['type' => self::TYPE_OWNERSHIP, 'audience' => self::AUDIENCE_PARTICIPANTS],
		'workspace.ownership.declined' => ['type' => self::TYPE_OWNERSHIP, 'audience' => self::AUDIENCE_PARTICIPANTS],
		'workspace.ownership.expired' => ['type' => self::TYPE_OWNERSHIP, 'audience' => self::AUDIENCE_PARTICIPANTS],
		'workspace.ownership.aborted' => ['type' => self::TYPE_OWNERSHIP, 'audience' => self::AUDIENCE_PARTICIPANTS],
		'workspace.ownership.transferred' => ['type' => self::TYPE_OWNERSHIP, 'audience' => self::AUDIENCE_MEMBERS],
		'workspace.owner.assigned' => ['type' => self::TYPE_OWNERSHIP, 'audience' => self::AUDIENCE_MEMBERS],
		'workspace.orphaned' => ['type' => self::TYPE_OWNERSHIP, 'audience' => self::AUDIENCE_MEMBERS],
	];

	/** Closing an offer withdraws the recipient's pending-offer notification. */
	private const CLOSES_OFFER = [
		'workspace.ownership.cancelled',
		'workspace.ownership.declined',
		'workspace.ownership.expired',
		'workspace.ownership.accepted',
		'workspace.ownership.aborted',
		'workspace.ownership.transferred',
	];

	/** @return array{type: string, audience: string}|null */
	public function activity(string $eventType): ?array {
		return self::EVENTS[$eventType] ?? null;
	}

	/** @return list<string> */
	public function activityTypes(): array {
		return [self::TYPE_MAINTENANCE, self::TYPE_EVIDENCE, self::TYPE_MEMBERSHIP, self::TYPE_OWNERSHIP];
	}

	/** @return list<string> */
	public function mappedEvents(): array {
		return array_keys(self::EVENTS);
	}

	/**
	 * Notification effects: the subject to send and to which participant
	 * ('to' recipient or 'from' offerer), and whether the pending offer
	 * notification is withdrawn.
	 *
	 * @return array{send: ?array{subject: string, recipient: string}, closesOffer: bool}
	 */
	public function notification(string $eventType): array {
		$send = match ($eventType) {
			'workspace.ownership.offered' => ['subject' => 'transfer_offered', 'recipient' => 'to'],
			'workspace.ownership.declined' => ['subject' => 'transfer_declined', 'recipient' => 'from'],
			'workspace.ownership.expired' => ['subject' => 'transfer_expired', 'recipient' => 'from'],
			default => null,
		};
		return ['send' => $send, 'closesOffer' => in_array($eventType, self::CLOSES_OFFER, true)];
	}

	/**
	 * @param array<string, mixed> $details
	 * @return array<string, scalar|null>
	 */
	public function safeDetails(array $details): array {
		$safe = [];
		foreach (self::SAFE_DETAIL_KEYS as $key) {
			if (array_key_exists($key, $details) && (is_scalar($details[$key]) || $details[$key] === null)) {
				$value = $details[$key];
				$safe[$key] = is_string($value) ? substr($value, 0, 64) : $value;
			}
		}
		return $safe;
	}

	/**
	 * Recipient user IDs. Members are the current workspace members; a removed
	 * member is still told about the removal; participants are the offerer and
	 * recipient of an ownership transfer. System actors are never recipients.
	 *
	 * @param list<string> $memberUids
	 * @param list<string> $participantUids
	 * @return list<string>
	 */
	public function recipients(string $eventType, string $subjectId, array $memberUids, array $participantUids): array {
		$mapping = $this->activity($eventType);
		if ($mapping === null) {
			return [];
		}
		$uids = $mapping['audience'] === self::AUDIENCE_PARTICIPANTS ? $participantUids : [...$memberUids, ...$participantUids];
		if ($eventType === 'workspace.member.removed') {
			$uids[] = $subjectId;
		}
		$uids = array_values(array_unique(array_filter($uids, static fn (string $uid): bool => $uid !== '' && $uid !== 'system')));
		sort($uids, SORT_STRING);
		return $uids;
	}
}
