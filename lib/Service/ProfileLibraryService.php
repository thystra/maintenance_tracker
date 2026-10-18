<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use JsonException;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The workspace profile library: profiles built in the profile editor,
 * saved from an item or imported from a JSON file. Every write passes the
 * same ProfileValidator as installation (1 MiB, depth and per-list bounds;
 * data only; source URLs are never fetched) and stores the canonical form.
 * Ids of bundled profiles are reserved so a workspace profile can never pose
 * as a first-party one.
 */
class ProfileLibraryService {
	public const MAX_PROFILES = 200;

	public function __construct(
		private ProfileLibraryRepository $repository,
		private ProfileValidator $validator,
		private ProfileCatalog $catalog,
		private ProfileSnapshotBuilder $snapshots,
		private UuidGenerator $uuids,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(WorkspaceContext $context): array {
		return array_map(fn (array $row): array => $this->toApi($row), $this->repository->list($context->workspace()->getId()));
	}

	/** @return array<string,mixed> */
	public function show(WorkspaceContext $context, string $uuid): array {
		return $this->toApi($this->row($context, $uuid));
	}

	/** @param array<string,mixed> $document @return array<string,mixed> */
	public function create(WorkspaceContext $context, array $document, ?string $uuid): array {
		$workspaceId = $context->workspace()->getId();
		$uuid = $uuid === null ? $this->uuids->generate() : CatalogInput::uuid($uuid, 'uuid');
		$v = $this->validator->validate($document);
		$existing = $this->repository->find($workspaceId, $uuid, true);
		if ($existing !== null) {
			if ($existing['deleted_at'] === null && hash_equals((string)$existing['content_hash'], $v['contentHash'])) {
				return $this->toApi($existing);
			}
			throw new RevisionConflictException('The supplied profile UUID already exists with different data');
		}
		if ($this->repository->uuidExists($uuid)) {
			throw new RevisionConflictException('The supplied profile UUID already exists with different data');
		}
		if (count($this->repository->list($workspaceId)) >= self::MAX_PROFILES) {
			throw new ValidationException('A workspace can keep at most ' . self::MAX_PROFILES . ' profiles');
		}
		$this->assertAvailable($workspaceId, $v['profile'], null);
		$now = $this->timeFactory->getTime();
		$this->repository->insert([
			'workspace_id' => $workspaceId,
			'uuid' => $uuid,
			'profile_key' => $v['profile']['id'],
			'profile_version' => $v['profile']['version'],
			'name' => $v['profile']['name'],
			'category' => $v['profile']['category'],
			'content_hash' => $v['contentHash'],
			'content_json' => $v['canonicalJson'],
			'revision' => 1,
			'created_at' => $now,
			'updated_at' => $now,
			'deleted_at' => null,
		]);
		$this->journal->record($workspaceId, 'workspace_profile', $uuid, 'upsert', 1, $now, null, ['profileKey' => $v['profile']['id'], 'profileVersion' => $v['profile']['version']]);
		return $this->toApi($this->row($context, $uuid));
	}

	/** @param array<string,mixed> $document @return array<string,mixed> */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $document): array {
		$row = $this->row($context, $uuid);
		$workspaceId = $context->workspace()->getId();
		$v = $this->validator->validate($document);
		$this->assertAvailable($workspaceId, $v['profile'], (int)$row['id']);
		$now = $this->timeFactory->getTime();
		$ok = $this->repository->update($workspaceId, (int)$row['id'], $expectedRevision, [
			'profile_key' => $v['profile']['id'],
			'profile_version' => $v['profile']['version'],
			'name' => $v['profile']['name'],
			'category' => $v['profile']['category'],
			'content_hash' => $v['contentHash'],
			'content_json' => $v['canonicalJson'],
			'revision' => $expectedRevision + 1,
			'updated_at' => $now,
		]);
		if (!$ok) {
			throw new RevisionConflictException('The profile has changed since it was last read');
		}
		$this->journal->record($workspaceId, 'workspace_profile', $uuid, 'upsert', $expectedRevision + 1, $now, null, ['profileKey' => $v['profile']['id'], 'profileVersion' => $v['profile']['version']]);
		return $this->toApi($this->row($context, $uuid));
	}

	/** @return array<string,mixed> */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		$row = $this->row($context, $uuid);
		$workspaceId = $context->workspace()->getId();
		$now = $this->timeFactory->getTime();
		if (!$this->repository->update($workspaceId, (int)$row['id'], $expectedRevision, ['revision' => $expectedRevision + 1, 'updated_at' => $now, 'deleted_at' => $now])) {
			throw new RevisionConflictException('The profile has changed since it was last read');
		}
		$this->journal->record($workspaceId, 'workspace_profile', $uuid, 'delete', $expectedRevision + 1, $now);
		return ['uuid' => $uuid, 'archived' => true];
	}

	/**
	 * Save an item's setup as a new workspace profile.
	 *
	 * @param array<string,mixed> $input name (required), id, version, description, uuid
	 * @return array<string,mixed>
	 */
	public function saveFromAsset(WorkspaceContext $context, string $assetUuid, array $input): array {
		CatalogInput::known($input, ['uuid', 'id', 'version', 'name', 'description'], 'profile');
		$name = CatalogInput::text($input['name'] ?? null, 'name', 255);
		$workspace = $context->workspace();
		$id = isset($input['id']) && $input['id'] !== '' ? (string)$input['id'] : 'workspace.' . ProfileSnapshotBuilder::slug($name);
		$document = $this->snapshots->fromAsset($context, $assetUuid, [
			'id' => $id,
			'version' => isset($input['version']) && $input['version'] !== '' ? (string)$input['version'] : '1.0.0',
			'name' => $name,
			'description' => isset($input['description']) && trim((string)$input['description']) !== '' ? (string)$input['description'] : 'Saved from an item in ' . $workspace->getName() . '.',
			'author' => $workspace->getName(),
		]);
		return $this->create($context, $document, isset($input['uuid']) ? (string)$input['uuid'] : null);
	}

	/** @param array<string,mixed> $profile */
	private function assertAvailable(int $workspaceId, array $profile, ?int $ownId): void {
		foreach ($this->catalog->listBundled() as $bundled) {
			if ($bundled['id'] === $profile['id']) {
				throw new ValidationException('The id ' . $profile['id'] . ' belongs to a bundled profile; choose another id');
			}
		}
		foreach ($this->repository->list($workspaceId) as $row) {
			if ((int)$row['id'] !== $ownId && $row['profile_key'] === $profile['id'] && $row['profile_version'] === $profile['version']) {
				throw new ValidationException('The workspace already has profile ' . $profile['id'] . ' version ' . $profile['version']);
			}
		}
	}

	/** @return array<string,mixed> */
	private function row(WorkspaceContext $context, string $uuid): array {
		return $this->repository->find($context->workspace()->getId(), strtolower($uuid)) ?? throw new NotFoundException('Profile not found');
	}

	/**
	 * Same shape as a bundled catalog entry, plus the library uuid and revision.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function toApi(array $row): array {
		try {
			$profile = json_decode((string)$row['content_json'], true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new \RuntimeException('Stored profile is not valid JSON', 0, $e);
		}
		$summary = [
			'meters' => count($profile['meters']),
			'components' => array_sum(array_map(static fn (array $c): int => (int)$c['quantity'], $profile['components'])),
			'componentTemplates' => count($profile['components']),
			'parts' => count($profile['parts']),
			'workGroups' => count($profile['workGroups']),
			'workDefinitions' => count($profile['workDefinitions']),
			'specifications' => count($profile['specifications'] ?? []),
			'fuelRegimes' => count($profile['fuel']['regimes'] ?? []),
		];
		return [
			'uuid' => (string)$row['uuid'],
			'id' => (string)$row['profile_key'],
			'version' => (string)$row['profile_version'],
			'name' => (string)$row['name'],
			'category' => (string)$row['category'],
			'description' => (string)$profile['description'],
			'dataLicense' => (string)$profile['dataLicense'],
			'provenance' => $profile['provenance'],
			'applicability' => $profile['applicability'],
			'contentHash' => (string)$row['content_hash'],
			'summary' => $summary,
			'origin' => 'workspace',
			'trustState' => 'local',
			'revision' => (int)$row['revision'],
			'updatedAt' => CatalogInput::formatTimestamp((int)$row['updated_at']),
			'profile' => $profile,
		];
	}
}
