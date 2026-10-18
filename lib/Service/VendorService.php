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
	/** API field => column for the optional contact details. */
	private const CONTACT = ['website' => 'website', 'address' => 'address', 'phone' => 'phone', 'email' => 'email', 'contactName' => 'contact_name'];

	public function __construct(
		private PartCatalogRepository $repository,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
		private OutsideLinkSettings $outsideLinks,
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
		CatalogInput::known($input, ['uuid', 'name', 'notes', ...array_keys(self::CONTACT)], 'vendor');
		$name = CatalogInput::text($input['name'] ?? null, 'name', 255);
		$notes = CatalogInput::optionalText($input['notes'] ?? null, 'notes', 2000, true);
		$contact = $this->contact($input, []);
		$uuid = array_key_exists('uuid', $input) ? CatalogInput::uuid($input['uuid'], 'uuid') : $this->uuidGenerator->generate();
		$workspaceId = $context->workspace()->getId();
		$existing = $this->repository->findVendorByUuid($workspaceId, $uuid, true);
		if ($existing !== null) {
			if ($existing['deleted_at'] === null && $existing['name'] === $name && $existing['notes'] === $notes && $this->sameContact($existing, $contact)) {
				return $this->toApi($existing);
			}
			throw new RevisionConflictException('The supplied vendor UUID already exists with different data');
		}
		if ($this->repository->findVendorByUuidAnyWorkspace($uuid) !== null) {
			throw new RevisionConflictException('The supplied vendor UUID already exists with different data');
		}
		$now = $this->timeFactory->getTime();
		$id = $this->repository->insertVendor($workspaceId, $uuid, $name, $notes, $now, $contact);
		$this->journal->record($workspaceId, 'vendor', $uuid, 'upsert', 1, $now);
		return $this->toApi($this->repository->findVendorById($workspaceId, $id) ?? throw new \LogicException('Inserted vendor disappeared'));
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function update(WorkspaceContext $context, string $uuid, int $expectedRevision, array $input): array {
		CatalogInput::known($input, ['name', 'notes', ...array_keys(self::CONTACT)], 'vendor update');
		$row = $this->findRow($context, $uuid);
		$this->assertRevision($row, $expectedRevision);
		$name = array_key_exists('name', $input) ? CatalogInput::text($input['name'], 'name', 255) : (string)$row['name'];
		$notes = array_key_exists('notes', $input) ? CatalogInput::optionalText($input['notes'], 'notes', 2000, true) : ($row['notes'] === null ? null : (string)$row['notes']);
		return $this->write($context, $row, $expectedRevision, $name, $notes, null, 'upsert', $this->contact($input, $row));
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
			// The website is an outside link: hidden while the admin disables outside links.
			'website' => $this->outsideLinks->enabled() ? self::nullable($row['website'] ?? null) : null,
			'address' => self::nullable($row['address'] ?? null),
			'phone' => self::nullable($row['phone'] ?? null),
			'email' => self::nullable($row['email'] ?? null),
			'contactName' => self::nullable($row['contact_name'] ?? null),
			'revision' => (int)$row['revision'],
			'createdAt' => CatalogInput::formatTimestamp((int)$row['created_at']),
			'updatedAt' => CatalogInput::formatTimestamp((int)$row['updated_at']),
			'deletedAt' => $row['deleted_at'] === null ? null : CatalogInput::formatTimestamp((int)$row['deleted_at']),
		];
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	/** @param array<string,string|null> $contact */
	private function write(WorkspaceContext $context, array $row, int $expectedRevision, string $name, ?string $notes, ?int $deletedAt, string $operation, array $contact = []): array {
		$workspaceId = $context->workspace()->getId();
		$now = $this->timeFactory->getTime();
		if (!$this->repository->updateVendor($workspaceId, (int)$row['id'], $expectedRevision, $name, $notes, $deletedAt, $now, $contact)) {
			throw new RevisionConflictException('The vendor revision does not match');
		}
		$this->journal->record($workspaceId, 'vendor', (string)$row['uuid'], $operation, $expectedRevision + 1, $now);
		return $this->toApi($this->repository->findVendorById($workspaceId, (int)$row['id']) ?? throw new \LogicException('Updated vendor disappeared'));
	}

	/**
	 * Validate the optional contact fields present in $input. Absent fields keep
	 * the stored value; null or '' clears a field.
	 *
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $row
	 * @return array<string,string|null>
	 */
	private function contact(array $input, array $row): array {
		$values = [];
		foreach (self::CONTACT as $field => $column) {
			if (!array_key_exists($field, $input)) {
				if ($row !== []) {
					continue;
				}
				$values[$column] = null;
				continue;
			}
			$value = $input[$field];
			if ($field === 'website' && $row !== [] && ($value === null || $value === '') && !$this->outsideLinks->enabled()) {
				// Clients cannot see a hidden website, so an empty value must not erase it.
				continue;
			}
			if ($value === null || (is_string($value) && trim($value) === '')) {
				$values[$column] = null;
				continue;
			}
			$values[$column] = match ($field) {
				'website' => $this->website($value),
				'address' => CatalogInput::optionalText($value, 'address', 1000, true),
				'phone' => self::phone($value),
				'email' => self::email($value),
				default => CatalogInput::optionalText($value, $field, 255),
			};
		}
		return $values;
	}

	private function website(mixed $value): string {
		$this->outsideLinks->assertEnabled();
		return OutsideLinkPolicy::validate($value, 'website');
	}

	private static function phone(mixed $value): string {
		$phone = CatalogInput::text($value, 'phone', 64);
		if (preg_match('/^[0-9+()\-.\/ #*xX]+$/', $phone) !== 1 || preg_match('/[0-9]/', $phone) !== 1) {
			throw new ValidationException('phone may contain only digits, spaces, and + ( ) - . / # * x');
		}
		return $phone;
	}

	private static function email(mixed $value): string {
		$email = CatalogInput::text($value, 'email', 254);
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new ValidationException('email must be a valid email address');
		}
		return $email;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,string|null> $contact
	 */
	private function sameContact(array $row, array $contact): bool {
		foreach ($contact as $column => $value) {
			if (self::nullable($row[$column] ?? null) !== $value) {
				return false;
			}
		}
		return true;
	}

	private static function nullable(mixed $value): ?string {
		return $value === null ? null : (string)$value;
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
