<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Append/read-only persistence boundary for meter readings.
 *
 * This class intentionally does not extend QBMapper so update/delete methods
 * cannot be inherited accidentally. Corrections append a superseding row.
 */
final class ReadingMapper {
	public function __construct(private IDBConnection $db) {
	}

	public function append(Reading $reading): Reading {
		$query = $this->db->getQueryBuilder();
		$query->insert('maint_readings')->values([
			'workspace_id' => $query->createNamedParameter($reading->getWorkspaceId(), IQueryBuilder::PARAM_INT),
			'meter_id' => $query->createNamedParameter($reading->getMeterId(), IQueryBuilder::PARAM_INT),
			'uuid' => $query->createNamedParameter($reading->getUuid(), IQueryBuilder::PARAM_STR),
			'observed_at' => $query->createNamedParameter($reading->getObservedAt(), IQueryBuilder::PARAM_INT),
			'canonical_value' => $query->createNamedParameter($reading->getCanonicalValue(), IQueryBuilder::PARAM_INT),
			'original_value' => $query->createNamedParameter($reading->getOriginalValue(), IQueryBuilder::PARAM_STR),
			'original_unit' => $query->createNamedParameter($reading->getOriginalUnit(), IQueryBuilder::PARAM_STR),
			'source_type' => $query->createNamedParameter($reading->getSourceType(), IQueryBuilder::PARAM_STR),
			'source_ref' => $this->nullableString($query, $reading->getSourceRef()),
			'notes' => $this->nullableString($query, $reading->getNotes()),
			'supersedes_id' => $this->nullableInt($query, $reading->getSupersedesId()),
			'created_at' => $query->createNamedParameter($reading->getCreatedAt(), IQueryBuilder::PARAM_INT),
		]);
		$query->executeStatement();
		$reading->setId($query->getLastInsertId());

		return $reading;
	}

	/** @return list<Reading> */
	public function findForMeter(int $workspaceId, int $meterId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('r.*')
			->from('maint_readings', 'r')
			->where($query->expr()->eq('r.workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('r.meter_id', $query->createNamedParameter($meterId, IQueryBuilder::PARAM_INT)))
			->orderBy('r.observed_at', 'ASC')
			->addOrderBy('r.id', 'ASC');

		return $this->findMany($query);
	}

	public function findById(int $workspaceId, int $id): Reading {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_readings')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		return $this->findOne($query);
	}

	public function findByUuid(int $workspaceId, string $uuid): Reading {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_readings')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('uuid', $query->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		return $this->findOne($query);
	}

	public function findSuperseding(int $workspaceId, int $readingId): ?Reading {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('maint_readings')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('supersedes_id', $query->createNamedParameter($readingId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		return $this->findOptional($query);
	}

	public function findEffectivePredecessor(int $workspaceId, int $meterId, int $observedAt, ?int $excludeReadingId): ?Reading {
		return $this->findEffectiveNeighbor($workspaceId, $meterId, $observedAt, $excludeReadingId, true);
	}

	public function findEffectiveSuccessor(int $workspaceId, int $meterId, int $observedAt, ?int $excludeReadingId): ?Reading {
		return $this->findEffectiveNeighbor($workspaceId, $meterId, $observedAt, $excludeReadingId, false);
	}

	private function findEffectiveNeighbor(int $workspaceId, int $meterId, int $observedAt, ?int $excludeReadingId, bool $predecessor): ?Reading {
		$query = $this->db->getQueryBuilder();
		$query->select('r.*')
			->from('maint_readings', 'r')
			->leftJoin('r', 'maint_readings', 's', $query->expr()->eq('s.supersedes_id', 'r.id'))
			->where($query->expr()->eq('r.workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('r.meter_id', $query->createNamedParameter($meterId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNull('s.id'));
		if ($excludeReadingId !== null) {
			$query->andWhere($query->expr()->neq('r.id', $query->createNamedParameter($excludeReadingId, IQueryBuilder::PARAM_INT)));
		}
		if ($predecessor) {
			$query->andWhere($query->expr()->lte('r.observed_at', $query->createNamedParameter($observedAt, IQueryBuilder::PARAM_INT)))
				->orderBy('r.observed_at', 'DESC')
				->addOrderBy('r.id', 'DESC');
		} else {
			$query->andWhere($query->expr()->gte('r.observed_at', $query->createNamedParameter($observedAt, IQueryBuilder::PARAM_INT)))
				->orderBy('r.observed_at', 'ASC')
				->addOrderBy('r.id', 'ASC');
		}
		$query->setMaxResults(1);

		return $this->findOptional($query);
	}

	/** @return list<Reading> */
	private function findMany(IQueryBuilder $query): array {
		$result = $query->executeQuery();
		try {
			$rows = [];
			while (($row = $result->fetchAssociative()) !== false) {
				$rows[] = Reading::fromRow($row);
			}
			return $rows;
		} finally {
			$result->closeCursor();
		}
	}

	private function findOne(IQueryBuilder $query): Reading {
		$reading = $this->findOptional($query);
		if ($reading === null) {
			throw new DoesNotExistException('Reading not found');
		}
		return $reading;
	}

	private function findOptional(IQueryBuilder $query): ?Reading {
		$result = $query->executeQuery();
		try {
			$row = $result->fetchAssociative();
			return $row === false ? null : Reading::fromRow($row);
		} finally {
			$result->closeCursor();
		}
	}

	private function nullableInt(IQueryBuilder $query, ?int $value): mixed {
		return $query->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT);
	}

	private function nullableString(IQueryBuilder $query, ?string $value): mixed {
		return $query->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR);
	}
}
