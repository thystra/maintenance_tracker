<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Workspace;
use OCA\MaintenanceTracker\Db\WorkspaceMapper;
use OCA\MaintenanceTracker\Db\WorkspaceMember;
use OCA\MaintenanceTracker\Db\WorkspaceMemberMapper;
use OCA\MaintenanceTracker\Exception\AccessDeniedException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

final class WorkspaceService {
	private const ROLE_RANK = [
		'viewer' => 10,
		'editor' => 20,
		'owner' => 30,
	];

	public function __construct(
		private WorkspaceMapper $workspaceMapper,
		private WorkspaceMemberMapper $memberMapper,
		private UuidGenerator $uuidGenerator,
		private ITimeFactory $timeFactory,
		private UserLifecycleService $userLifecycleService,
	) {
	}

	public function getOrCreatePersonal(string $userUid): Workspace {
		return $this->userLifecycleService->runForActiveUser(
			$userUid,
			fn (): Workspace => $this->getOrCreatePersonalUnlocked($userUid),
		);
	}

	/**
	 * Authorize and run the entire operation under the account lifecycle lock.
	 *
	 * @template T
	 * @param callable(WorkspaceContext): T $operation
	 * @return T
	 */
	public function runWithAccess(
		string $userUid,
		?string $workspaceUuid,
		string $minimumRole,
		callable $operation,
	): mixed {
		return $this->userLifecycleService->runForActiveUser(
			$userUid,
			fn (): mixed => $operation($this->requireAccessUnlocked(
				$userUid,
				$workspaceUuid,
				$minimumRole,
			)),
		);
	}

	private function getOrCreatePersonalUnlocked(string $userUid): Workspace {
		try {
			return $this->workspaceMapper->findPersonalByOwner($userUid);
		} catch (DoesNotExistException) {
			// Continue with lazy creation while holding the user-state lock.
		}

		$now = $this->timeFactory->getTime();
		$workspace = new Workspace();
		$workspace->setUuid($this->uuidGenerator->generate());
		$workspace->setOwnerUid($userUid);
		$workspace->setPersonalOwnerUid($userUid);
		$workspace->setName('Personal maintenance');
		$workspace->setKind('personal');
		$workspace->setRevision(1);
		$workspace->setCreatedAt($now);
		$workspace->setUpdatedAt($now);

		/** @var Workspace $inserted */
		$inserted = $this->workspaceMapper->insert($workspace);

		$member = new WorkspaceMember();
		$member->setWorkspaceId($inserted->getId());
		$member->setUserUid($userUid);
		$member->setRole('owner');
		$member->setCreatedAt($now);
		$this->memberMapper->insert($member);

		return $inserted;
	}

	private function requireAccessUnlocked(
		string $userUid,
		?string $workspaceUuid,
		string $minimumRole,
	): WorkspaceContext {
		if (!isset(self::ROLE_RANK[$minimumRole])) {
			throw new \LogicException("Unknown workspace role: {$minimumRole}");
		}

		if ($workspaceUuid === null || $workspaceUuid === '') {
			$workspace = $this->getOrCreatePersonalUnlocked($userUid);

			return new WorkspaceContext($workspace, 'owner');
		}

		$workspaceUuid = strtolower(trim($workspaceUuid));
		if (!UuidGenerator::isValid($workspaceUuid)) {
			throw new AccessDeniedException('The workspace is unavailable');
		}

		try {
			$workspace = $this->workspaceMapper->findByUuid($workspaceUuid);
			$member = $this->memberMapper->findByWorkspaceAndUser(
				$workspace->getId(),
				$userUid,
			);
		} catch (DoesNotExistException) {
			throw new AccessDeniedException('The workspace is unavailable');
		}

		$role = $member->getRole();
		if (!isset(self::ROLE_RANK[$role]) || self::ROLE_RANK[$role] < self::ROLE_RANK[$minimumRole]) {
			throw new AccessDeniedException('The workspace role does not permit this action');
		}

		return new WorkspaceContext($workspace, $role);
	}
}
