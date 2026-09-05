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
	public function __construct(
		private WorkspaceMapper $workspaceMapper,
		private WorkspaceMemberMapper $memberMapper,
		private UuidGenerator $uuidGenerator,
		private ITimeFactory $timeFactory,
		private UserLifecycleService $userLifecycleService,
		private AuthorizationCatalog $authorization,
	) {
	}

	public function getOrCreatePersonal(string $userUid): Workspace {
		return $this->userLifecycleService->runForActiveUser(
			$userUid,
			fn (): Workspace => $this->getOrCreatePersonalUnlocked($userUid),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listAccessible(string $userUid): array {
		return $this->userLifecycleService->runForActiveUser(
			$userUid,
			function () use ($userUid): array {
				$this->getOrCreatePersonalUnlocked($userUid);
				$items = [];
				foreach ($this->memberMapper->findForUser($userUid) as $member) {
					try {
						$workspace = $this->workspaceMapper->findById($member->getWorkspaceId());
					} catch (DoesNotExistException) {
						continue;
					}
					$items[] = $workspace->toApi(
						$this->authorization->normalizeRole($member->getRole()),
					);
				}

				usort(
					$items,
					static fn (array $left, array $right): int =>
						[$left['kind'] !== 'personal', $left['name'], $left['uuid']]
						<=> [$right['kind'] !== 'personal', $right['name'], $right['uuid']],
				);

				return $items;
			},
		);
	}

	/**
	 * @template T
	 * @param callable(WorkspaceContext): T $operation
	 * @return T
	 */
	public function runWithCapability(
		string $userUid,
		?string $workspaceUuid,
		string $capability,
		callable $operation,
	): mixed {
		return $this->userLifecycleService->runForActiveUser(
			$userUid,
			fn (): mixed => $this->runAuthorizedUnlocked(
				$userUid,
				$workspaceUuid,
				$capability,
				$operation,
			),
		);
	}

	/**
	 * Run a workspace operation while holding lifecycle locks for the actor and
	 * all explicitly affected users. Membership mutations use this boundary so
	 * account deletion/UID reuse cannot race a grant, role change, or removal.
	 *
	 * @template T
	 * @param list<string> $affectedUserUids
	 * @param callable(WorkspaceContext): T $operation
	 * @return T
	 */
	public function runWithCapabilityForUsers(
		string $userUid,
		array $affectedUserUids,
		?string $workspaceUuid,
		string $capability,
		callable $operation,
	): mixed {
		return $this->userLifecycleService->runForActiveUsers(
			[$userUid, ...$affectedUserUids],
			fn (): mixed => $this->runAuthorizedUnlocked(
				$userUid,
				$workspaceUuid,
				$capability,
				$operation,
			),
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

	private function runAuthorizedUnlocked(
		string $userUid,
		?string $workspaceUuid,
		string $capability,
		callable $operation,
	): mixed {
		$context = $this->requireCapabilityUnlocked($userUid, $workspaceUuid, $capability);
		if ($this->authorization->isWrite($capability)) {
			$this->workspaceMapper->serializeWrite(
				$context->workspace()->getId(),
				$this->uuidGenerator->generate(),
			);
		}

		return $operation($context);
	}

	private function requireCapabilityUnlocked(
		string $userUid,
		?string $workspaceUuid,
		string $capability,
	): WorkspaceContext {
		// Validate capability identity even before a personal workspace shortcut.
		$this->authorization->isWrite($capability);

		if ($workspaceUuid === null || $workspaceUuid === '') {
			$workspace = $this->getOrCreatePersonalUnlocked($userUid);
			$role = 'owner';
		} else {
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
			$role = $this->authorization->normalizeRole($member->getRole());
		}

		if (!$this->authorization->allows($role, $capability)) {
			throw new AccessDeniedException('The workspace capability does not permit this action');
		}

		return new WorkspaceContext($workspace, $role);
	}
}
