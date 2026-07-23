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
 * @method string getUserUid()
 * @method void setUserUid(string $userUid)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
final class WorkspaceMember extends Entity {
	protected int $workspaceId = 0;
	protected string $userUid = '';
	protected string $role = 'viewer';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('userUid', Types::STRING);
		$this->addType('role', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
	}
}
