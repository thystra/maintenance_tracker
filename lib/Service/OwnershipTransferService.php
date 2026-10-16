<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Db\Workspace;
use OCA\MaintenanceTracker\Db\WorkspaceMapper;
use OCA\MaintenanceTracker\Db\WorkspaceMember;
use OCA\MaintenanceTracker\Db\WorkspaceMemberMapper;
use OCA\MaintenanceTracker\Exception\AccessDeniedException;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Workspace ownership transfer.
 *
 * An owner offers ownership to an existing member, who accepts or declines
 * before the offer expires. Acceptance runs a persisted, resumable state
 * machine (see OwnershipTransferPolicy::STEPS):
 *
 * 1. snapshot: record each evidence folder and in-folder evidence file with its
 *    path relative to the workspace folder;
 * 2. unshare: revoke the old owner's member shares;
 * 3. move: move the workspace folder into the new owner's
 *    `Maintenance Tracker/` folder with one Nextcloud move (file ids survive
 *    where the storage backend allows; the target path is persisted first);
 * 4. repoint: re-point folder and evidence references to the moved nodes, by
 *    surviving file id or else by the snapshotted relative path;
 * 5. swap: in one database transaction, make the recipient Owner and the
 *    previous owner Manager (or remove them if they chose to leave);
 * 6. share: re-create every member share from the new owner. Share changes
 *    run with the folder owner as the request's volatile active user, because
 *    Nextcloud share events resolve paths relative to the session user; this
 *    also lets account deletion and occ finish the transfer immediately.
 *
 * Roles never change before the files have moved, and every step is
 * idempotent, so an interruption leaves a transfer that resumes instead of a
 * half-moved workspace. Nothing here logs titles, names, paths, or contents.
 */
final class OwnershipTransferService {
	public const DELETION_POLICY_KEY = 'owner_deletion_policy';
	private const SYSTEM_ACTOR = 'system';

	public function __construct(
		private TransferRepository $transfers,
		private EvidenceRepository $evidence,
		private EvidenceFolderService $folders,
		private WorkspaceMapper $workspaces,
		private WorkspaceMemberMapper $members,
		private AuditService $audit,
		private AuthorizationCatalog $authorization,
		private UserLifecycleService $lifecycle,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private UuidGenerator $uuidGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Offer ownership to an existing member. Runs inside the caller's
	 * capability transaction (workspace.ownership.transfer, Owner only).
	 *
	 * Returns the transfer UUID; render it with show() after the transaction,
	 * because the quota preview reads Files.
	 *
	 * @param array<string,mixed> $input
	 */
	public function offer(WorkspaceContext $context, string $actorUid, array $input): string {
		CatalogInput::known($input, ['toUid', 'leaveAfter', 'expiresInDays'], 'transfer');
		$workspace = $context->workspace();
		if ($workspace->isOrphaned() || $workspace->getOwnerUid() !== $actorUid) {
			throw new AccessDeniedException('Only the workspace owner can transfer ownership');
		}
		$toUid = self::uid($input['toUid'] ?? null);
		if ($toUid === $actorUid) {
			throw new ValidationException('You already own this workspace');
		}
		$member = $this->member($workspace->getId(), $toUid);
		if ($member === null || !$this->userManager->userExists($toUid)) {
			throw new ValidationException('Ownership can only be transferred to an existing workspace member');
		}
		$leaveAfter = $input['leaveAfter'] ?? false;
		if (!is_bool($leaveAfter)) {
			throw new ValidationException('leaveAfter must be a boolean');
		}
		$days = OwnershipTransferPolicy::expiryDays($input['expiresInDays'] ?? null);
		$now = $this->timeFactory->getTime();
		$open = $this->openFor($workspace->getId(), $now);
		if ($open !== null) {
			throw new RevisionConflictException('An ownership transfer is already pending for this workspace; cancel it first');
		}
		$uuid = $this->uuidGenerator->generate();
		$expiresAt = $now + $days * 86400;
		$this->transfers->insert([
			'uuid' => $uuid,
			'workspace_id' => $workspace->getId(),
			'from_uid' => $actorUid,
			'to_uid' => $toUid,
			'reason' => OwnershipTransferPolicy::REASON_OWNER,
			'status' => OwnershipTransferPolicy::STATUS_PENDING,
			'step' => 'offered',
			'leave_after' => $leaveAfter ? 1 : 0,
			'expires_at' => $expiresAt,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$this->audit->record($workspace->getId(), 'workspace.ownership.offered', $actorUid, $uuid, null, [
			'toUid' => $toUid,
			'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
			'leaveAfter' => $leaveAfter,
		]);
		return $uuid;
	}

	/**
	 * Open transfers involving the user (incoming offers drive the accept
	 * banner; interrupted ones offer resume). Read-only apart from expiring
	 * stale offers.
	 *
	 * @return array{incoming: list<array<string,mixed>>, outgoing: list<array<string,mixed>>}
	 */
	public function listForUser(string $uid): array {
		$now = $this->timeFactory->getTime();
		$incoming = [];
		$outgoing = [];
		foreach ($this->transfers->findOpenForUser($uid) as $row) {
			if (OwnershipTransferPolicy::isExpired($row, $now)) {
				$this->expire($row, $now);
				continue;
			}
			if ($row['to_uid'] === $uid) {
				$incoming[] = $this->toApi($row, $uid);
			} else {
				$outgoing[] = $this->toApi($row, $uid);
			}
		}
		return ['incoming' => $incoming, 'outgoing' => $outgoing];
	}

	/** @return array<string,mixed> */
	public function show(string $uid, string $uuid): array {
		$row = $this->participantRow($uid, $uuid);
		return $this->toApi($row, $uid);
	}

	/** Owner withdraws a pending offer. @return array<string,mixed> */
	public function cancel(string $uid, string $uuid): array {
		return $this->closePending($uid, $uuid, 'from_uid', OwnershipTransferPolicy::STATUS_CANCELLED, 'workspace.ownership.cancelled');
	}

	/** Recipient declines a pending offer. @return array<string,mixed> */
	public function decline(string $uid, string $uuid): array {
		return $this->closePending($uid, $uuid, 'to_uid', OwnershipTransferPolicy::STATUS_DECLINED, 'workspace.ownership.declined');
	}

	/**
	 * Recipient accepts. The quota implication must be acknowledged first: the
	 * workspace folder (including member uploads) will count against the
	 * recipient's quota, and an offer that cannot fit is refused up front.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function accept(string $uid, string $uuid, array $input): array {
		CatalogInput::known($input, ['acknowledgeQuota'], 'acceptance');
		$row = $this->participantRow($uid, $uuid);
		if ($row['to_uid'] !== $uid) {
			throw new AccessDeniedException('Only the recipient can accept this transfer');
		}
		if ($row['status'] === OwnershipTransferPolicy::STATUS_IN_PROGRESS) {
			$this->run($uuid, $uid);
			return $this->toApi($this->require($uuid), $uid);
		}
		// Quota is read from Files before, never inside, the transaction.
		$quota = $this->quota($this->workspace((int)$row['workspace_id']), $uid);
		$this->lifecycle->runForActiveUsers([(string)$row['from_uid'], $uid], function () use ($uid, $uuid, $input, $quota): void {
			$now = $this->timeFactory->getTime();
			$row = $this->require($uuid);
			if ($row['status'] !== OwnershipTransferPolicy::STATUS_PENDING) {
				throw new RevisionConflictException('This transfer is no longer pending');
			}
			if (OwnershipTransferPolicy::isExpired($row, $now)) {
				$this->expire($row, $now);
				throw new RevisionConflictException('This transfer offer has expired');
			}
			$workspace = $this->workspace((int)$row['workspace_id']);
			if ($workspace->isOrphaned() || $workspace->getOwnerUid() !== $row['from_uid'] || $this->member($workspace->getId(), $uid) === null) {
				throw new RevisionConflictException('Workspace ownership or membership changed since the offer; ask the owner for a new one');
			}
			if (($input['acknowledgeQuota'] ?? false) !== true) {
				throw new ValidationException('Accepting moves ' . $quota['folderBytes'] . ' bytes of evidence into your Files and counts against your quota; acknowledge this with acknowledgeQuota');
			}
			if (!$quota['fits']) {
				throw new ValidationException('The workspace evidence folder does not fit in your remaining storage quota');
			}
			$this->workspaces->serializeWrite($workspace->getId(), $this->uuidGenerator->generate());
			if (!$this->transfers->advance((int)$row['id'], OwnershipTransferPolicy::STATUS_PENDING, 'offered', [
				'status' => OwnershipTransferPolicy::STATUS_IN_PROGRESS,
				'step' => 'snapshot',
				'updated_at' => $now,
			])) {
				throw new RevisionConflictException('This transfer changed concurrently; reload and try again');
			}
			$this->audit->record($workspace->getId(), 'workspace.ownership.accepted', $uid, $uuid, null, ['reason' => (string)$row['reason']]);
		});
		$this->run($uuid, $uid);
		return $this->toApi($this->require($uuid), $uid);
	}

	/** Resume an interrupted transfer (either participant). @return array<string,mixed> */
	public function resume(string $uid, string $uuid): array {
		$row = $this->participantRow($uid, $uuid);
		if ($row['status'] !== OwnershipTransferPolicy::STATUS_IN_PROGRESS) {
			throw new RevisionConflictException('Only an in-progress transfer can be resumed');
		}
		$this->run($uuid, $uid);
		return $this->toApi($this->require($uuid), $uid);
	}

	/**
	 * Abort an in-progress transfer before anything moved. Shares revoked in
	 * the unshare step are re-created on the owner's next folder action.
	 *
	 * @return array<string,mixed>
	 */
	public function abort(string $uid, string $uuid): array {
		$row = $this->participantRow($uid, $uuid);
		if ($row['status'] !== OwnershipTransferPolicy::STATUS_IN_PROGRESS || !in_array($row['step'], OwnershipTransferPolicy::ABORTABLE_STEPS, true)) {
			throw new RevisionConflictException('This transfer can no longer be aborted; resume it instead');
		}
		if ($row['step'] === 'move' && !$this->rootStillWithSource($row)) {
			throw new RevisionConflictException('The evidence folder has already moved; resume the transfer instead');
		}
		$now = $this->timeFactory->getTime();
		if (!$this->transfers->advance((int)$row['id'], OwnershipTransferPolicy::STATUS_IN_PROGRESS, (string)$row['step'], [
			'status' => OwnershipTransferPolicy::STATUS_ABORTED,
			'finished_at' => $now,
			'updated_at' => $now,
		])) {
			throw new RevisionConflictException('This transfer changed concurrently; reload and try again');
		}
		$this->transfers->deleteItems((int)$row['id']);
		$this->audit->record((int)$row['workspace_id'], 'workspace.ownership.aborted', $uid, $uuid, null, ['step' => (string)$row['step']]);
		$workspace = $this->workspace((int)$row['workspace_id']);
		$this->folders->syncShares($workspace, $uid);
		return $this->toApi($this->require($uuid), $uid);
	}

	/**
	 * BeforeUserDeletedEvent: for every workspace the departing user owns that
	 * still has other members, transfer it to the longest-standing Manager
	 * while the owner's Files still exist. Without a Manager (or with the
	 * `orphan` policy) the workspace is left for the account purge to orphan.
	 * Failures are logged and never block the account deletion.
	 */
	public function handleOwnerDeletion(string $uid): void {
		$policy = OwnershipTransferPolicy::deletionPolicy($this->appConfig->getValueString(Application::APP_ID, self::DELETION_POLICY_KEY, OwnershipTransferPolicy::DELETION_TRANSFER));
		if ($policy !== OwnershipTransferPolicy::DELETION_TRANSFER) {
			return;
		}
		try {
			$workspaceIds = $this->ownedWorkspaceIds($uid);
		} catch (Throwable $exception) {
			$this->logger->warning('Maintenance Tracker could not inspect workspaces before account deletion', ['app' => Application::APP_ID, 'exception' => $exception::class]);
			return;
		}
		foreach ($workspaceIds as $workspaceId) {
			try {
				$successor = OwnershipTransferPolicy::successor($this->memberFacts($workspaceId), $uid);
				if ($successor === null || !$this->userManager->userExists($successor)) {
					continue;
				}
				$uuid = $this->startSystemTransfer($workspaceId, $uid, $successor, OwnershipTransferPolicy::REASON_ACCOUNT_DELETION, true);
				$this->run($uuid, null);
			} catch (Throwable $exception) {
				$this->logger->warning('Maintenance Tracker ownership transfer on account deletion did not finish', [
					'app' => Application::APP_ID,
					'exception' => $exception::class,
				]);
			}
		}
	}

	/**
	 * Administrator assignment (occ). An orphaned workspace simply gets the new
	 * owner; a live workspace runs a full transfer, resuming an interrupted one
	 * to the same user.
	 *
	 * @return array<string,mixed>
	 */
	public function assignOwner(string $workspaceUuid, string $toUid, bool $removePrevious): array {
		$toUid = self::uid($toUid);
		if (!$this->userManager->userExists($toUid)) {
			throw new ValidationException('The target Nextcloud user does not exist');
		}
		try {
			$workspace = $this->workspaces->findByUuid(strtolower(trim($workspaceUuid)));
		} catch (DoesNotExistException) {
			throw new NotFoundException('Workspace not found');
		}
		$now = $this->timeFactory->getTime();
		$open = $this->openFor($workspace->getId(), $now);
		if ($open !== null && $open['status'] === OwnershipTransferPolicy::STATUS_IN_PROGRESS) {
			if ($open['to_uid'] !== $toUid) {
				throw new RevisionConflictException('Another ownership transfer is in progress; resume it first');
			}
			$this->run((string)$open['uuid'], null);
			return $this->toApi($this->require((string)$open['uuid']), null);
		}
		if (!$workspace->isOrphaned() && $workspace->getOwnerUid() === $toUid) {
			throw new ValidationException('The user already owns this workspace');
		}
		if ($open !== null) {
			$this->transfers->advance((int)$open['id'], OwnershipTransferPolicy::STATUS_PENDING, 'offered', [
				'status' => OwnershipTransferPolicy::STATUS_CANCELLED, 'finished_at' => $now, 'updated_at' => $now,
			]);
			$this->audit->record($workspace->getId(), 'workspace.ownership.cancelled', self::SYSTEM_ACTOR, (string)$open['uuid']);
		}
		$uuid = $this->startSystemTransfer($workspace->getId(), $workspace->getOwnerUid(), $toUid, OwnershipTransferPolicy::REASON_ADMIN, $removePrevious, $workspace->isOrphaned());
		$this->audit->record($workspace->getId(), 'workspace.owner.assigned', self::SYSTEM_ACTOR, $workspace->getUuid(), null, [
			'toUid' => $toUid,
			'fromUid' => $workspace->getOwnerUid(),
		]);
		$this->run($uuid, null);
		return $this->toApi($this->require($uuid), null);
	}

	/** @return list<array<string,mixed>> */
	public function orphanedWorkspaces(): array {
		$q = $this->db->getQueryBuilder();
		$q->select('uuid', 'name', 'owner_uid', 'orphaned_at')->from('maint_spaces')
			->where($q->expr()->isNotNull('orphaned_at'))
			->andWhere($q->expr()->isNull('deleted_at'))
			->orderBy('orphaned_at');
		$r = $q->executeQuery();
		try {
			return array_map(static fn (array $row): array => [
				'uuid' => (string)$row['uuid'],
				'name' => (string)$row['name'],
				'formerOwnerUid' => (string)$row['owner_uid'],
				'orphanedAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['orphaned_at']),
			], $r->fetchAllAssociative());
		} finally {
			$r->closeCursor();
		}
	}

	/**
	 * Drive a transfer forward from its persisted step. $actorUid is the
	 * session user (null for system/CLI), used for audit attribution.
	 */
	public function run(string $uuid, ?string $actorUid): void {
		for ($guard = 0; $guard < count(OwnershipTransferPolicy::STEPS) + 2; ++$guard) {
			$row = $this->require($uuid);
			if ($row['status'] !== OwnershipTransferPolicy::STATUS_IN_PROGRESS) {
				return;
			}
			$step = (string)$row['step'];
			if ($step === 'share') {
				$now = $this->timeFactory->getTime();
				if ($this->transfers->advance((int)$row['id'], OwnershipTransferPolicy::STATUS_IN_PROGRESS, 'share', [
					'status' => OwnershipTransferPolicy::STATUS_COMPLETED, 'step' => 'done', 'error' => null, 'finished_at' => $now, 'updated_at' => $now,
				])) {
					$this->share($this->workspace((int)$row['workspace_id']), (string)$row['to_uid']);
				}
				return;
			}
			try {
				$this->runStep($row, $step, $actorUid);
			} catch (Throwable $exception) {
				$this->transfers->recordError((int)$row['id'], $step . ':' . (new \ReflectionClass($exception))->getShortName(), $this->timeFactory->getTime());
				$this->logger->warning('Maintenance Tracker ownership transfer stopped', [
					'app' => Application::APP_ID,
					'transfer' => $uuid,
					'step' => $step,
					'exception' => $exception::class,
				]);
				throw new RevisionConflictException('The ownership transfer stopped at the ' . $step . ' step; it is safe to resume', 0, $exception);
			}
			$next = OwnershipTransferPolicy::nextStep($step);
			$now = $this->timeFactory->getTime();
			$changes = ['step' => $next, 'error' => null, 'updated_at' => $now];
			if ($next === 'done') {
				$changes['status'] = OwnershipTransferPolicy::STATUS_COMPLETED;
				$changes['finished_at'] = $now;
			}
			$this->transfers->advance((int)$row['id'], OwnershipTransferPolicy::STATUS_IN_PROGRESS, $step, $changes);
		}
	}

	/** @param array<string,mixed> $row */
	private function runStep(array $row, string $step, ?string $actorUid): void {
		$workspace = $this->workspaceIncludingOrphaned((int)$row['workspace_id']);
		match ($step) {
			'snapshot' => $this->snapshot($row, $workspace),
			'unshare' => $this->folders->revokeAllShares($workspace, (string)$row['from_uid']),
			'move' => $this->move($row),
			'repoint' => $this->repoint($row, $workspace),
			'swap' => $this->swap($row, $workspace, $actorUid),
			default => throw new \LogicException('Unknown transfer step'),
		};
	}

	/**
	 * Files are resolved first, outside any transaction (see
	 * UserLifecycleService::afterCommit); only the item rows are written in one.
	 *
	 * @param array<string,mixed> $row
	 */
	private function snapshot(array $row, Workspace $workspace): void {
		$id = (int)$row['id'];
		$items = [];
		$root = $workspace->isOrphaned() ? null : $this->folders->findRoot($workspace);
		if ($root !== null) {
			foreach ($this->evidence->folders($workspace->getId()) as $folderRow) {
				$node = $this->nodeIn((string)$row['from_uid'], (int)$folderRow['folder_id']);
				if ($node instanceof Folder && $this->folders->isInside($node, $root)) {
					$items[] = ['folder', (int)$folderRow['id'], (int)$node->getId(), self::relative($root, $node)];
				}
			}
			foreach ($this->evidence->activeEvidence($workspace->getId()) as $evidenceRow) {
				$node = $this->nodeIn((string)$row['from_uid'], (int)$evidenceRow['file_id']);
				if ($node !== null && $this->folders->isInside($node, $root)) {
					$items[] = ['evidence', (int)$evidenceRow['id'], (int)$node->getId(), self::relative($root, $node)];
				}
			}
		}
		$this->db->beginTransaction();
		try {
			$this->transfers->deleteItems($id);
			foreach ($items as [$type, $refId, $fileId, $relPath]) {
				$this->transfers->addItem($id, $type, $refId, $fileId, $relPath);
			}
			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}

	/** @param array<string,mixed> $row */
	private function move(array $row): void {
		$root = $this->rootItem($row);
		if ($root === null) {
			return;
		}
		$toFolder = $this->rootFolder->getUserFolder((string)$row['to_uid']);
		if ($row['target_path'] !== null && $toFolder->nodeExists((string)$row['target_path']) && !$this->rootStillWithSource($row)) {
			return;
		}
		$source = $this->nodeIn((string)$row['from_uid'], (int)$root['old_file_id']);
		if (!$source instanceof Folder) {
			$moved = $toFolder->getFirstNodeById((int)$root['old_file_id']);
			if ($moved instanceof Folder) {
				$this->setTarget($row, $toFolder->getRelativePath($moved->getPath()));
			}
			// Otherwise the folder was deleted before the transfer: nothing to move.
			return;
		}
		$app = $this->folders->appFolderFor((string)$row['to_uid']);
		$name = $app->getNonExistingName($source->getName());
		$target = rtrim($app->getPath(), '/') . '/' . $name;
		$this->setTarget($row, $toFolder->getRelativePath($target));
		$source->move($target);
	}

	/** @param array<string,mixed> $row */
	private function repoint(array $row, Workspace $workspace): void {
		$toUid = (string)$row['to_uid'];
		$toFolder = $this->rootFolder->getUserFolder($toUid);
		$current = $this->require((string)$row['uuid']);
		$newRoot = null;
		if ($current['target_path'] !== null && $toFolder->nodeExists((string)$current['target_path'])) {
			$candidate = $toFolder->get((string)$current['target_path']);
			$newRoot = $candidate instanceof Folder ? $candidate : null;
		}
		$now = $this->timeFactory->getTime();
		$updates = [];
		if ($newRoot !== null) {
			foreach ($this->transfers->items((int)$row['id']) as $item) {
				if ($item['new_file_id'] !== null) {
					continue;
				}
				$node = $toFolder->getFirstNodeById((int)$item['old_file_id']);
				if ($node === null || !$this->folders->isInside($node, $newRoot)) {
					$node = $item['rel_path'] === '' ? $newRoot : ($newRoot->nodeExists((string)$item['rel_path']) ? $newRoot->get((string)$item['rel_path']) : null);
				}
				if ($node !== null) {
					$updates[] = [$item, (int)$node->getId(), $toFolder->getRelativePath($node->getPath()) ?? ''];
				}
			}
		}
		$this->db->beginTransaction();
		try {
			if ($newRoot === null) {
				// No folder moved (none existed, or it was deleted): drop folder
				// tracking so the new owner gets a fresh folder on first upload.
				$this->evidence->deleteAllFolders($workspace->getId());
			}
			foreach ($updates as [$item, $fileId, $path]) {
				if ($item['item_type'] === 'folder') {
					$this->evidence->repointFolder((int)$item['ref_id'], $toUid, $fileId);
				} else {
					$this->evidence->repointEvidence((int)$item['ref_id'], $fileId, $path, $now);
				}
				$this->transfers->setItemFile((int)$item['id'], $fileId);
			}
			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}

	/** @param array<string,mixed> $row */
	private function swap(array $row, Workspace $workspace, ?string $actorUid): void {
		$fromUid = (string)$row['from_uid'];
		$toUid = (string)$row['to_uid'];
		$now = $this->timeFactory->getTime();
		$this->db->beginTransaction();
		try {
			$this->workspaces->serializeWrite($workspace->getId(), $this->uuidGenerator->generate());
			$workspace = $this->workspaceIncludingOrphaned($workspace->getId());
			if ($workspace->getOwnerUid() !== $toUid || $workspace->isOrphaned()) {
				$workspace->setOwnerUid($toUid);
				$workspace->setPersonalOwnerUid(null);
				$workspace->setKind('shared');
				$workspace->setOrphanedAt(null);
				$workspace->setRevision($workspace->getRevision() + 1);
				$workspace->setUpdatedAt($now);
				$this->workspaces->update($workspace);
			}
			$previous = 'gone';
			$from = $this->member($workspace->getId(), $fromUid);
			if ($from !== null && $fromUid !== $toUid) {
				if ((int)$row['leave_after'] === 1 || !$this->userManager->userExists($fromUid)) {
					$this->members->delete($from);
					$previous = 'left';
				} else {
					$from->setRole('manager');
					$this->members->update($from);
					$previous = 'manager';
				}
			}
			$to = $this->member($workspace->getId(), $toUid);
			if ($to === null) {
				$to = new WorkspaceMember();
				$to->setWorkspaceId($workspace->getId());
				$to->setUserUid($toUid);
				$to->setRole('owner');
				$to->setCreatedAt($now);
				$this->members->insert($to);
			} elseif ($to->getRole() !== 'owner') {
				$to->setRole('owner');
				$this->members->update($to);
			}
			$this->audit->record($workspace->getId(), 'workspace.ownership.transferred', $actorUid ?? self::SYSTEM_ACTOR, (string)$row['uuid'], null, [
				'fromUid' => $fromUid,
				'toUid' => $toUid,
				'reason' => (string)$row['reason'],
				'previousOwner' => $previous,
			]);
			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}

	/**
	 * Re-create member shares from the new owner (session user). Called after
	 * the transfer is marked complete, because share reconciliation is paused
	 * while a transfer is in progress. Sharing failures never block; the next
	 * membership change or owner upload reconciles again.
	 */
	private function share(Workspace $workspace, string $toUid): void {
		foreach ($this->members->findForWorkspace($workspace->getId()) as $member) {
			if ($member->getUserUid() !== $toUid) {
				$this->folders->asUser($toUid, fn () => $this->folders->prepareForMembers($this->workspace($workspace->getId()), $toUid));
				return;
			}
		}
	}

	private function startSystemTransfer(int $workspaceId, string $fromUid, string $toUid, string $reason, bool $leaveAfter, bool $orphaned = false): string {
		$now = $this->timeFactory->getTime();
		$uuid = $this->uuidGenerator->generate();
		$this->transfers->insert([
			'uuid' => $uuid,
			'workspace_id' => $workspaceId,
			'from_uid' => $fromUid,
			'to_uid' => $toUid,
			'reason' => $reason,
			'status' => OwnershipTransferPolicy::STATUS_IN_PROGRESS,
			// An orphaned workspace has no Files left to move.
			'step' => $orphaned ? 'swap' : 'snapshot',
			'leave_after' => $leaveAfter ? 1 : 0,
			'expires_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$this->audit->record($workspaceId, 'workspace.ownership.accepted', self::SYSTEM_ACTOR, $uuid, null, ['reason' => $reason]);
		return $uuid;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function closePending(string $uid, string $uuid, string $column, string $status, string $event): array {
		$row = $this->participantRow($uid, $uuid);
		if ($row[$column] !== $uid) {
			throw new AccessDeniedException('You cannot change this transfer');
		}
		$now = $this->timeFactory->getTime();
		if ($row['status'] !== OwnershipTransferPolicy::STATUS_PENDING || !$this->transfers->advance((int)$row['id'], OwnershipTransferPolicy::STATUS_PENDING, 'offered', [
			'status' => $status, 'finished_at' => $now, 'updated_at' => $now,
		])) {
			throw new RevisionConflictException('This transfer is no longer pending');
		}
		$this->audit->record((int)$row['workspace_id'], $event, $uid, $uuid);
		return $this->toApi($this->require($uuid), $uid);
	}

	/** @param array<string,mixed> $row */
	private function expire(array $row, int $now): void {
		if ($this->transfers->advance((int)$row['id'], OwnershipTransferPolicy::STATUS_PENDING, 'offered', [
			'status' => OwnershipTransferPolicy::STATUS_EXPIRED, 'finished_at' => $now, 'updated_at' => $now,
		])) {
			$this->audit->record((int)$row['workspace_id'], 'workspace.ownership.expired', self::SYSTEM_ACTOR, (string)$row['uuid']);
		}
	}

	/** @return array<string,mixed>|null */
	private function openFor(int $workspaceId, int $now): ?array {
		$open = $this->transfers->findOpenForWorkspace($workspaceId);
		if ($open !== null && OwnershipTransferPolicy::isExpired($open, $now)) {
			$this->expire($open, $now);
			return null;
		}
		return $open;
	}

	/** @return array<string,mixed> */
	private function participantRow(string $uid, string $uuid): array {
		$uuid = strtolower(trim($uuid));
		$row = UuidGenerator::isValid($uuid) ? $this->transfers->findByUuid($uuid) : null;
		if ($row === null || ($row['from_uid'] !== $uid && $row['to_uid'] !== $uid)) {
			throw new NotFoundException('Ownership transfer not found');
		}
		return $row;
	}

	/** @return array<string,mixed> */
	private function require(string $uuid): array {
		$row = $this->transfers->findByUuid($uuid);
		if ($row === null) {
			throw new NotFoundException('Ownership transfer not found');
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function toApi(array $row, ?string $viewerUid): array {
		$out = [
			'uuid' => (string)$row['uuid'],
			'fromUid' => (string)$row['from_uid'],
			'toUid' => (string)$row['to_uid'],
			'reason' => (string)$row['reason'],
			'status' => (string)$row['status'],
			'step' => (string)$row['step'],
			'leaveAfter' => (int)$row['leave_after'] === 1,
			'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['expires_at']),
			'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['created_at']),
			'finishedAt' => $row['finished_at'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int)$row['finished_at']),
			'resumable' => $row['status'] === OwnershipTransferPolicy::STATUS_IN_PROGRESS,
			'abortable' => $row['status'] === OwnershipTransferPolicy::STATUS_IN_PROGRESS && in_array($row['step'], OwnershipTransferPolicy::ABORTABLE_STEPS, true),
			'error' => $row['error'] === null ? null : (string)$row['error'],
			'workspace' => null,
			'quota' => null,
		];
		try {
			$workspace = $this->workspaceIncludingOrphaned((int)$row['workspace_id']);
			$out['workspace'] = ['uuid' => $workspace->getUuid(), 'name' => $workspace->getName()];
			if ($row['status'] === OwnershipTransferPolicy::STATUS_PENDING) {
				$quota = $this->quota($workspace, (string)$row['to_uid']);
				// Only the recipient sees their own free space.
				if ($viewerUid !== $row['to_uid']) {
					$quota = ['folderBytes' => $quota['folderBytes']];
				}
				$out['quota'] = $quota;
			}
		} catch (Throwable) {
			// The workspace was purged; the transfer row is historical.
		}
		return $out;
	}

	/** @return array{folderBytes: int, recipientFreeBytes: int|null, recipientQuotaLimited: bool, fits: bool} */
	private function quota(Workspace $workspace, string $toUid): array {
		$root = $workspace->isOrphaned() ? null : $this->folders->findRoot($workspace);
		$bytes = $root === null ? 0 : max(0, (int)$root->getSize());
		$free = null;
		try {
			$value = $this->rootFolder->getUserFolder($toUid)->getFreeSpace();
			$free = is_numeric($value) ? (int)$value : null;
		} catch (Throwable) {
		}
		return [
			'folderBytes' => $bytes,
			'recipientFreeBytes' => $free !== null && $free >= 0 ? $free : null,
			'recipientQuotaLimited' => $free !== null && $free >= 0,
			'fits' => OwnershipTransferPolicy::fits($bytes, $free),
		];
	}

	/** @param array<string,mixed> $row */
	private function rootStillWithSource(array $row): bool {
		$root = $this->rootItem($row);
		return $root === null || $this->nodeIn((string)$row['from_uid'], (int)$root['old_file_id']) instanceof Folder;
	}

	/** @param array<string,mixed> $row @return array<string,mixed>|null */
	private function rootItem(array $row): ?array {
		foreach ($this->transfers->items((int)$row['id']) as $item) {
			if ($item['item_type'] === 'folder' && $item['rel_path'] === '') {
				return $item;
			}
		}
		return null;
	}

	/** @param array<string,mixed> $row */
	private function setTarget(array $row, ?string $path): void {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_ws_transfers')
			->set('target_path', $q->createNamedParameter($path))
			->set('updated_at', $q->createNamedParameter($this->timeFactory->getTime(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('id', $q->createNamedParameter((int)$row['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function nodeIn(string $uid, int $fileId): ?Node {
		try {
			if (!$this->userManager->userExists($uid)) {
				return null;
			}
			return $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
		} catch (Throwable) {
			return null;
		}
	}

	private static function relative(Folder $root, Node $node): string {
		if ($node->getId() === $root->getId()) {
			return '';
		}
		return ltrim(substr($node->getPath(), strlen(rtrim($root->getPath(), '/'))), '/');
	}

	private function member(int $workspaceId, string $uid): ?WorkspaceMember {
		try {
			return $this->members->findByWorkspaceAndUser($workspaceId, $uid);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<array{userUid: string, role: string, createdAt: int, id: int}> */
	private function memberFacts(int $workspaceId): array {
		$facts = [];
		foreach ($this->members->findForWorkspace($workspaceId) as $member) {
			try {
				$role = $this->authorization->normalizeRole($member->getRole());
			} catch (\InvalidArgumentException) {
				continue;
			}
			$facts[] = ['userUid' => $member->getUserUid(), 'role' => $role, 'createdAt' => $member->getCreatedAt(), 'id' => (int)$member->getId()];
		}
		return $facts;
	}

	/** Workspaces owned by $uid that still have other members. @return list<int> */
	private function ownedWorkspaceIds(string $uid): array {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('maint_spaces')
			->where($q->expr()->eq('owner_uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->isNull('deleted_at'))
			->andWhere($q->expr()->isNull('orphaned_at'));
		$r = $q->executeQuery();
		try {
			$ids = array_map('intval', $r->fetchFirstColumn());
		} finally {
			$r->closeCursor();
		}
		return array_values(array_filter($ids, function (int $id) use ($uid): bool {
			foreach ($this->members->findForWorkspace($id) as $member) {
				if ($member->getUserUid() !== $uid) {
					return true;
				}
			}
			return false;
		}));
	}

	private function workspace(int $id): Workspace {
		try {
			return $this->workspaces->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Workspace not found');
		}
	}

	private function workspaceIncludingOrphaned(int $id): Workspace {
		return $this->workspace($id);
	}

	private static function uid(mixed $value): string {
		if (!is_string($value)) {
			throw new ValidationException('toUid must be a string');
		}
		$value = trim($value);
		if ($value === '' || strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
			throw new ValidationException('toUid is invalid');
		}
		return $value;
	}
}
