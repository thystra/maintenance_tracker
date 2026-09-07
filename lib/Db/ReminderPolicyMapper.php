<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<ReminderPolicy> */
final class ReminderPolicyMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'maint_reminder_policy', ReminderPolicy::class);
	}

	public function findForWorkspace(int $workspaceId): ReminderPolicy {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('maint_reminder_policy')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($query);
	}

	public function updateWithExpectedRevision(ReminderPolicy $policy, int $expectedRevision): bool {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_reminder_policy')
			->set('calendar_lead_days', $query->createNamedParameter($policy->getCalendarLeadDays(), IQueryBuilder::PARAM_INT))
			->set('meter_lead_percent', $query->createNamedParameter($policy->getMeterLeadPercent(), IQueryBuilder::PARAM_INT))
			->set('revision', $query->createNamedParameter($policy->getRevision(), IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter($policy->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($policy->getWorkspaceId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('revision', $query->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		return $query->executeStatement() === 1;
	}
}
