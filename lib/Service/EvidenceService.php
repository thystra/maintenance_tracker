<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\ActivityMapper;
use OCA\MaintenanceTracker\Exception\AccessDeniedException;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IPreview;
use OCP\IURLGenerator;
use Throwable;

/**
 * Evidence references into Nextcloud Files.
 *
 * The app stores a verified file id, never a copy. Every read resolves the id
 * in the *viewer's own* Files view, so a member only gets a file name, path,
 * preview, or Files link when Nextcloud itself would let them open that file
 * (their own file, the shared workspace folder, or any other share). Otherwise
 * only the workspace-level record (kind, title, MIME, size, who attached it)
 * is shown with an availability state.
 */
final class EvidenceService {
	private const CREATE_FIELDS = ['uuid', 'kind', 'title', 'path', 'fileId', 'notes', 'links'];
	private const UPDATE_FIELDS = ['kind', 'title', 'notes'];

	public function __construct(
		private EvidenceRepository $repository,
		private EvidenceFolderService $folders,
		private AssetService $assets,
		private ActivityMapper $activities,
		private CostRepository $costs,
		private FuelRepository $fuel,
		private AuthorizationCatalog $authorization,
		private IRootFolder $rootFolder,
		private IPreview $preview,
		private IURLGenerator $urlGenerator,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private AuditService $audit,
		private ITimeFactory $timeFactory,
		private WorkspaceService $workspaces,
	) {
	}

	/** @return array{items:list<array<string,mixed>>} */
	public function listForTarget(WorkspaceContext $context, string $viewerUid, string $targetType, string $targetUuid): array {
		$target = $this->target($context, EvidencePolicy::targetType($targetType), $targetUuid);
		$rows = $this->repository->findActiveForTarget($context->workspace()->getId(), $target['type'], $target['id']);
		return ['items' => array_map(fn (array $row): array => $this->toApi($context, $row, $viewerUid), $rows)];
	}

	/** @return array<string,mixed> */
	public function show(WorkspaceContext $context, string $viewerUid, string $uuid): array {
		return $this->toApi($context, $this->row($context, $uuid), $viewerUid);
	}

	/**
	 * Attach a file the actor can open in their own Files (by path relative to
	 * their Files root, or by file id) to one or more records.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function create(WorkspaceContext $context, string $actorUid, array $input): array {
		CatalogInput::known($input, self::CREATE_FIELDS, 'evidence');
		$kind = EvidencePolicy::kind($input['kind'] ?? null);
		$uuid = array_key_exists('uuid', $input) ? CatalogInput::uuid($input['uuid'], 'uuid') : $this->uuidGenerator->generate();
		$links = $input['links'] ?? null;
		if (!is_array($links) || !array_is_list($links) || $links === [] || count($links) > EvidencePolicy::MAX_LINKS) {
			throw new ValidationException('links must list 1 to ' . EvidencePolicy::MAX_LINKS . ' targets');
		}
		$targets = [];
		foreach ($links as $i => $link) {
			if (!is_array($link)) {
				throw new ValidationException("links[{$i}] must be an object");
			}
			CatalogInput::known($link, ['targetType', 'targetUuid'], "links[{$i}]");
			$targets[] = $this->target($context, EvidencePolicy::targetType($link['targetType'] ?? null), (string)CatalogInput::uuid($link['targetUuid'] ?? null, "links[{$i}].targetUuid"));
		}

		$file = $this->resolveForActor($actorUid, $input);
		$mime = (string)$file->getMimetype();
		EvidencePolicy::assertMimeFits($kind, $mime);
		$workspaceId = $context->workspace()->getId();
		$fileId = (int)$file->getId();

		$now = $this->timeFactory->getTime();
		$userFolder = $this->rootFolder->getUserFolder($actorUid);
		$storage = $this->inWorkspaceFolder($context, $fileId) ? 'workspace' : 'linked';
		// A privately linked file's name can itself be sensitive, so its default
		// title is the kind label; workspace-folder files are visible to members anyway.
		$fallbackTitle = $storage === 'workspace' ? (string)$file->getName() : EvidencePolicy::defaultTitle($kind);
		$facts = [
			'size' => max(0, (int)$file->getSize()),
			'sha256' => $this->sha256($file),
			'original_name' => substr((string)$file->getName(), 0, 255),
			'last_known_path' => $userFolder->getRelativePath($file->getPath()) ?? '',
		];
		// File work above runs outside any transaction; only database writes are locked.
		$row = $this->workspaces->runLocked($actorUid, $context, function () use ($context, $workspaceId, $uuid, $fileId, $kind, $input, $fallbackTitle, $actorUid, $storage, $mime, $facts, $targets, $now): array {
			$existing = $this->repository->findByUuid($workspaceId, $uuid);
			if ($existing !== null) {
				if ($existing['deleted_at'] === null && $existing['file_id'] === $fileId && $existing['kind'] === $kind) {
					return $existing;
				}
				throw new RevisionConflictException('The supplied evidence UUID already exists with different data');
			}
			if ($this->repository->findByUuid(null, $uuid) !== null) {
				throw new RevisionConflictException('The supplied evidence UUID already exists with different data');
			}
			$duplicate = $this->repository->findActiveByFile($workspaceId, $fileId);
			if ($duplicate !== null) {
				throw new RevisionConflictException('This file is already evidence ' . $duplicate['uuid'] . ' in this workspace; link that evidence instead');
			}

			$id = $this->repository->insert([
				'workspace_id' => $workspaceId,
				'uuid' => $uuid,
				'kind' => $kind,
				'title' => EvidencePolicy::title($input['title'] ?? null, $fallbackTitle),
				'file_id' => $fileId,
				'attached_by' => $actorUid,
				'storage' => $storage,
				'mime' => substr($mime, 0, 255),
				'size' => $facts['size'],
				'sha256' => $facts['sha256'],
				'original_name' => $facts['original_name'],
				'last_known_path' => $facts['last_known_path'],
				'notes' => CatalogInput::optionalText($input['notes'] ?? null, 'notes', 2000, true),
				'revision' => 1,
				'created_at' => $now,
				'updated_at' => $now,
				'deleted_at' => null,
			]);
			foreach ($targets as $target) {
				$this->repository->addLink($workspaceId, $id, $target['type'], $target['id'], $now);
			}
			$this->journal->record($workspaceId, 'evidence', $uuid, 'upsert', 1, $now);
			return $this->row($context, $uuid);
		});
		return $this->toApi($context, $row, $actorUid);
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function update(WorkspaceContext $context, string $actorUid, string $uuid, int $expectedRevision, array $input): array {
		CatalogInput::known($input, self::UPDATE_FIELDS, 'evidence update');
		$row = $this->row($context, $uuid);
		$this->assertMayChange($context, $actorUid, $row);
		$this->assertRevision($row, $expectedRevision);
		$changes = [];
		if (array_key_exists('kind', $input)) {
			$changes['kind'] = EvidencePolicy::kind($input['kind']);
			EvidencePolicy::assertMimeFits($changes['kind'], (string)$row['mime']);
		}
		if (array_key_exists('title', $input)) {
			$changes['title'] = EvidencePolicy::title($input['title'], (string)$row['title']);
		}
		if (array_key_exists('notes', $input)) {
			$changes['notes'] = CatalogInput::optionalText($input['notes'], 'notes', 2000, true);
		}
		$changes += $this->refreshedLocation($context, $row);
		$updated = $this->workspaces->runLocked($actorUid, $context, function () use ($context, $actorUid, $uuid, $expectedRevision, $changes): array {
			$row = $this->row($context, $uuid);
			$this->assertMayChange($context, $actorUid, $row);
			$this->assertRevision($row, $expectedRevision);
			return $this->write($context, $row, $expectedRevision, $changes, 'upsert');
		});
		return $this->toApi($context, $updated, $actorUid);
	}

	/** Remove the evidence record. The file itself is never touched. @return array<string,mixed> */
	public function archive(WorkspaceContext $context, string $actorUid, string $uuid, int $expectedRevision): array {
		$updated = $this->workspaces->runLocked($actorUid, $context, function () use ($context, $actorUid, $uuid, $expectedRevision): array {
			$row = $this->row($context, $uuid);
			$this->assertMayChange($context, $actorUid, $row);
			$this->assertRevision($row, $expectedRevision);
			return $this->write($context, $row, $expectedRevision, ['deleted_at' => $this->timeFactory->getTime()], 'delete');
		});
		return $this->toApi($context, $updated, $actorUid);
	}

	/** @return array<string,mixed> */
	public function link(WorkspaceContext $context, string $actorUid, string $uuid, string $targetType, string $targetUuid): array {
		$this->workspaces->runLocked($actorUid, $context, fn () => $this->linkLocked($context, $actorUid, $uuid, $targetType, $targetUuid));
		return $this->toApi($context, $this->row($context, $uuid), $actorUid);
	}

	private function linkLocked(WorkspaceContext $context, string $actorUid, string $uuid, string $targetType, string $targetUuid): void {
		$row = $this->row($context, $uuid);
		$target = $this->target($context, EvidencePolicy::targetType($targetType), $targetUuid);
		if (count($this->repository->links($context->workspace()->getId(), $row['id'])) >= EvidencePolicy::MAX_LINKS) {
			throw new ValidationException('Evidence can be linked to at most ' . EvidencePolicy::MAX_LINKS . ' records');
		}
		if ($this->repository->addLink($context->workspace()->getId(), $row['id'], $target['type'], $target['id'], $this->timeFactory->getTime())) {
			$this->audit->record($context->workspace()->getId(), 'evidence.linked', $actorUid, (string)$row['uuid'], (int)$row['revision'], ['targetType' => $target['type'], 'targetUuid' => $target['uuid']]);
		}
	}

	/** @return array<string,mixed> */
	public function unlink(WorkspaceContext $context, string $actorUid, string $uuid, string $targetType, string $targetUuid): array {
		$this->workspaces->runLocked($actorUid, $context, fn () => $this->unlinkLocked($context, $actorUid, $uuid, $targetType, $targetUuid));
		return $this->toApi($context, $this->row($context, $uuid), $actorUid);
	}

	private function unlinkLocked(WorkspaceContext $context, string $actorUid, string $uuid, string $targetType, string $targetUuid): void {
		$row = $this->row($context, $uuid);
		$this->assertMayChange($context, $actorUid, $row);
		$target = $this->target($context, EvidencePolicy::targetType($targetType), $targetUuid, true);
		$links = $this->repository->links($context->workspace()->getId(), $row['id']);
		if (count($links) <= 1) {
			throw new ValidationException('Evidence must stay linked to at least one record; archive it instead');
		}
		if (!$this->repository->removeLink($context->workspace()->getId(), $row['id'], $target['type'], $target['id'])) {
			throw new NotFoundException('Evidence link not found');
		}
		$this->audit->record($context->workspace()->getId(), 'evidence.unlinked', $actorUid, (string)$row['uuid'], (int)$row['revision'], ['targetType' => $target['type'], 'targetUuid' => $target['uuid']]);
	}

	/**
	 * Prepare an upload into the app-managed folder: ensures the workspace and
	 * asset folders (and member shares) and returns the destination path in the
	 * caller's own Files view plus a collision-resistant file name. The client
	 * then uploads through WebDAV, so Nextcloud enforces permissions and quota.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function uploadTarget(WorkspaceContext $context, string $actorUid, array $input): array {
		CatalogInput::known($input, ['targetType', 'targetUuid', 'fileName'], 'upload target');
		$target = $this->target($context, EvidencePolicy::targetType($input['targetType'] ?? null), (string)CatalogInput::uuid($input['targetUuid'] ?? null, 'targetUuid'));
		$fileName = CatalogInput::text($input['fileName'] ?? null, 'fileName', 255);
		$asset = $this->assets->findById($context, $target['assetId']);
		$folder = $this->folders->ensureAssetFolder($context->workspace(), $asset, $actorUid);
		$described = $this->folders->describeFor($context->workspace(), $folder, $actorUid);
		$name = EvidencePolicy::uploadName($fileName, gmdate('Y-m-d', $this->timeFactory->getTime()), str_replace('-', '', $this->uuidGenerator->generate()));
		return $described + [
			'fileName' => $name,
			'uploadPath' => $described['available'] ? rtrim((string)$described['path'], '/') . '/' . $name : null,
		];
	}

	/** Workspace folder status for the caller. Never creates anything. @return array<string,mixed> */
	public function folderStatus(WorkspaceContext $context, string $viewerUid): array {
		$root = $this->folders->findRoot($context->workspace());
		if ($root === null) {
			return ['exists' => false, 'available' => false, 'path' => null, 'writable' => false, 'ownerUid' => $context->workspace()->getOwnerUid(), 'freeBytes' => null, 'quotaLimited' => false, 'reason' => 'The workspace evidence folder is created with the first upload'];
		}
		return ['exists' => true] + $this->folders->describeFor($context->workspace(), $root, $viewerUid);
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	public function toApi(WorkspaceContext $context, array $row, string $viewerUid): array {
		$workspaceId = $context->workspace()->getId();
		$links = [];
		foreach ($this->repository->links($workspaceId, $row['id']) as $link) {
			$targetUuid = $this->targetUuid($workspaceId, (string)$link['target_type'], (int)$link['target_id']);
			if ($targetUuid !== null) {
				$links[] = ['targetType' => (string)$link['target_type'], 'targetUuid' => $targetUuid];
			}
		}
		$out = [
			'uuid' => (string)$row['uuid'],
			'kind' => (string)$row['kind'],
			'title' => (string)$row['title'],
			'mime' => (string)$row['mime'],
			'size' => (int)$row['size'],
			'sha256' => $row['sha256'],
			'storage' => (string)$row['storage'],
			'attachedBy' => (string)$row['attached_by'],
			'notes' => $row['notes'],
			'links' => $links,
			'revision' => (int)$row['revision'],
			'createdAt' => CatalogInput::formatTimestamp((int)$row['created_at']),
			'updatedAt' => CatalogInput::formatTimestamp((int)$row['updated_at']),
			'archived' => $row['deleted_at'] !== null,
			'canChange' => $this->mayChange($context, $viewerUid, $row),
		];
		$node = $this->nodeFor($viewerUid, (int)$row['file_id']);
		if ($node !== null) {
			$fileId = (int)$node->getId();
			$userFolder = $this->rootFolder->getUserFolder($viewerUid);
			$mime = (string)$node->getMimetype();
			return $out + [
				'availability' => 'available',
				'file' => [
					'fileId' => $fileId,
					'name' => (string)$node->getName(),
					'path' => $userFolder->getRelativePath($node->getPath()),
					'currentSize' => (int)$node->getSize(),
					'changed' => $row['sha256'] !== null && (int)$node->getSize() !== (int)$row['size'],
					'filesUrl' => $this->urlGenerator->linkToRoute('files.view.showFile', ['fileid' => $fileId]),
					'downloadUrl' => $this->urlGenerator->linkTo('', 'remote.php') . '/dav/files/' . rawurlencode($viewerUid) . implode('/', array_map('rawurlencode', explode('/', (string)$userFolder->getRelativePath($node->getPath())))),
					'previewUrl' => $this->preview->isMimeSupported($mime)
						? $this->urlGenerator->linkToRoute('core.Preview.getPreviewByFileId', ['fileId' => $fileId, 'x' => 256, 'y' => 256, 'a' => 1])
						: null,
				],
			];
		}
		// Not openable by this viewer. Distinguish "exists but not shared with
		// you" from "gone" using the attaching user's view only; no name, path,
		// or file id of someone else's file is disclosed.
		$stillExists = $this->nodeFor((string)$row['attached_by'], (int)$row['file_id']) !== null;
		$out['sha256'] = null;
		return $out + ['availability' => $stillExists ? 'not_shared' : 'missing', 'file' => null];
	}

	/**
	 * The file behind an evidence row as the viewer's own Files sees it, or
	 * null when the viewer cannot open it. Used by export; never call inside
	 * an app database transaction.
	 *
	 * @param array<string,mixed> $row
	 */
	public function readableFile(string $viewerUid, array $row): ?File {
		return $this->nodeFor($viewerUid, (int)$row['file_id']);
	}

	/** @param array<string,mixed> $input */
	private function resolveForActor(string $actorUid, array $input): File {
		$userFolder = $this->rootFolder->getUserFolder($actorUid);
		$hasPath = array_key_exists('path', $input) && $input['path'] !== null;
		$hasId = array_key_exists('fileId', $input) && $input['fileId'] !== null;
		if ($hasPath === $hasId) {
			throw new ValidationException('Provide exactly one of path or fileId');
		}
		try {
			if ($hasId) {
				if (!is_int($input['fileId']) || $input['fileId'] <= 0) {
					throw new ValidationException('fileId must be a positive integer');
				}
				$node = $userFolder->getFirstNodeById($input['fileId']);
			} else {
				$path = CatalogInput::text($input['path'], 'path', 4000);
				if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
					throw new ValidationException('path must not contain parent-directory segments');
				}
				$node = $userFolder->get($path);
			}
		} catch (FilesNotFoundException) {
			$node = null;
		}
		if (!$node instanceof File || !$node->isReadable()) {
			throw new NotFoundException('File not found in your Nextcloud Files');
		}
		return $node;
	}

	private function nodeFor(string $uid, int $fileId): ?File {
		try {
			$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
		} catch (Throwable) {
			return null;
		}
		return $node instanceof File && $node->isReadable() ? $node : null;
	}

	private function inWorkspaceFolder(WorkspaceContext $context, int $fileId): bool {
		$root = $this->folders->findRoot($context->workspace());
		if ($root === null) {
			return false;
		}
		$node = $this->nodeFor($context->workspace()->getOwnerUid(), $fileId);
		return $node !== null && $this->folders->isInside($node, $root);
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function refreshedLocation(WorkspaceContext $context, array $row): array {
		$node = $this->nodeFor((string)$row['attached_by'], (int)$row['file_id']);
		if ($node === null) {
			return [];
		}
		$path = $this->rootFolder->getUserFolder((string)$row['attached_by'])->getRelativePath($node->getPath()) ?? '';
		$storage = $this->inWorkspaceFolder($context, (int)$row['file_id']) ? 'workspace' : 'linked';
		$changes = [];
		if ($path !== $row['last_known_path']) {
			$changes['last_known_path'] = $path;
		}
		if ($storage !== $row['storage']) {
			$changes['storage'] = $storage;
		}
		return $changes;
	}

	private function sha256(File $file): ?string {
		if ((int)$file->getSize() > EvidencePolicy::HASH_LIMIT_BYTES) {
			return null;
		}
		try {
			$handle = $file->fopen('rb');
			if ($handle === false) {
				return null;
			}
			try {
				$ctx = hash_init('sha256');
				hash_update_stream($ctx, $handle);
				return hash_final($ctx);
			} finally {
				fclose($handle);
			}
		} catch (Throwable) {
			return null;
		}
	}

	/** @param array<string,mixed> $row */
	private function mayChange(WorkspaceContext $context, string $actorUid, array $row): bool {
		$role = $context->role();
		return $this->authorization->allows($role, AuthorizationCatalog::EVIDENCE_MANAGE)
			|| ($row['attached_by'] === $actorUid && $this->authorization->allows($role, AuthorizationCatalog::EVIDENCE_CREATE));
	}

	/** @param array<string,mixed> $row */
	private function assertMayChange(WorkspaceContext $context, string $actorUid, array $row): void {
		if (!$this->mayChange($context, $actorUid, $row)) {
			throw new AccessDeniedException('Only the member who attached this evidence, or an Owner/Manager, may change it');
		}
	}

	/** @param array<string,mixed> $row */
	private function assertRevision(array $row, int $expectedRevision): void {
		if ((int)$row['revision'] !== $expectedRevision) {
			throw new RevisionConflictException('The evidence revision does not match');
		}
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $changes @return array<string,mixed> */
	private function write(WorkspaceContext $context, array $row, int $expectedRevision, array $changes, string $op): array {
		$now = $this->timeFactory->getTime();
		if (!$this->repository->update($context->workspace()->getId(), $row['id'], $expectedRevision, $changes, $now)) {
			throw new RevisionConflictException('The evidence revision does not match');
		}
		$this->journal->record($context->workspace()->getId(), 'evidence', (string)$row['uuid'], $op, $expectedRevision + 1, $now);
		return $this->repository->findByUuid($context->workspace()->getId(), (string)$row['uuid']) ?? throw new \LogicException('Evidence disappeared');
	}

	/** @return array<string,mixed> */
	private function row(WorkspaceContext $context, string $uuid): array {
		$row = $this->repository->findByUuid($context->workspace()->getId(), CatalogInput::uuid($uuid, 'uuid'));
		if ($row === null || $row['deleted_at'] !== null) {
			throw new NotFoundException('Evidence not found');
		}
		return $row;
	}

	/** @return array{type:string,id:int,uuid:string,assetId:int} */
	private function target(WorkspaceContext $context, string $type, string $uuid, bool $includeArchived = false): array {
		$uuid = CatalogInput::uuid($uuid, 'targetUuid');
		$workspaceId = $context->workspace()->getId();
		switch ($type) {
			case 'asset':
				$asset = $this->assets->find($context, $uuid);
				return ['type' => 'asset', 'id' => $asset->getId(), 'uuid' => $uuid, 'assetId' => $asset->getId()];
			case 'activity':
				try {
					$activity = $this->activities->findByUuid($workspaceId, $uuid, $includeArchived);
				} catch (DoesNotExistException) {
					throw new NotFoundException('Activity not found');
				}
				return ['type' => 'activity', 'id' => $activity->getId(), 'uuid' => $uuid, 'assetId' => $activity->getAssetId()];
			case 'fuel':
				$ticket = $this->fuel->findTicket($workspaceId, $uuid, $includeArchived);
				if ($ticket === null) {
					throw new NotFoundException('Fuel ticket not found');
				}
				return ['type' => 'fuel', 'id' => (int)$ticket['id'], 'uuid' => $uuid, 'assetId' => (int)$ticket['asset_id']];
			default:
				$cost = $this->costs->findByUuid($workspaceId, $uuid, $includeArchived);
				if ($cost === null) {
					throw new NotFoundException('Cost not found');
				}
				return ['type' => 'cost', 'id' => (int)$cost['id'], 'uuid' => $uuid, 'assetId' => (int)$cost['asset_id']];
		}
	}

	private function targetUuid(int $workspaceId, string $type, int $id): ?string {
		return $this->repository->targetUuid($workspaceId, match ($type) {
			'asset' => 'maint_assets',
			'activity' => 'maint_activities',
			'fuel' => 'maint_fuel',
			default => 'maint_costs',
		}, $id);
	}
}
