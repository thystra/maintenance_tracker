<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\AccessDeniedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

final class UserLifecycleService {
	private const WORKSPACE_PURGE_ORDER = [
		'maint_asset_fit',
		'maint_fitments',
		'maint_offers',
		'maint_fit_bind',
		'maint_fit_targets',
		'maint_fit_imports',
		'maint_fit_revs',
		'maint_fit_packs',
		'maint_parts',
		'maint_evidence_links',
		'maint_evidence',
		'maint_ev_shares',
		'maint_ev_folders',
		'maint_links',
		'maint_costs',
		'maint_cost_settings',
		'maint_part_compat',
		'maint_vendors',
		'maint_fit_slots',
		'maint_prof_bind',
		'maint_asset_prof',
		'maint_prof_revs',
		'maint_profiles',
		'maint_occurrences',
		'maint_reminder_policy',
		'maint_activity_parts',
		'maint_activity_meters',
		'maint_activity_items',
		'maint_activities',
		'maint_work_sched',
		'maint_work_defs',
		'maint_work_groups',
		'maint_readings',
		'maint_meters',
		'maint_assignments',
		'maint_relationships',
		'maint_specs',
		'maint_components',
		'maint_categories',
		'maint_changes',
		'maint_audit',
		'maint_assets',
		'maint_members',
	];

	/** @var list<callable(): void> */
	private array $afterCommit = [];
	private int $depth = 0;

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private AuditService $audit,
	) {
	}

	/**
	 * Run $callback once the outermost lifecycle transaction has committed
	 * (immediately when none is open); it is dropped on rollback. Nextcloud
	 * Files and sharing must never run inside our transaction: core code (for
	 * example the user mount cache) relies on catching unique violations,
	 * which aborts the whole transaction on PostgreSQL. Callbacks are
	 * best-effort; failures are logged without user data and never surface.
	 *
	 * @param callable(): void $callback
	 */
	public function afterCommit(callable $callback): void {
		if ($this->depth === 0) {
			$this->runCallback($callback);
			return;
		}
		$this->afterCommit[] = $callback;
	}

	private function runCallback(callable $callback): void {
		try {
			$callback();
		} catch (Throwable $exception) {
			$this->logger->warning('Maintenance Tracker post-commit task failed', [
				'app' => 'maintenance_tracker',
				'exception' => $exception::class,
			]);
		}
	}

	private function flushAfterCommit(bool $committed): void {
		if ($this->depth !== 0) {
			return;
		}
		$callbacks = $this->afterCommit;
		$this->afterCommit = [];
		if ($committed) {
			foreach ($callbacks as $callback) {
				$this->runCallback($callback);
			}
		}
	}

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	public function runForActiveUser(string $userUid, callable $operation): mixed {
		return $this->runForActiveUsers([$userUid], $operation);
	}

	/**
	 * Serialize an operation against deletion/detachment of every named account.
	 * Locks are acquired in deterministic UID order to avoid actor/target deadlocks.
	 *
	 * @template T
	 * @param list<string> $userUids
	 * @param callable(): T $operation
	 * @return T
	 */
	public function runForActiveUsers(array $userUids, callable $operation): mixed {
		$userUids = array_values(array_unique(array_map(
			static fn (string $uid): string => trim($uid),
			$userUids,
		)));
		if ($userUids === [] || in_array('', $userUids, true)) {
			throw new \InvalidArgumentException('At least one non-empty user UID is required');
		}
		sort($userUids, SORT_STRING);

		for ($attempt = 0; $attempt < 3; ++$attempt) {
			$this->db->beginTransaction();
			++$this->depth;
			try {
				foreach ($userUids as $userUid) {
					if ($this->lockStateInTransaction($userUid, 'active') !== 'active') {
						throw new AccessDeniedException('The account is no longer active');
					}
				}

				$result = $operation();
				$this->db->commit();
				--$this->depth;
				$this->flushAfterCommit(true);

				return $result;
			} catch (DatabaseException $exception) {
				--$this->depth;
				$this->rollback();
				$this->flushAfterCommit(false);
				if (
					$attempt < 2
					&& $exception->getReason()
						=== DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION
				) {
					continue;
				}

				throw $exception;
			} catch (Throwable $exception) {
				--$this->depth;
				$this->rollback();
				$this->flushAfterCommit(false);
				throw $exception;
			}
		}

		throw new \RuntimeException('Could not serialize the account lifecycle');
	}

	/**
	 * Remove personal workspaces and memberships when a Nextcloud identity is
	 * deleted or detached. Shared records authored by this UID are retained;
	 * append-only audit actor attribution therefore remains historically useful.
	 */
	public function purgeUser(string $userUid): void {
		$this->beginLockedState($userUid, 'deleted');
		try {
			$this->setState($userUid, 'deleted');
			$this->closePendingTransfers($userUid);
			foreach ($this->findOwnedWorkspaceIds($userUid) as $workspaceId) {
				if ($this->hasOtherMembers($workspaceId, $userUid)) {
					// Never silently purge a workspace other people use. If the
					// pre-deletion transfer to a Manager did not happen, keep it
					// orphaned for an administrator (occ maintenance_tracker:workspace:assign-owner).
					$this->orphanWorkspace($workspaceId, $userUid);
					continue;
				}
				$this->purgeWorkspace($workspaceId);
			}

			// Nextcloud removes shares to a deleted account itself; drop the
			// matching evidence-folder share bookkeeping in shared workspaces.
			$query = $this->db->getQueryBuilder();
			$query->delete('maint_ev_shares')
				->where($query->expr()->eq(
					'member_uid',
					$query->createNamedParameter($userUid, IQueryBuilder::PARAM_STR),
				));
			$query->executeStatement();

			$query = $this->db->getQueryBuilder();
			$query->delete('maint_members')
				->where($query->expr()->eq(
					'user_uid',
					$query->createNamedParameter($userUid, IQueryBuilder::PARAM_STR),
				));
			$query->executeStatement();

			// An orphaned workspace whose last member is gone has nobody left
			// to assign it to; purge it like any unused workspace.
			foreach ($this->findMemberlessOrphans() as $workspaceId) {
				$this->purgeWorkspace($workspaceId);
			}
			$this->db->commit();
		} catch (Throwable $exception) {
			$this->rollback();
			throw $exception;
		}
	}

	private function purgeWorkspace(int $workspaceId): void {
		$this->serializeWorkspacePurge($workspaceId);
		foreach (self::WORKSPACE_PURGE_ORDER as $table) {
			$this->deleteByWorkspace($table, $workspaceId);
		}
		$this->deleteTransfers($workspaceId);
		$this->deleteWorkspace($workspaceId);
	}

	private function orphanWorkspace(int $workspaceId, string $formerOwnerUid): void {
		$this->serializeWorkspacePurge($workspaceId);
		$now = $this->timeFactory->getTime();
		$query = $this->db->getQueryBuilder();
		$query->update('maint_spaces')
			->set('orphaned_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('personal_owner_uid', $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('kind', $query->createNamedParameter('shared'))
			->set('updated_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		// The owner's Files and shares are deleted with the account.
		$this->deleteByWorkspace('maint_ev_shares', $workspaceId);
		$query = $this->db->getQueryBuilder();
		$query->delete('maint_ev_folders')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('owner_uid', $query->createNamedParameter($formerOwnerUid)))
			->executeStatement();
		$this->audit->record($workspaceId, 'workspace.orphaned', $formerOwnerUid, $this->workspaceUuid($workspaceId), null, ['formerOwnerUid' => $formerOwnerUid]);
	}

	private function closePendingTransfers(string $userUid): void {
		$now = $this->timeFactory->getTime();
		$query = $this->db->getQueryBuilder();
		$query->update('maint_ws_transfers')
			->set('status', $query->createNamedParameter('cancelled'))
			->set('finished_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('status', $query->createNamedParameter('pending')))
			->andWhere($query->expr()->orX(
				$query->expr()->eq('from_uid', $query->createNamedParameter($userUid)),
				$query->expr()->eq('to_uid', $query->createNamedParameter($userUid)),
			))
			->executeStatement();
	}

	private function deleteTransfers(int $workspaceId): void {
		$query = $this->db->getQueryBuilder();
		$query->select('id')->from('maint_ws_transfers')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $query->executeQuery();
		try {
			$ids = array_map('intval', $result->fetchFirstColumn());
		} finally {
			$result->closeCursor();
		}
		foreach (array_chunk($ids, 500) as $chunk) {
			$query = $this->db->getQueryBuilder();
			$query->delete('maint_ws_xfer_items')
				->where($query->expr()->in('transfer_id', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		$this->deleteByWorkspace('maint_ws_transfers', $workspaceId);
	}

	private function hasOtherMembers(int $workspaceId, string $userUid): bool {
		$query = $this->db->getQueryBuilder();
		$query->select('id')->from('maint_members')
			->where($query->expr()->eq('workspace_id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->neq('user_uid', $query->createNamedParameter($userUid)))
			->setMaxResults(1);
		$result = $query->executeQuery();
		try {
			return $result->fetchOne() !== false;
		} finally {
			$result->closeCursor();
		}
	}

	/** @return list<int> */
	private function findMemberlessOrphans(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('s.id')->from('maint_spaces', 's')
			->leftJoin('s', 'maint_members', 'm', $query->expr()->eq('m.workspace_id', 's.id'))
			->where($query->expr()->isNotNull('s.orphaned_at'))
			->andWhere($query->expr()->isNull('m.id'));
		$result = $query->executeQuery();
		try {
			return array_map('intval', $result->fetchFirstColumn());
		} finally {
			$result->closeCursor();
		}
	}

	private function workspaceUuid(int $workspaceId): string {
		$query = $this->db->getQueryBuilder();
		$query->select('uuid')->from('maint_spaces')
			->where($query->expr()->eq('id', $query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $query->executeQuery();
		try {
			return (string)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}

	public function activateUser(string $userUid): void {
		$this->beginLockedState($userUid, 'active');
		try {
			$this->setState($userUid, 'active');
			$this->db->commit();
		} catch (Throwable $exception) {
			$this->rollback();
			throw $exception;
		}
	}

	/**
	 * @return list<int>
	 */
	private function findOwnedWorkspaceIds(string $userUid): array {
		$query = $this->db->getQueryBuilder();
		$query->select('id')
			->from('maint_spaces')
			->where($query->expr()->orX(
				$query->expr()->eq('personal_owner_uid', $query->createNamedParameter($userUid, IQueryBuilder::PARAM_STR)),
				$query->expr()->eq('owner_uid', $query->createNamedParameter($userUid, IQueryBuilder::PARAM_STR)),
			))
			->andWhere($query->expr()->isNull('orphaned_at'));
		$result = $query->executeQuery();
		try {
			return array_map(
				static fn (mixed $id): int => (int)$id,
				$result->fetchFirstColumn(),
			);
		} finally {
			$result->closeCursor();
		}
	}

	private function serializeWorkspacePurge(int $workspaceId): void {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_spaces')
			->set(
				'write_lock_token',
				$query->createNamedParameter(
					bin2hex(random_bytes(16)),
					IQueryBuilder::PARAM_STR,
				),
			)
			->where($query->expr()->eq(
				'id',
				$query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			));

		if ($query->executeStatement() !== 1) {
			throw new \RuntimeException('Workspace disappeared while serializing account deletion');
		}
	}

	private function deleteByWorkspace(string $table, int $workspaceId): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($table)
			->where($query->expr()->eq(
				'workspace_id',
				$query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			));
		$query->executeStatement();
	}

	private function deleteWorkspace(int $workspaceId): void {
		$query = $this->db->getQueryBuilder();
		$query->delete('maint_spaces')
			->where($query->expr()->eq(
				'id',
				$query->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			));
		$query->executeStatement();
	}

	private function beginLockedState(string $userUid, string $initialState): string {
		for ($attempt = 0; $attempt < 3; ++$attempt) {
			$this->db->beginTransaction();
			try {
				return $this->lockStateInTransaction($userUid, $initialState);
			} catch (DatabaseException $exception) {
				$this->rollback();
				if (
					$attempt < 2
					&& $exception->getReason()
						=== DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION
				) {
					continue;
				}

				throw $exception;
			} catch (Throwable $exception) {
				$this->rollback();
				throw $exception;
			}
		}

		throw new \RuntimeException('Could not serialize the account lifecycle');
	}

	private function lockStateInTransaction(string $userUid, string $initialState): string {
		$userKey = self::userKey($userUid);
		$now = $this->timeFactory->getTime();
		$lockToken = bin2hex(random_bytes(16));
		$query = $this->db->getQueryBuilder();
		$query->update('maint_user_state')
			->set(
				'updated_at',
				$query->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			)
			->set(
				'lock_token',
				$query->createNamedParameter($lockToken, IQueryBuilder::PARAM_STR),
			)
			->where($query->expr()->eq(
				'user_key',
				$query->createNamedParameter($userKey, IQueryBuilder::PARAM_STR),
			));

		if ($query->executeStatement() === 0) {
			$this->insertState($userKey, $initialState, $lockToken, $now);
		}

		return $this->readState($userKey);
	}

	private function insertState(
		string $userKey,
		string $state,
		string $lockToken,
		int $updatedAt,
	): void {
		$query = $this->db->getQueryBuilder();
		$query->insert('maint_user_state')
			->values([
				'user_key' => $query->createNamedParameter($userKey, IQueryBuilder::PARAM_STR),
				'state' => $query->createNamedParameter($state, IQueryBuilder::PARAM_STR),
				'lock_token' => $query->createNamedParameter($lockToken, IQueryBuilder::PARAM_STR),
				'updated_at' => $query->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT),
			]);
		$query->executeStatement();
	}

	private function readState(string $userKey): string {
		$query = $this->db->getQueryBuilder();
		$query->select('state')
			->from('maint_user_state')
			->where($query->expr()->eq(
				'user_key',
				$query->createNamedParameter($userKey, IQueryBuilder::PARAM_STR),
			));
		$result = $query->executeQuery();
		try {
			$state = $result->fetchOne();
		} finally {
			$result->closeCursor();
		}

		if (!is_string($state) || !in_array($state, ['active', 'deleted'], true)) {
			throw new \RuntimeException('Invalid account lifecycle state');
		}

		return $state;
	}

	private function setState(string $userUid, string $state): void {
		$query = $this->db->getQueryBuilder();
		$query->update('maint_user_state')
			->set(
				'state',
				$query->createNamedParameter($state, IQueryBuilder::PARAM_STR),
			)
			->set(
				'lock_token',
				$query->createNamedParameter(
					bin2hex(random_bytes(16)),
					IQueryBuilder::PARAM_STR,
				),
			)
			->set(
				'updated_at',
				$query->createNamedParameter(
					$this->timeFactory->getTime(),
					IQueryBuilder::PARAM_INT,
				),
			)
			->where($query->expr()->eq(
				'user_key',
				$query->createNamedParameter(
					self::userKey($userUid),
					IQueryBuilder::PARAM_STR,
				),
			));

		if ($query->executeStatement() !== 1) {
			throw new \RuntimeException('Account lifecycle state disappeared');
		}
	}

	private static function userKey(string $userUid): string {
		return hash('sha256', $userUid);
	}

	private function rollback(): void {
		if ($this->db->inTransaction()) {
			$this->db->rollBack();
		}
	}
}
