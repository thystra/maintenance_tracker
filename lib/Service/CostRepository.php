<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class CostRepository {
	private const INT_COLUMNS = ['workspace_id', 'asset_id', 'activity_id', 'vendor_id', 'part_id', 'amount_minor', 'revision', 'created_at', 'updated_at', 'deleted_at'];
	private const STRING_COLUMNS = ['uuid', 'line_type', 'category', 'description', 'currency', 'incurred_on', 'notes'];

	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string,mixed> $row */
	public function insert(array $row): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_costs')->values($this->bind($q, $row));
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/** @param array<string,mixed> $changes */
	public function update(int $workspaceId, int $id, int $expectedRevision, array $changes): bool {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_costs');
		foreach ($this->bind($q, $changes) as $column => $parameter) {
			$q->set($column, $parameter);
		}
		$q->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('revision', $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		return $q->executeStatement() === 1;
	}

	/** @return array<string,mixed>|null */
	public function findByUuid(int $workspaceId, string $uuid, bool $includeArchived = false): ?array {
		$q = $this->select();
		$q->where($q->expr()->eq('c.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('c.uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		if (!$includeArchived) {
			$q->andWhere($q->expr()->isNull('c.deleted_at'));
		}
		return $this->one($q);
	}

	public function uuidExists(string $uuid): bool {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('maint_costs')->where($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		return $this->one($q) !== null;
	}

	/** @return list<array<string,mixed>> */
	public function findActive(int $workspaceId, ?int $assetId): array {
		$q = $this->select();
		$q->where($q->expr()->eq('c.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('c.deleted_at'));
		if ($assetId !== null) {
			$q->andWhere($q->expr()->eq('c.asset_id', $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)));
		}
		$q->orderBy('c.incurred_on', 'DESC')->addOrderBy('c.id', 'DESC');
		return $this->many($q);
	}

	/** @return list<string> distinct currencies of active entries */
	public function activeCurrencies(int $workspaceId): array {
		$q = $this->db->getQueryBuilder();
		$q->selectDistinct('currency')->from('maint_costs')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'))
			->orderBy('currency', 'ASC');
		return array_map(static fn (array $row): string => (string)$row['currency'], $this->many($q));
	}

	/** @return array<string,mixed>|null */
	public function findSettings(int $workspaceId): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_cost_settings')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		return $this->one($q);
	}

	public function insertSettings(int $workspaceId, string $currency, bool $tax, bool $multi, int $now): void {
		$q = $this->db->getQueryBuilder();
		$q->insert('maint_cost_settings')->values([
			'workspace_id' => $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'default_currency' => $q->createNamedParameter($currency, IQueryBuilder::PARAM_STR),
			'tax_enabled' => $q->createNamedParameter($tax, IQueryBuilder::PARAM_BOOL),
			'multi_currency' => $q->createNamedParameter($multi, IQueryBuilder::PARAM_BOOL),
			'revision' => $q->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		]);
		$q->executeStatement();
	}

	public function updateSettings(int $workspaceId, int $expectedRevision, string $currency, bool $tax, bool $multi, int $now): bool {
		$q = $this->db->getQueryBuilder();
		$q->update('maint_cost_settings')
			->set('default_currency', $q->createNamedParameter($currency, IQueryBuilder::PARAM_STR))
			->set('tax_enabled', $q->createNamedParameter($tax, IQueryBuilder::PARAM_BOOL))
			->set('multi_currency', $q->createNamedParameter($multi, IQueryBuilder::PARAM_BOOL))
			->set('revision', $q->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('revision', $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		return $q->executeStatement() === 1;
	}

	private function select(): IQueryBuilder {
		$q = $this->db->getQueryBuilder();
		$q->select('c.*', 'a.uuid AS asset_uuid', 'act.uuid AS activity_uuid', 'v.uuid AS vendor_uuid', 'v.name AS vendor_name', 'p.uuid AS part_uuid')
			->from('maint_costs', 'c')
			->innerJoin('c', 'maint_assets', 'a', $q->expr()->eq('c.asset_id', 'a.id'))
			->leftJoin('c', 'maint_activities', 'act', $q->expr()->eq('c.activity_id', 'act.id'))
			->leftJoin('c', 'maint_vendors', 'v', $q->expr()->eq('c.vendor_id', 'v.id'))
			->leftJoin('c', 'maint_parts', 'p', $q->expr()->eq('c.part_id', 'p.id'));
		return $q;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function bind(IQueryBuilder $q, array $row): array {
		$values = [];
		foreach ($row as $column => $value) {
			if (in_array($column, self::INT_COLUMNS, true)) {
				$values[$column] = $q->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT);
			} elseif (in_array($column, self::STRING_COLUMNS, true)) {
				$values[$column] = $q->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR);
			} else {
				throw new \LogicException("Unknown cost column {$column}");
			}
		}
		return $values;
	}

	/** @return array<string,mixed>|null */
	private function one(IQueryBuilder $q): ?array {
		$result = $q->executeQuery();
		try {
			$row = $result->fetchAssociative();
			return $row === false ? null : $row;
		} finally {
			$result->closeCursor();
		}
	}

	/** @return list<array<string,mixed>> */
	private function many(IQueryBuilder $q): array {
		$result = $q->executeQuery();
		try {
			return $result->fetchAllAssociative();
		} finally {
			$result->closeCursor();
		}
	}
}
