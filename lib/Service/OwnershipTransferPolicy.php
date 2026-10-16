<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

/**
 * Pure rules for workspace ownership transfer: the state machine, expiry
 * bounds, the quota decision, and the account-deletion successor choice.
 */
final class OwnershipTransferPolicy {
	public const STATUS_PENDING = 'pending';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_DECLINED = 'declined';
	public const STATUS_EXPIRED = 'expired';
	public const STATUS_ABORTED = 'aborted';
	public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_IN_PROGRESS];

	/**
	 * Ordered steps of an accepted transfer. Each step is persisted before it
	 * runs and is idempotent, so an interrupted transfer resumes where it stopped.
	 */
	public const STEPS = ['offered', 'snapshot', 'unshare', 'move', 'repoint', 'swap', 'share', 'done'];

	/** Steps before any file has moved: an in-progress transfer can still be aborted. */
	public const ABORTABLE_STEPS = ['snapshot', 'unshare', 'move'];

	public const REASON_OWNER = 'owner_request';
	public const REASON_ACCOUNT_DELETION = 'account_deletion';
	public const REASON_ADMIN = 'admin';

	public const DEFAULT_EXPIRY_DAYS = 7;
	public const MAX_EXPIRY_DAYS = 30;

	/** Owner-account deletion policies (app config `owner_deletion_policy`). */
	public const DELETION_TRANSFER = 'transfer_to_manager';
	public const DELETION_ORPHAN = 'orphan';

	public static function nextStep(string $step): string {
		$index = array_search($step, self::STEPS, true);
		if ($index === false || $step === 'done') {
			throw new \LogicException('No step follows ' . $step);
		}
		return self::STEPS[$index + 1];
	}

	public static function expiryDays(mixed $value): int {
		if ($value === null) {
			return self::DEFAULT_EXPIRY_DAYS;
		}
		if (!is_int($value) || $value < 1 || $value > self::MAX_EXPIRY_DAYS) {
			throw new ValidationException('expiresInDays must be an integer from 1 to ' . self::MAX_EXPIRY_DAYS);
		}
		return $value;
	}

	public static function isExpired(array $transfer, int $now): bool {
		return $transfer['status'] === self::STATUS_PENDING && (int)$transfer['expires_at'] <= $now;
	}

	/**
	 * Whether the evidence folder fits in the recipient's free space. Negative
	 * free space means unlimited or unknown quota in Nextcloud, which never blocks.
	 */
	public static function fits(int $folderBytes, ?int $freeBytes): bool {
		return $freeBytes === null || $freeBytes < 0 || $folderBytes <= $freeBytes;
	}

	public static function deletionPolicy(string $configured): string {
		return $configured === self::DELETION_ORPHAN ? self::DELETION_ORPHAN : self::DELETION_TRANSFER;
	}

	/**
	 * Successor when the owner account is deleted: the longest-standing Manager
	 * (earliest membership, then lowest membership id). Never a Contributor or
	 * Viewer: ownership is only ever escalated from a role that already manages
	 * the workspace.
	 *
	 * @param list<array{userUid: string, role: string, createdAt: int, id: int}> $members
	 */
	public static function successor(array $members, string $ownerUid): ?string {
		$managers = array_values(array_filter(
			$members,
			static fn (array $m): bool => $m['role'] === 'manager' && $m['userUid'] !== $ownerUid,
		));
		usort($managers, static fn (array $a, array $b): int => [$a['createdAt'], $a['id']] <=> [$b['createdAt'], $b['id']]);
		return $managers[0]['userUid'] ?? null;
	}
}
