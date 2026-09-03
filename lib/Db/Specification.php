<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\MaintenanceTracker\Db;
use JsonException;use OCP\AppFramework\Db\Entity;use OCP\DB\Types;
/** @method int getWorkspaceId() @method void setWorkspaceId(int $v) @method int getAssetId() @method void setAssetId(int $v) @method int|null getComponentId() @method void setComponentId(?int $v) @method string getUuid() @method void setUuid(string $v) @method string getSpecKey() @method void setSpecKey(string $v) @method string getLabel() @method void setLabel(string $v) @method string getValueJson() @method void setValueJson(string $v) @method string|null getUnit() @method void setUnit(?string $v) @method string|null getRegimeKey() @method void setRegimeKey(?string $v) @method string|null getSourceType() @method void setSourceType(?string $v) @method string|null getSourceRef() @method void setSourceRef(?string $v) @method int getRevision() @method void setRevision(int $v) @method int getCreatedAt() @method void setCreatedAt(int $v) @method int getUpdatedAt() @method void setUpdatedAt(int $v) @method int|null getDeletedAt() @method void setDeletedAt(?int $v) */
final class Specification extends Entity{
 protected int $workspaceId=0;protected int $assetId=0;protected ?int $componentId=null;protected string $uuid='';protected string $specKey='';protected string $label='';protected string $valueJson='null';protected ?string $unit=null;protected ?string $regimeKey=null;protected ?string $sourceType=null;protected ?string $sourceRef=null;protected int $revision=1;protected int $createdAt=0;protected int $updatedAt=0;protected ?int $deletedAt=null;
 public function __construct(){foreach(['workspaceId'=>Types::BIGINT,'assetId'=>Types::BIGINT,'componentId'=>Types::BIGINT,'uuid'=>Types::STRING,'specKey'=>Types::STRING,'label'=>Types::STRING,'valueJson'=>Types::TEXT,'unit'=>Types::STRING,'regimeKey'=>Types::STRING,'sourceType'=>Types::STRING,'sourceRef'=>Types::TEXT,'revision'=>Types::INTEGER,'createdAt'=>Types::BIGINT,'updatedAt'=>Types::BIGINT,'deletedAt'=>Types::BIGINT]as$f=>$t)$this->addType($f,$t);}
 /** @throws JsonException */ public function toApi(?string $componentUuid=null):array{return['uuid'=>$this->getUuid(),'componentUuid'=>$componentUuid,'key'=>$this->getSpecKey(),'label'=>$this->getLabel(),'value'=>json_decode($this->getValueJson(),true,512,JSON_THROW_ON_ERROR),'unit'=>$this->getUnit(),'regime'=>$this->getRegimeKey(),'source'=>$this->getSourceType()===null?null:['type'=>$this->getSourceType(),'reference'=>$this->getSourceRef()],'revision'=>$this->getRevision()];}
}
