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
 * @method void setWorkspaceId(int $value)
 * @method int getCalendarLeadDays()
 * @method void setCalendarLeadDays(int $value)
 * @method int getMeterLeadPercent()
 * @method void setMeterLeadPercent(int $value)
 * @method int getRevision()
 * @method void setRevision(int $value)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $value)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $value)
 */
final class ReminderPolicy extends Entity {
	protected int $workspaceId = 0;
	protected int $calendarLeadDays = 14;
	protected int $meterLeadPercent = 10;
	protected int $revision = 1;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('calendarLeadDays', Types::INTEGER);
		$this->addType('meterLeadPercent', Types::INTEGER);
		$this->addType('revision', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}
}
