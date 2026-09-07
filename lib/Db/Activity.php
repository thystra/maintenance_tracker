<?php

declare(strict_types=1);
namespace OCA\MaintenanceTracker\Db;
use OCP\AppFramework\Db\Entity; use OCP\DB\Types;
/** @method int getWorkspaceId() @method void setWorkspaceId(int $v) @method int getAssetId() @method void setAssetId(int $v) @method string getUuid() @method void setUuid(string $v) @method int getPerformedAt() @method void setPerformedAt(int $v) @method ?string getSummary() @method void setSummary(?string $v) @method ?string getNotes() @method void setNotes(?string $v) @method int getRevision() @method void setRevision(int $v) @method int getCreatedAt() @method void setCreatedAt(int $v) @method int getUpdatedAt() @method void setUpdatedAt(int $v) @method ?int getDeletedAt() @method void setDeletedAt(?int $v) */
final class Activity extends Entity { protected int $workspaceId=0; protected int $assetId=0; protected string $uuid=''; protected ?int $performedAt=null; protected ?string $summary=null; protected ?string $notes=null; protected int $revision=1; protected int $createdAt=0; protected int $updatedAt=0; protected ?int $deletedAt=null; public function __construct(){foreach(['workspaceId','assetId','performedAt','createdAt','updatedAt','deletedAt'] as $f)$this->addType($f,Types::BIGINT);$this->addType('uuid',Types::STRING);$this->addType('summary',Types::STRING);$this->addType('notes',Types::STRING);$this->addType('revision',Types::INTEGER);} }
