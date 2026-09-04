<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\WorkspaceMember;
use OCA\MaintenanceTracker\Db\WorkspaceMemberMapper;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

final class MembershipService {
	public function __construct(
		private WorkspaceMemberMapper $mapper,
		private AuthorizationCatalog $authorization,
		private AuditService $audit,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return list<array{userUid: string, role: string, createdAt: string}>
	 */
	public function list(WorkspaceContext $context): array {
		return array_map(
			fn (WorkspaceMember $member): array => $this->toApi($member),
			$this->mapper->findForWorkspace($context->workspace()->getId()),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{userUid: string, role: string, createdAt: string}
	 */
	public function add(
		WorkspaceContext $context,
		string $actorUid,
		array $input,
	): array {
		$targetUid = $this->targetUid($input['userUid'] ?? null);
		$role = $this->managedRole($input['role'] ?? null);
		if (!$this->userManager->userExists($targetUid)) {
			throw new ValidationException('The target Nextcloud user does not exist');
		}

		try {
			$this->mapper->findByWorkspaceAndUser($context->workspace()->getId(), $targetUid);
			throw new ValidationException('The user is already a workspace member');
		} catch (DoesNotExistException) {
			// Continue.
		}

		$member = new WorkspaceMember();
		$member->setWorkspaceId($context->workspace()->getId());
		$member->setUserUid($targetUid);
		$member->setRole($role);
		$member->setCreatedAt($this->timeFactory->getTime());
		/** @var WorkspaceMember $inserted */
		$inserted = $this->mapper->insert($member);

		$this->audit->record(
			$context->workspace()->getId(),
			'workspace.member.added',
			$actorUid,
			$targetUid,
			null,
			['role' => $role],
		);

		return $this->toApi($inserted);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{userUid: string, role: string, createdAt: string}
	 */
	public function changeRole(
		WorkspaceContext $context,
		string $actorUid,
		string $targetUid,
		array $input,
	): array {
		$targetUid = $this->targetUid($targetUid);
		$role = $this->managedRole($input['role'] ?? null);
		$member = $this->find($context, $targetUid);
		$oldRole = $this->authorization->normalizeRole($member->getRole());
		if ($oldRole === 'owner') {
			throw new ValidationException('Workspace owner membership cannot be changed');
		}
		if ($oldRole === $role) {
			return $this->toApi($member);
		}

		$member->setRole($role);
		/** @var WorkspaceMember $updated */
		$updated = $this->mapper->update($member);
		$this->audit->record(
			$context->workspace()->getId(),
			'workspace.member.role_changed',
			$actorUid,
			$targetUid,
			null,
			['fromRole' => $oldRole, 'toRole' => $role],
		);

		return $this->toApi($updated);
	}

	public function remove(
		WorkspaceContext $context,
		string $actorUid,
		string $targetUid,
	): void {
		$targetUid = $this->targetUid($targetUid);
		$member = $this->find($context, $targetUid);
		$role = $this->authorization->normalizeRole($member->getRole());
		if ($role === 'owner') {
			throw new ValidationException('Workspace owner membership cannot be removed');
		}

		$this->mapper->delete($member);
		$this->audit->record(
			$context->workspace()->getId(),
			'workspace.member.removed',
			$actorUid,
			$targetUid,
			null,
			['role' => $role],
		);
	}

	public function targetUidFromInput(array $input): string {
		return $this->targetUid($input['userUid'] ?? null);
	}

	private function find(WorkspaceContext $context, string $targetUid): WorkspaceMember {
		try {
			return $this->mapper->findByWorkspaceAndUser(
				$context->workspace()->getId(),
				$targetUid,
			);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Workspace member not found');
		}
	}

	private function targetUid(mixed $value): string {
		if (!is_string($value)) {
			throw new ValidationException('userUid must be a string');
		}
		$value = trim($value);
		if ($value === '' || strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
			throw new ValidationException('userUid is invalid');
		}

		return $value;
	}

	private function managedRole(mixed $value): string {
		if (!is_string($value)) {
			throw new ValidationException('role must be a string');
		}
		try {
			$role = $this->authorization->normalizeRole($value);
		} catch (\InvalidArgumentException $exception) {
			throw new ValidationException('Unsupported workspace role', 0, $exception);
		}
		if (!in_array($role, ['manager', 'contributor', 'viewer'], true)) {
			throw new ValidationException('Owner membership cannot be assigned through this endpoint');
		}

		return $role;
	}

	/**
	 * @return array{userUid: string, role: string, createdAt: string}
	 */
	private function toApi(WorkspaceMember $member): array {
		return [
			'userUid' => $member->getUserUid(),
			'role' => $this->authorization->normalizeRole($member->getRole()),
			'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $member->getCreatedAt()),
		];
	}
}
