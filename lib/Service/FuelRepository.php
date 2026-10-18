<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Row access for fuel settings (maint_vehicle), regimes (maint_contexts) and tickets (maint_fuel). */
class FuelRepository {
	private const INT_COLUMNS = ['workspace_id', 'asset_id', 'odometer_mm', 'meter_id', 'reading_id', 'volume_ul', 'context_id', 'cost_id', 'filled_at', 'sort_order', 'revision', 'created_at', 'updated_at', 'deleted_at'];
	private const BOOL_COLUMNS = ['full_fill', 'missed_previous', 'is_default'];
	private const STRING_COLUMNS = ['uuid', 'name', 'fuel_tab', 'distance_unit', 'volume_unit', 'economy_unit', 'fuel_type', 'odometer_value', 'odometer_unit', 'volume_value', 'station', 'notes'];

	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string,mixed>|null */
	public function findVehicle(int $workspaceId, int $assetId): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_vehicle')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('asset_id', $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)));
		return $this->one($q);
	}

	/** @return list<array<string,mixed>> */
	public function vehiclesForWorkspace(int $workspaceId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_vehicle')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		return $this->many($q);
	}

	/** @param array<string,mixed> $row */
	public function insertVehicle(array $row): void {
		$this->insert('maint_vehicle', $row);
	}

	/** @param array<string,mixed> $changes */
	public function updateVehicle(int $workspaceId, int $id, int $expectedRevision, array $changes): bool {
		return $this->update('maint_vehicle', $workspaceId, $id, $expectedRevision, $changes);
	}

	/** @return list<array<string,mixed>> */
	public function contexts(int $workspaceId, int $assetId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_contexts')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('asset_id', $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'))
			->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC')->addOrderBy('id', 'ASC');
		return $this->many($q);
	}

	/** @return array<string,mixed>|null */
	public function findContext(int $workspaceId, string $uuid, bool $includeArchived = false): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('maint_contexts')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		if (!$includeArchived) {
			$q->andWhere($q->expr()->isNull('deleted_at'));
		}
		return $this->one($q);
	}

	/** @param array<string,mixed> $row */
	public function insertContext(array $row): void {
		$this->insert('maint_contexts', $row);
	}

	/** @param array<string,mixed> $changes */
	public function updateContext(int $workspaceId, int $id, int $expectedRevision, array $changes): bool {
		return $this->update('maint_contexts', $workspaceId, $id, $expectedRevision, $changes);
	}

	/** @return list<array<string,mixed>> active tickets of one item (or the whole workspace), oldest first */
	public function tickets(int $workspaceId, ?int $assetId): array {
		$q = $this->select();
		$q->where($q->expr()->eq('f.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('f.deleted_at'))
			->andWhere($q->expr()->isNull('a.deleted_at'));
		if ($assetId !== null) {
			$q->andWhere($q->expr()->eq('f.asset_id', $q->createNamedParameter($assetId, IQueryBuilder::PARAM_INT)));
		}
		$q->orderBy('f.asset_id', 'ASC')->addOrderBy('f.filled_at', 'ASC')->addOrderBy('f.odometer_mm', 'ASC')->addOrderBy('f.id', 'ASC');
		return $this->many($q);
	}

	/** @return array<string,mixed>|null */
	public function findTicket(int $workspaceId, string $uuid, bool $includeArchived = false): ?array {
		$q = $this->select();
		$q->where($q->expr()->eq('f.workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('f.uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		if (!$includeArchived) {
			$q->andWhere($q->expr()->isNull('f.deleted_at'));
		}
		return $this->one($q);
	}

	public function uuidExists(string $table, string $uuid): bool {
		if (!in_array($table, ['maint_fuel', 'maint_contexts'], true)) {
			throw new \LogicException('Unknown table');
		}
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from($table)->where($q->expr()->eq('uuid', $q->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));
		return $this->one($q) !== null;
	}

	public function countTicketsForContext(int $workspaceId, int $contextId): int {
		$q = $this->db->getQueryBuilder();
		$q->select($q->func()->count('*'))->from('maint_fuel')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('context_id', $q->createNamedParameter($contextId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->isNull('deleted_at'));
		$result = $q->executeQuery();
		try {
			return (int)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}

	/** @param array<string,mixed> $row */
	public function insertTicket(array $row): void {
		$this->insert('maint_fuel', $row);
	}

	/** @param array<string,mixed> $changes */
	public function updateTicket(int $workspaceId, int $id, int $expectedRevision, array $changes): bool {
		return $this->update('maint_fuel', $workspaceId, $id, $expectedRevision, $changes);
	}

	private function select(): IQueryBuilder {
		$q = $this->db->getQueryBuilder();
		$q->select('f.*', 'a.uuid AS asset_uuid', 'ctx.uuid AS context_uuid', 'ctx.name AS context_name', 'm.uuid AS meter_uuid', 'r.uuid AS reading_uuid',
			'c.uuid AS cost_uuid', 'c.amount_minor AS cost_minor', 'c.currency AS cost_currency', 'c.revision AS cost_revision', 'c.deleted_at AS cost_deleted_at')
			->from('maint_fuel', 'f')
			->innerJoin('f', 'maint_assets', 'a', $q->expr()->eq('f.asset_id', 'a.id'))
			->leftJoin('f', 'maint_contexts', 'ctx', $q->expr()->eq('f.context_id', 'ctx.id'))
			->leftJoin('f', 'maint_meters', 'm', $q->expr()->eq('f.meter_id', 'm.id'))
			->leftJoin('f', 'maint_readings', 'r', $q->expr()->eq('f.reading_id', 'r.id'))
			->leftJoin('f', 'maint_costs', 'c', $q->expr()->eq('f.cost_id', 'c.id'));
		return $q;
	}

	/** @param array<string,mixed> $row */
	private function insert(string $table, array $row): void {
		$q = $this->db->getQueryBuilder();
		$q->insert($table)->values($this->bind($q, $row));
		$q->executeStatement();
	}

	/** @param array<string,mixed> $changes */
	private function update(string $table, int $workspaceId, int $id, int $expectedRevision, array $changes): bool {
		$q = $this->db->getQueryBuilder();
		$q->update($table);
		foreach ($this->bind($q, $changes) as $column => $parameter) {
			$q->set($column, $parameter);
		}
		$q->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('revision', $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		return $q->executeStatement() === 1;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function bind(IQueryBuilder $q, array $row): array {
		$values = [];
		foreach ($row as $column => $value) {
			if ($value === null) {
				$values[$column] = $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL);
			} elseif (in_array($column, self::INT_COLUMNS, true)) {
				$values[$column] = $q->createNamedParameter($value, IQueryBuilder::PARAM_INT);
			} elseif (in_array($column, self::BOOL_COLUMNS, true)) {
				$values[$column] = $q->createNamedParameter((bool)$value, IQueryBuilder::PARAM_BOOL);
			} elseif (in_array($column, self::STRING_COLUMNS, true)) {
				$values[$column] = $q->createNamedParameter($value, IQueryBuilder::PARAM_STR);
			} else {
				throw new \LogicException("Unknown fuel column {$column}");
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
