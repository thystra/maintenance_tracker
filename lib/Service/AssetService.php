<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Asset;
use OCA\MaintenanceTracker\Db\AssetMapper;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;
use OCP\IDBConnection;
use Throwable;

final class AssetService {
	public function __construct(
		private AssetMapper $assetMapper,
		private AssetValidator $validator,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $changeJournal,
		private ITimeFactory $timeFactory,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array{items: list<Asset>, nextCursor: string|null}
	 */
	public function findPage(
		WorkspaceContext $context,
		?string $cursor,
		int $limit,
	): array {
		if ($limit < 1 || $limit > 100) {
			throw new ValidationException('limit must be between 1 and 100');
		}

		$afterId = $this->decodeCursor($cursor);
		$items = $this->assetMapper->findPageForWorkspace(
			$context->workspace()->getId(),
			$afterId,
			$limit + 1,
		);
		$hasMore = count($items) > $limit;
		if ($hasMore) {
			array_pop($items);
		}

		$nextCursor = null;
		if ($hasMore && $items !== []) {
			$nextCursor = $this->encodeCursor($items[array_key_last($items)]->getId());
		}

		return [
			'items' => $items,
			'nextCursor' => $nextCursor,
		];
	}

	public function find(WorkspaceContext $context, string $uuid): Asset {
		try {
			return $this->assetMapper->findByUuid(
				$context->workspace()->getId(),
				strtolower($uuid),
			);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Asset not found');
		}
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function create(WorkspaceContext $context, array $input): Asset {
		$values = $this->validator->forCreate($input);
		$workspaceId = $context->workspace()->getId();
		$uuid = $values['uuid'] ?? $this->uuidGenerator->generate();

		if ($values['uuid'] !== null) {
			try {
				$existing = $this->assetMapper->findByUuid($workspaceId, $uuid, true);
				if ($existing->getDeletedAt() === null && $this->matchesCreate($existing, $values)) {
					return $existing;
				}

				throw new RevisionConflictException('The supplied asset UUID already exists with different data');
			} catch (DoesNotExistException) {
				// UUID is available.
			}
		}

		$now = $this->timeFactory->getTime();
		$asset = new Asset();
		$asset->setWorkspaceId($workspaceId);
		$asset->setUuid($uuid);
		$this->applyValues($asset, $values);
		$asset->setRevision(1);
		$asset->setCreatedAt($now);
		$asset->setUpdatedAt($now);
		$asset->setDeletedAt(null);

		$ownsTransaction = $this->beginTransactionIfNeeded();
		try {
			/** @var Asset $inserted */
			$inserted = $this->assetMapper->insert($asset);
			$this->changeJournal->record(
				$workspaceId,
				'asset',
				$uuid,
				'upsert',
				1,
				$now,
			);
			$this->commitIfOwned($ownsTransaction);

			return $inserted;
		} catch (DatabaseException $exception) {
			$this->rollbackIfOwned($ownsTransaction);
			if ($exception->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				if (!$ownsTransaction) {
					throw new RevisionConflictException(
						'The supplied asset UUID already exists',
						0,
						$exception,
					);
				}

				try {
					$existing = $this->assetMapper->findByUuid($workspaceId, $uuid, true);
					if ($existing->getDeletedAt() === null && $this->matchesCreate($existing, $values)) {
						return $existing;
					}
				} catch (DoesNotExistException) {
					// Preserve the original database error.
				}

				throw new RevisionConflictException(
					'The supplied asset UUID already exists with different data',
					0,
					$exception,
				);
			}

			throw $exception;
		} catch (Throwable $exception) {
			$this->rollbackIfOwned($ownsTransaction);
			throw $exception;
		}
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function update(
		WorkspaceContext $context,
		string $uuid,
		int $expectedRevision,
		array $input,
	): Asset {
		$values = $this->validator->forPatch($input);
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}

		$asset = $this->find($context, $uuid);
		if ($asset->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The asset has changed since it was last read');
		}

		$this->applyValues($asset, $values);
		$this->validator->validateRelationships([
			'profileKey' => $asset->getProfileKey(),
			'profileVersion' => $asset->getProfileVersion(),
			'purchasePriceMinor' => $asset->getPurchasePriceMinor(),
			'currency' => $asset->getCurrency(),
		]);

		$now = $this->timeFactory->getTime();
		$asset->setRevision($expectedRevision + 1);
		$asset->setUpdatedAt($now);

		return $this->persistRevision($asset, $expectedRevision, 'upsert', $now);
	}

	public function archive(
		WorkspaceContext $context,
		string $uuid,
		int $expectedRevision,
	): Asset {
		if ($expectedRevision < 1) {
			throw new ValidationException('expectedRevision must be positive');
		}

		$asset = $this->find($context, $uuid);
		if ($asset->getRevision() !== $expectedRevision) {
			throw new RevisionConflictException('The asset has changed since it was last read');
		}

		$now = $this->timeFactory->getTime();
		$asset->setStatus('retired');
		$asset->setDeletedAt($now);
		$asset->setUpdatedAt($now);
		$asset->setRevision($expectedRevision + 1);

		return $this->persistRevision($asset, $expectedRevision, 'delete', $now);
	}

	private function persistRevision(
		Asset $asset,
		int $expectedRevision,
		string $operation,
		int $now,
	): Asset {
		$ownsTransaction = $this->beginTransactionIfNeeded();
		try {
			if (!$this->assetMapper->updateWithExpectedRevision($asset, $expectedRevision)) {
				throw new RevisionConflictException('The asset has changed since it was last read');
			}

			$this->changeJournal->record(
				$asset->getWorkspaceId(),
				'asset',
				$asset->getUuid(),
				$operation,
				$asset->getRevision(),
				$now,
			);
			$this->commitIfOwned($ownsTransaction);

			return $asset;
		} catch (Throwable $exception) {
			$this->rollbackIfOwned($ownsTransaction);
			throw $exception;
		}
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function applyValues(Asset $asset, array $values): void {
		foreach ($values as $field => $value) {
			switch ($field) {
				case 'uuid':
					break;
				case 'category':
					$asset->setCategoryKey($value);
					break;
				case 'name':
					$asset->setName($value);
					break;
				case 'manufacturer':
					$asset->setManufacturer($value);
					break;
				case 'model':
					$asset->setModel($value);
					break;
				case 'modelYear':
					$asset->setModelYear($value);
					break;
				case 'serialNumber':
					$asset->setSerialNumber($value);
					break;
				case 'notes':
					$asset->setNotes($value);
					break;
				case 'status':
					$asset->setStatus($value);
					break;
				case 'profileKey':
					$asset->setProfileKey($value);
					break;
				case 'profileVersion':
					$asset->setProfileVersion($value);
					break;
				case 'acquiredOn':
					$asset->setAcquiredOn($value);
					break;
				case 'purchasePriceMinor':
					$asset->setPurchasePriceMinor($value);
					break;
				case 'currency':
					$asset->setCurrency($value);
					break;
			}
		}
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function matchesCreate(Asset $asset, array $values): bool {
		return $asset->getCategoryKey() === $values['category']
			&& $asset->getName() === $values['name']
			&& $asset->getManufacturer() === $values['manufacturer']
			&& $asset->getModel() === $values['model']
			&& $asset->getModelYear() === $values['modelYear']
			&& $asset->getSerialNumber() === $values['serialNumber']
			&& $asset->getNotes() === $values['notes']
			&& $asset->getStatus() === $values['status']
			&& $asset->getProfileKey() === $values['profileKey']
			&& $asset->getProfileVersion() === $values['profileVersion']
			&& $asset->getAcquiredOn() === $values['acquiredOn']
			&& $asset->getPurchasePriceMinor() === $values['purchasePriceMinor']
			&& $asset->getCurrency() === $values['currency'];
	}

	private function beginTransactionIfNeeded(): bool {
		if ($this->db->inTransaction()) {
			return false;
		}

		$this->db->beginTransaction();

		return true;
	}

	private function commitIfOwned(bool $ownsTransaction): void {
		if ($ownsTransaction) {
			$this->db->commit();
		}
	}

	private function rollbackIfOwned(bool $ownsTransaction): void {
		if ($ownsTransaction && $this->db->inTransaction()) {
			$this->db->rollBack();
		}
	}

	private function encodeCursor(int $lastId): string {
		return rtrim(strtr(base64_encode("v1:{$lastId}"), '+/', '-_'), '=');
	}

	private function decodeCursor(?string $cursor): int {
		if ($cursor === null || $cursor === '') {
			return 0;
		}
		if (strlen($cursor) > 64 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
			throw new ValidationException('cursor is invalid');
		}

		$padding = (4 - strlen($cursor) % 4) % 4;
		$decoded = base64_decode(
			strtr($cursor, '-_', '+/') . str_repeat('=', $padding),
			true,
		);
		if ($decoded === false || preg_match('/^v1:([1-9]\d*)$/D', $decoded, $matches) !== 1) {
			throw new ValidationException('cursor is invalid');
		}

		$id = filter_var(
			$matches[1],
			FILTER_VALIDATE_INT,
			['options' => ['min_range' => 1]],
		);
		if ($id === false) {
			throw new ValidationException('cursor is invalid');
		}

		return $id;
	}
}
