<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/** @method int getWorkspaceId() @method void setWorkspaceId(int $v) @method int getAssetId() @method void setAssetId(int $v) @method int|null getParentId() @method void setParentId(?int $v) @method string getUuid() @method void setUuid(string $v) @method string getTypeKey() @method void setTypeKey(string $v) @method string getName() @method void setName(string $v) @method string|null getManufacturer() @method void setManufacturer(?string $v) @method string|null getModel() @method void setModel(?string $v) @method string|null getPartNumber() @method void setPartNumber(?string $v) @method string|null getSerialNumber() @method void setSerialNumber(?string $v) @method string|null getNotes() @method void setNotes(?string $v) @method string getStatus() @method void setStatus(string $v) @method int getRevision() @method void setRevision(int $v) @method int getCreatedAt() @method void setCreatedAt(int $v) @method int getUpdatedAt() @method void setUpdatedAt(int $v) @method int|null getDeletedAt() @method void setDeletedAt(?int $v) */
final class Component extends Entity {
	protected int $workspaceId=0; protected int $assetId=0; protected ?int $parentId=null; protected string $uuid=''; protected string $typeKey='component'; protected string $name=''; protected ?string $manufacturer=null; protected ?string $model=null; protected ?string $partNumber=null; protected ?string $serialNumber=null; protected ?string $notes=null; protected string $status='active'; protected int $revision=1; protected int $createdAt=0; protected int $updatedAt=0; protected ?int $deletedAt=null;
	public function __construct(){foreach(['workspaceId'=>Types::BIGINT,'assetId'=>Types::BIGINT,'parentId'=>Types::BIGINT,'uuid'=>Types::STRING,'typeKey'=>Types::STRING,'name'=>Types::STRING,'manufacturer'=>Types::STRING,'model'=>Types::STRING,'partNumber'=>Types::STRING,'serialNumber'=>Types::STRING,'notes'=>Types::TEXT,'status'=>Types::STRING,'revision'=>Types::INTEGER,'createdAt'=>Types::BIGINT,'updatedAt'=>Types::BIGINT,'deletedAt'=>Types::BIGINT] as $f=>$t){$this->addType($f,$t);}}
	public function toApi(?string $parentUuid=null):array{return ['uuid'=>$this->getUuid(),'parentUuid'=>$parentUuid,'type'=>$this->getTypeKey(),'name'=>$this->getName(),'manufacturer'=>$this->getManufacturer(),'model'=>$this->getModel(),'partNumber'=>$this->getPartNumber(),'serialNumber'=>$this->getSerialNumber(),'notes'=>$this->getNotes(),'status'=>$this->getStatus(),'revision'=>$this->getRevision()];}
}
