<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\WorkspaceMapper;
use OCA\MaintenanceTracker\Db\WorkspaceMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * Read-time labels for Activity and notifications. Workspace names are
 * disclosed only to current members.
 */
final class IntegrationLabels {
	public function __construct(
		private IUserManager $userManager,
		private WorkspaceMapper $workspaces,
		private WorkspaceMemberMapper $members,
	) {
	}

	/** @return array{type: string, id: string, name: string} */
	public function user(string $uid, string $fallback): array {
		$displayName = $uid !== '' && $uid !== 'system' ? $this->userManager->getDisplayName($uid) : null;
		if ($displayName !== null && $displayName !== '') {
			return ['type' => 'user', 'id' => $uid, 'name' => $displayName];
		}
		return ['type' => 'highlight', 'id' => $uid === '' ? 'unknown' : $uid, 'name' => $fallback];
	}

	/** @return array{type: string, id: string, name: string} */
	public function workspace(int $workspaceId, string $viewerUid, string $fallback): array {
		if ($this->isMember($workspaceId, $viewerUid)) {
			try {
				$workspace = $this->workspaces->findById($workspaceId);
				return ['type' => 'highlight', 'id' => $workspace->getUuid(), 'name' => $workspace->getName()];
			} catch (DoesNotExistException) {
				// Fall through to the neutral label.
			}
		}
		return ['type' => 'highlight', 'id' => 'workspace', 'name' => $fallback];
	}

	public function isMember(int $workspaceId, string $uid): bool {
		try {
			$this->members->findByWorkspaceAndUser($workspaceId, $uid);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	/** @param array<string, array{name: string}> $params */
	public static function plain(string $subject, array $params): string {
		$replace = [];
		foreach ($params as $key => $param) {
			$replace['{' . $key . '}'] = $param['name'];
		}
		return strtr($subject, $replace);
	}
}
