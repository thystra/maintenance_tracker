<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Store/vendor links attached to canonical parts and vendors. Writes are
 * refused and reads return no links while the administrator has disabled
 * outside links.
 */
final class LinkService {
	public const SUBJECT_TYPES = ['part', 'vendor'];
	public const MAX_PER_SUBJECT = 32;

	public function __construct(
		private LinkRepository $repository,
		private PartService $parts,
		private VendorService $vendors,
		private OutsideLinkSettings $settings,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return array{enabled:bool,items:list<array<string,mixed>>} */
	public function list(WorkspaceContext $context, string $subjectType, string $subjectUuid): array {
		$subject = $this->subject($context, $subjectType, $subjectUuid, true);
		if (!$this->settings->enabled()) {
			return ['enabled' => false, 'items' => []];
		}
		$rows = $this->repository->findActive($context->workspace()->getId(), $subjectType, (int)$subject['id']);
		return ['enabled' => true, 'items' => array_map(fn (array $row): array => $this->toApi($row, $subjectType, (string)$subject['uuid']), $rows)];
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function create(WorkspaceContext $context, string $subjectType, string $subjectUuid, array $input): array {
		$this->settings->assertEnabled();
		CatalogInput::known($input, ['uuid', 'label', 'url'], 'link');
		$subject = $this->subject($context, $subjectType, $subjectUuid, false);
		$uuid = array_key_exists('uuid', $input) ? CatalogInput::uuid($input['uuid'], 'uuid') : $this->uuidGenerator->generate();
		$label = CatalogInput::text($input['label'] ?? null, 'label', 255);
		$url = OutsideLinkPolicy::validate($input['url'] ?? null, 'url');
		$workspaceId = $context->workspace()->getId();
		$existing = $this->repository->findByUuid($workspaceId, $uuid);
		if ($existing !== null) {
			if ($existing['deleted_at'] === null && $existing['subject_type'] === $subjectType && (int)$existing['subject_id'] === (int)$subject['id'] && $existing['label'] === $label && $existing['url'] === $url) {
				return $this->toApi($existing, $subjectType, (string)$subject['uuid']);
			}
			throw new RevisionConflictException('The supplied link UUID already exists with different data');
		}
		if ($this->repository->findByUuid(null, $uuid) !== null) {
			throw new RevisionConflictException('The supplied link UUID already exists with different data');
		}
		$this->insert($context, $subjectType, (int)$subject['id'], $uuid, $label, $url, 'manual');
		return $this->toApi($this->repository->findByUuid($workspaceId, $uuid) ?? throw new \LogicException('Inserted link disappeared'), $subjectType, (string)$subject['uuid']);
	}

	/**
	 * Attach a profile-supplied offer to a canonical part, converging on an
	 * existing active link with the same URL. Callers must have checked
	 * that outside links are enabled before materializing.
	 */
	public function ensureForPart(WorkspaceContext $context, int $partId, string $label, string $url): string {
		$this->settings->assertEnabled();
		$url = OutsideLinkPolicy::validate($url, 'url');
		foreach ($this->repository->findActive($context->workspace()->getId(), 'part', $partId) as $row) {
			if ($row['url'] === $url) {
				return (string)$row['uuid'];
			}
		}
		$uuid = $this->uuidGenerator->generate();
		$this->insert($context, 'part', $partId, $uuid, $label, $url, 'profile');
		return $uuid;
	}

	/** @return array<string,mixed> */
	public function archive(WorkspaceContext $context, string $subjectType, string $subjectUuid, string $linkUuid, int $expectedRevision): array {
		$subject = $this->subject($context, $subjectType, $subjectUuid, true);
		$workspaceId = $context->workspace()->getId();
		$row = $this->repository->findByUuid($workspaceId, CatalogInput::uuid($linkUuid, 'linkUuid'));
		if ($row === null || $row['deleted_at'] !== null || $row['subject_type'] !== $subjectType || (int)$row['subject_id'] !== (int)$subject['id']) {
			throw new NotFoundException('Link not found');
		}
		if ((int)$row['revision'] !== $expectedRevision) {
			throw new RevisionConflictException('The link revision does not match');
		}
		$now = $this->timeFactory->getTime();
		if (!$this->repository->archive($workspaceId, (int)$row['id'], $expectedRevision, $now)) {
			throw new RevisionConflictException('The link revision does not match');
		}
		$this->journal->record($workspaceId, 'link', (string)$row['uuid'], 'delete', $expectedRevision + 1, $now);
		$archived = $this->repository->findByUuid($workspaceId, (string)$row['uuid']) ?? throw new \LogicException('Archived link disappeared');
		return $this->settings->enabled()
			? $this->toApi($archived, $subjectType, (string)$subject['uuid'])
			: ['uuid' => (string)$archived['uuid'], 'revision' => (int)$archived['revision'], 'archived' => true];
	}

	private function insert(WorkspaceContext $context, string $subjectType, int $subjectId, string $uuid, string $label, string $url, string $source): void {
		$workspaceId = $context->workspace()->getId();
		if (count($this->repository->findActive($workspaceId, $subjectType, $subjectId)) >= self::MAX_PER_SUBJECT) {
			throw new ValidationException('At most ' . self::MAX_PER_SUBJECT . ' active links are allowed per ' . $subjectType);
		}
		$now = $this->timeFactory->getTime();
		$this->repository->insert($workspaceId, $uuid, $subjectType, $subjectId, $label, $url, $source, $now);
		$this->journal->record($workspaceId, 'link', $uuid, 'upsert', 1, $now);
	}

	/** @return array<string,mixed> */
	private function subject(WorkspaceContext $context, string $subjectType, string $subjectUuid, bool $includeArchived): array {
		return match ($subjectType) {
			'part' => $this->parts->findRow($context, $subjectUuid, $includeArchived),
			'vendor' => $this->vendors->findRow($context, $subjectUuid, $includeArchived),
			default => throw new NotFoundException('Link subject not found'),
		};
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function toApi(array $row, string $subjectType, string $subjectUuid): array {
		return [
			'uuid' => (string)$row['uuid'],
			'subjectType' => $subjectType,
			'subjectUuid' => $subjectUuid,
			'label' => (string)$row['label'],
			'url' => (string)$row['url'],
			'source' => (string)$row['source'],
			'revision' => (int)$row['revision'],
			'createdAt' => CatalogInput::formatTimestamp((int)$row['created_at']),
			'updatedAt' => CatalogInput::formatTimestamp((int)$row['updated_at']),
			'archived' => $row['deleted_at'] !== null,
		];
	}
}
