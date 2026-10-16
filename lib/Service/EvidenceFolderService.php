<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Asset;
use OCA\MaintenanceTracker\Db\Workspace;
use OCA\MaintenanceTracker\Db\WorkspaceMemberMapper;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * App-managed evidence folders.
 *
 * Each workspace has one folder in the workspace OWNER's Files:
 * `Maintenance Tracker/<Workspace>/` with one subfolder per asset. The folder
 * is shared with every other workspace member through an ordinary Nextcloud
 * user share whose permissions follow the member's role, so members browse it
 * in Files like any other share. Folders are tracked by file id, so renames
 * and moves in Files are followed; a deleted folder is recreated on the next
 * upload under a fresh, non-colliding name (an existing folder that the app
 * did not create is never adopted, because adopting it would share unrelated
 * content with members).
 */
final class EvidenceFolderService {
	public const APP_FOLDER = 'Maintenance Tracker';
	public const ROOT_SCOPE = 'workspace';

	public const ROLE_PERMISSIONS = [
		'viewer' => Constants::PERMISSION_READ,
		'contributor' => Constants::PERMISSION_READ | Constants::PERMISSION_CREATE | Constants::PERMISSION_UPDATE,
		'manager' => Constants::PERMISSION_READ | Constants::PERMISSION_CREATE | Constants::PERMISSION_UPDATE | Constants::PERMISSION_DELETE,
	];

	public function __construct(
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
		private EvidenceRepository $repository,
		private WorkspaceMemberMapper $members,
		private AuthorizationCatalog $authorization,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/** Filesystem-safe single path segment derived from a user-visible name. */
	public static function segment(string $name, string $fallback): string {
		$name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $name) ?? '';
		$name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', " .\t");
		if (function_exists('mb_substr')) {
			$name = mb_substr($name, 0, 64);
		} else {
			$name = substr($name, 0, 64);
		}
		$name = trim($name, ' .');
		return $name === '' || in_array(strtolower($name), ['.htaccess', '.ocdata'], true) ? $fallback : $name;
	}

	/** Existing workspace folder in the owner's Files, or null. Never creates. */
	public function findRoot(Workspace $workspace): ?Folder {
		return $this->trackedFolder($workspace, self::ROOT_SCOPE);
	}

	/**
	 * Ensure the workspace folder exists; member shares are reconciled too.
	 * Shares are only ever created while the owner is the acting user: Nextcloud
	 * share events (activity, auto-accept) assume the sharer is the session user.
	 */
	public function ensureRoot(Workspace $workspace, string $actorUid): Folder {
		$folder = $this->trackedFolder($workspace, self::ROOT_SCOPE);
		if ($folder === null) {
			$owner = $this->rootFolder->getUserFolder($workspace->getOwnerUid());
			$app = $this->childFolder($owner, self::APP_FOLDER);
			$folder = $app->newFolder($app->getNonExistingName(self::segment($workspace->getName(), 'Workspace')));
			$this->repository->saveFolder($workspace->getId(), self::ROOT_SCOPE, $workspace->getOwnerUid(), (int)$folder->getId(), $this->timeFactory->getTime());
		}
		$this->syncShares($workspace, $actorUid, $folder);
		return $folder;
	}

	/** Membership hook: set up the folder and shares, never blocking the membership change. */
	public function prepareForMembers(Workspace $workspace, string $actorUid): void {
		$this->attempt(fn () => $this->ensureRoot($workspace, $actorUid), $workspace, 'setup');
	}

	/** Ensure the per-asset subfolder below the workspace folder. */
	public function ensureAssetFolder(Workspace $workspace, Asset $asset, string $actorUid): Folder {
		$root = $this->ensureRoot($workspace, $actorUid);
		$scope = 'asset:' . $asset->getId();
		$folder = $this->trackedFolder($workspace, $scope);
		// Follow renames/moves, but only while the folder stays inside the shared
		// workspace folder; otherwise members could not reach it.
		if ($folder !== null && !$this->isInside($folder, $root)) {
			$folder = null;
		}
		if ($folder === null) {
			$folder = $root->newFolder($root->getNonExistingName(self::segment($asset->getName(), 'Asset')));
			$this->repository->saveFolder($workspace->getId(), $scope, $workspace->getOwnerUid(), (int)$folder->getId(), $this->timeFactory->getTime());
		}
		return $folder;
	}

	/** True when $node is the workspace folder or below it (owner view). */
	public function isInside(Node $node, Folder $root): bool {
		$rootPath = rtrim($root->getPath(), '/') . '/';
		return $node->getId() === $root->getId() || str_starts_with($node->getPath(), $rootPath);
	}

	/**
	 * Describe a managed folder as seen by $uid: path relative to their Files
	 * root (owner: real path; member: the share mount), writability, and the
	 * owner's free space because shared uploads count against the owner quota.
	 *
	 * @return array<string,mixed>
	 */
	public function describeFor(Workspace $workspace, Folder $folder, string $uid): array {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$node = $userFolder->getFirstNodeById((int)$folder->getId());
		$free = $folder->getFreeSpace();
		$base = [
			'ownerUid' => $workspace->getOwnerUid(),
			'freeBytes' => is_int($free) || is_float($free) ? (int)$free : null,
			'quotaLimited' => is_numeric($free) && $free >= 0,
		];
		if (!$node instanceof Folder) {
			return $base + ['available' => false, 'path' => null, 'writable' => false, 'reason' => 'The workspace evidence folder has not been shared with you yet; it is shared when the workspace owner changes membership or uploads evidence (sharing may also be disabled or awaiting acceptance)'];
		}
		return $base + ['available' => true, 'path' => $userFolder->getRelativePath($node->getPath()), 'writable' => $node->isCreatable(), 'reason' => null];
	}

	/**
	 * Reconcile member shares of the workspace folder with current membership:
	 * create missing shares, update permissions after role changes, and revoke
	 * shares of removed members. Sharing failures (sharing disabled, group
	 * restrictions) are logged without file names and leave evidence usable by
	 * the owner; they never block the membership change itself.
	 */
	public function syncShares(Workspace $workspace, string $actorUid, ?Folder $root = null): void {
		$root ??= $this->findRoot($workspace);
		if ($root === null) {
			return;
		}
		$owner = $workspace->getOwnerUid();
		$desired = [];
		foreach ($this->members->findForWorkspace($workspace->getId()) as $member) {
			$role = $this->authorization->normalizeRole($member->getRole());
			if ($member->getUserUid() !== $owner && isset(self::ROLE_PERMISSIONS[$role])) {
				$desired[$member->getUserUid()] = self::ROLE_PERMISSIONS[$role];
			}
		}
		$now = $this->timeFactory->getTime();
		$existing = [];
		foreach ($this->repository->shares($workspace->getId()) as $row) {
			$existing[(string)$row['member_uid']] = $row;
		}
		foreach ($existing as $uid => $row) {
			$share = $this->share((string)$row['share_id']);
			if (!isset($desired[$uid])) {
				if ($share !== null) {
					$this->attempt(fn () => $this->shareManager->deleteShare($share), $workspace, 'revoke');
				}
				$this->repository->deleteShare($workspace->getId(), $uid);
				continue;
			}
			if ($share === null || (int)$share->getNodeId() !== (int)$root->getId()) {
				$this->repository->deleteShare($workspace->getId(), $uid);
				unset($existing[$uid]);
				continue;
			}
			if ($share->getPermissions() !== $desired[$uid]) {
				$share->setPermissions($desired[$uid]);
				if ($this->attempt(fn () => $this->shareManager->updateShare($share), $workspace, 'update')) {
					$this->repository->saveShare($workspace->getId(), $uid, (string)$row['share_id'], $desired[$uid], $now);
				}
			}
		}
		if ($actorUid !== $owner) {
			return;
		}
		foreach ($desired as $uid => $permissions) {
			if (isset($existing[$uid])) {
				continue;
			}
			$share = $this->shareManager->newShare();
			$share->setNode($root)
				->setShareType(IShare::TYPE_USER)
				->setSharedWith($uid)
				->setSharedBy($owner)
				->setShareOwner($owner)
				->setPermissions($permissions);
			$created = null;
			$this->attempt(function () use ($share, &$created): void {
				$created = $this->shareManager->createShare($share);
			}, $workspace, 'create');
			if ($created instanceof IShare) {
				$this->repository->saveShare($workspace->getId(), $uid, $created->getFullId(), $permissions, $now);
			}
		}
	}

	private function share(string $fullId): ?IShare {
		try {
			return $this->shareManager->getShareById($fullId);
		} catch (Throwable) {
			return null;
		}
	}

	private function attempt(callable $operation, Workspace $workspace, string $action): bool {
		try {
			$operation();
			return true;
		} catch (Throwable $exception) {
			$this->logger->warning('Maintenance Tracker evidence folder share ' . $action . ' failed', [
				'app' => 'maintenance_tracker',
				'workspace' => $workspace->getUuid(),
				'exception' => $exception::class,
			]);
			return false;
		}
	}

	private function trackedFolder(Workspace $workspace, string $scope): ?Folder {
		$row = $this->repository->findFolder($workspace->getId(), $scope);
		if ($row === null || $row['owner_uid'] !== $workspace->getOwnerUid()) {
			return null;
		}
		try {
			$node = $this->rootFolder->getUserFolder($workspace->getOwnerUid())->getFirstNodeById((int)$row['folder_id']);
		} catch (Throwable) {
			return null;
		}
		return $node instanceof Folder ? $node : null;
	}

	private function childFolder(Folder $parent, string $name): Folder {
		if ($parent->nodeExists($name)) {
			$node = $parent->get($name);
			if ($node instanceof Folder) {
				return $node;
			}
			$name = $parent->getNonExistingName($name);
		}
		$folder = $parent->newFolder($name);
		if (!$folder instanceof Folder) {
			throw new ValidationException('Could not create the Maintenance Tracker folder');
		}
		return $folder;
	}
}
