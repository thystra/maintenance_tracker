<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getWorkspaceId()
 * @method void setWorkspaceId(int $workspaceId)
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getCategoryKey()
 * @method void setCategoryKey(string $categoryKey)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getManufacturer()
 * @method void setManufacturer(?string $manufacturer)
 * @method string|null getModel()
 * @method void setModel(?string $model)
 * @method int|null getModelYear()
 * @method void setModelYear(?int $modelYear)
 * @method string|null getSerialNumber()
 * @method void setSerialNumber(?string $serialNumber)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getProfileKey()
 * @method void setProfileKey(?string $profileKey)
 * @method string|null getProfileVersion()
 * @method void setProfileVersion(?string $profileVersion)
 * @method string|null getAcquiredOn()
 * @method void setAcquiredOn(?string $acquiredOn)
 * @method int|null getPurchasePriceMinor()
 * @method void setPurchasePriceMinor(?int $purchasePriceMinor)
 * @method string|null getCurrency()
 * @method void setCurrency(?string $currency)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $deletedAt)
 */
final class Asset extends Entity {
	protected int $workspaceId = 0;
	protected string $uuid = '';
	protected string $categoryKey = 'other';
	protected string $name = '';
	protected ?string $manufacturer = null;
	protected ?string $model = null;
	protected ?int $modelYear = null;
	protected ?string $serialNumber = null;
	protected ?string $notes = null;
	protected string $status = 'active';
	protected ?string $profileKey = null;
	protected ?string $profileVersion = null;
	protected ?string $acquiredOn = null;
	protected ?int $purchasePriceMinor = null;
	protected ?string $currency = null;
	protected int $revision = 1;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('uuid', Types::STRING);
		$this->addType('categoryKey', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('manufacturer', Types::STRING);
		$this->addType('model', Types::STRING);
		$this->addType('modelYear', Types::INTEGER);
		$this->addType('serialNumber', Types::STRING);
		$this->addType('notes', Types::TEXT);
		$this->addType('status', Types::STRING);
		$this->addType('profileKey', Types::STRING);
		$this->addType('profileVersion', Types::STRING);
		$this->addType('acquiredOn', Types::STRING);
		$this->addType('purchasePriceMinor', Types::BIGINT);
		$this->addType('currency', Types::STRING);
		$this->addType('revision', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('deletedAt', Types::BIGINT);
	}

	/**
	 * @return array{
	 *     uuid: string,
	 *     category: string,
	 *     name: string,
	 *     manufacturer: string|null,
	 *     model: string|null,
	 *     modelYear: int|null,
	 *     serialNumber: string|null,
	 *     notes: string|null,
	 *     status: string,
	 *     profile: array{key: string, version: string}|null,
	 *     acquiredOn: string|null,
	 *     purchasePrice: array{minor: int, currency: string}|null,
	 *     revision: int,
	 *     createdAt: string,
	 *     updatedAt: string,
	 *     deletedAt: string|null
	 * }
	 */
	public function toApi(): array {
		$profile = null;
		if ($this->getProfileKey() !== null && $this->getProfileVersion() !== null) {
			$profile = [
				'key' => $this->getProfileKey(),
				'version' => $this->getProfileVersion(),
			];
		}

		$purchasePrice = null;
		if ($this->getPurchasePriceMinor() !== null && $this->getCurrency() !== null) {
			$purchasePrice = [
				'minor' => $this->getPurchasePriceMinor(),
				'currency' => $this->getCurrency(),
			];
		}

		return [
			'uuid' => $this->getUuid(),
			'category' => $this->getCategoryKey(),
			'name' => $this->getName(),
			'manufacturer' => $this->getManufacturer(),
			'model' => $this->getModel(),
			'modelYear' => $this->getModelYear(),
			'serialNumber' => $this->getSerialNumber(),
			'notes' => $this->getNotes(),
			'status' => $this->getStatus(),
			'profile' => $profile,
			'acquiredOn' => $this->getAcquiredOn(),
			'purchasePrice' => $purchasePrice,
			'revision' => $this->getRevision(),
			'createdAt' => self::formatTimestamp($this->getCreatedAt()),
			'updatedAt' => self::formatTimestamp($this->getUpdatedAt()),
			'deletedAt' => $this->getDeletedAt() === null
				? null
				: self::formatTimestamp($this->getDeletedAt()),
		];
	}

	private static function formatTimestamp(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
	}
}
