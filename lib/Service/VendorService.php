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

final class VendorService {
	public function __construct(
		private PartCatalogRepository $repository,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(WorkspaceContext $context, bool $includeArchived = false): array {
		return array_map(fn (array $row): array => $this->toApi($row), $this->repository->listVendors($context->workspace()->getId(), $includeArchived));
	}

	/** @return array<string,mixed> */
	public function findRow(WorkspaceContext $context, string $uuid, bool $includeArchived = false): array {
		$row = $this->repository->findVendorByUuid($context->workspace()->getId(), CatalogInput::uuid($uuid, 'vendorUuid'), $includeArchived);
		if ($row === null) {
			throw new NotFoundException('Vendor not found');
		}
		return $row;
	}

	/** @return array<string,mixed> */
	public function show(WorkspaceContext $context, string $uuid): array {
		return $this->toApi($this->findRow($context, $uuid, true));
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function create(WorkspaceContext $context, array $input): array {
		CatalogInput::known($input, ['uuid', 'name', 'notes'], 'vendor');
		$name = CatalogInput::text($input['name'] ?? null, 'name', 255);
		$notes = CatalogInput::optionalText($input['notes'] ?? null, 'notes', 2000, true);
		$uuid = array_key_exists('uuid', $input) ? CatalogInput::uuid($input['uuid'], 'uuid') : $this->uuidGenerator->generate();
		$workspaceId = $context->workspace()->getId();
		$existing = $this->repository->findVendorByUuid($workspaceId, $uuid, true);
		if ($existing !== null) {
			if ($existing['deleted_at'] === null && $existing['name'] === $name && $existing['notes'] === $notes) {
				return $this->toApi($existing);
			}
			throw new RevisionConflictException('The supplied vendor UUID already exists with different data');
		}
		if ($this->repository->findVendorByUuidAnyWorkspace($uuid) !== null) {
			throw new RevisionConflictException('The supplied vendor UUID already exists with different data');
		}
		$now = $this->timeFactory->getTime();
		$id = $this->repository->insertVendor($workspaceId, $uuid, $name, $notes, $now);
		$this->journal->record($workspaceId, 'vendor', $uuid, 'upsert', 1, $now);
		return $this->toApi($this->repository->findVendorById($workspaceId, $id) ?? throw new \LogicException('Inserted vendor disappeared'));
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		CatalogInput::known($input, ['name', 'notes'], 'vendor update');
		$row = $this->findRow($context, $uuid);
		$this->assertRevision($row, $expectedRevision);
		$name = array_key_exists('name', $input) ? CatalogInput::text($input['name'], 'name', 255) : (string)$row['name'];
		$notes = array_key_exists('notes', $input) ? CatalogInput::optionalText($input['notes'], 'notes', 2000, true) : ($row['notes'] === null ? null : (string)$row['notes']);
		return $this->write($context, $row, $expectedRevision, $name, $notes, null, 'upsert');
	}

	/** @return array<string,mixed> */
	public function archive(WorkspaceContext $context, string $uuid, int $expectedRevision): array {
		$row = $this->findRow($context, $uuid);
		$this->assertRevision($row, $expectedRevision);
		return $this->write($context, $row, $expectedRevision, (string)$row['name'], $row['notes'] === null ? null : (string)$row['notes'], $this->timeFactory->getTime(), 'delete');
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	public function toApi(array $row): array {
		return [
			'uuid' => (string)$row['uuid'],
			'name' => (string)$row['name'],
			'notes' => $row['notes'] === null ? null : (string)$row['notes'],
			'revision' => (int)$row['revision'],
			'createdAt' => CatalogInput::formatTimestamp((int)$row['created_at']),
			'updatedAt' => CatalogInput::formatTimestamp((int)$row['updated_at']),
			'deletedAt' => $row['deleted_at'] === null ? null : CatalogInput::formatTimestamp((int)$row['deleted_at']),
		];
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function write(WorkspaceContext $context, array $row, int $expectedRevision, string $name, ?string $notes, ?int $deletedAt, string $operation): array {
		$workspaceId = $context->workspace()->getId();
		$now = $this->timeFactory->getTime();
		if (!$this->repository->updateVendor($workspaceId, (int)$row['id'], $expectedRevision, $name, $notes, $deletedAt, $now)) {
			throw new RevisionConflictException('The vendor revision does not match');
		}
		$this->journal->record($workspaceId, 'vendor', (string)$row['uuid'], $operation, $expectedRevision + 1, $now);
		return $this->toApi($this->repository->findVendorById($workspaceId, (int)$row['id']) ?? throw new \LogicException('Updated vendor disappeared'));
	}

	/** @param array<string,mixed> $row */
	private function assertRevision(array $row, int $expectedRevision): void {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be a positive integer');
		}
		if ((int)$row['revision'] !== $expectedRevision) {
			throw new RevisionConflictException('The vendor revision does not match');
		}
	}
}
