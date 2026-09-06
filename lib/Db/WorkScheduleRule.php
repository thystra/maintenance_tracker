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
 * @method int getDefinitionId()
 * @method void setDefinitionId(int $value)
 * @method int|null getPosition()
 * @method void setPosition(?int $value)
 * @method string getRuleType()
 * @method void setRuleType(string $value)
 * @method string getIntervalValue()
 * @method void setIntervalValue(string $value)
 * @method string getIntervalUnit()
 * @method void setIntervalUnit(string $value)
 * @method int|null getCanonicalValue()
 * @method void setCanonicalValue(?int $value)
 * @method int|null getMeterId()
 * @method void setMeterId(?int $value)
 * @method int|null getWeekdayMask()
 * @method void setWeekdayMask(?int $value)
 */
final class WorkScheduleRule extends Entity {
	protected int $workspaceId = 0;
	protected int $definitionId = 0;
	// Null is a pre-persistence sentinel only. Schedule rules are persisted with an
	// explicit zero-based position and the database column is NOT NULL.
	protected ?int $position = null;
	protected string $ruleType = '';
	protected string $intervalValue = '';
	protected string $intervalUnit = '';
	protected ?int $canonicalValue = null;
	protected ?int $meterId = null;
	protected ?int $weekdayMask = null;

	public function __construct() {
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('definitionId', Types::BIGINT);
		$this->addType('position', Types::INTEGER);
		$this->addType('ruleType', Types::STRING);
		$this->addType('intervalValue', Types::STRING);
		$this->addType('intervalUnit', Types::STRING);
		$this->addType('canonicalValue', Types::BIGINT);
		$this->addType('meterId', Types::BIGINT);
		$this->addType('weekdayMask', Types::INTEGER);
	}
}
