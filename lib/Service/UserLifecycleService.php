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
use Throwable;

final class UserLifecycleService {
	private const WORKSPACE_PURGE_ORDER = [
		'maint_assignments',
		'maint_relationships',
		'maint_specs',
		'maint_components',
		'maint_categories',
		'maint_changes',
		'maint_assets',
		'maint_members',
	];

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Run a personal-workspace operation while serializing it against account
	 * deletion and external identity detachment.
	 *
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	public function runForActiveUser(string $userUid, callable $operation): mixed {
		$state = $this->beginLockedState($userUid, 'active');
		try {
			if ($state !== 'active') {
				throw new AccessDeniedException('The account is no longer active');
			}

			$result = $operation();
			$this->db->commit();

			return $result;
		} catch (Throwable $exception) {
			$this->rollback();
			throw $exception;
		}
	}

	/**
	 * Remove personal workspaces and memberships when a Nextcloud identity is
	 * deleted or detached. The retained state row contains only a SHA-256 UID
	 * key and serializes cleanup against lazy workspace creation.
	 */
	public function purgeUser(string $userUid): void {
		$this->beginLockedState($userUid, 'deleted');
		try {
			$this->setState($userUid, 'deleted');
			$workspaceIds = $this->findPersonalWorkspaceIds($userUid);

			foreach ($workspaceIds as $workspaceId) {
				$this->serializeWorkspacePurge($workspaceId);
				foreach (self::WORKSPACE_PURGE_ORDER as $table) {
					$this->deleteByWorkspace($table, $workspaceId);
				}
				$this->deleteWorkspace($workspaceId);
			}

			$query = $this->db->getQueryBuilder();
			$query->delete('maint_members')
				->where($query->expr()->eq(
					'user_uid',
					$query->createNamedParameter($userUid, IQueryBuilder::PARAM_STR),
				));
			$query->executeStatement();

			$this->db->commit();
		} catch (Throwable $exception) {
			$this->rollback();
			throw $exception;
		}
	}

	/**
	 * Reactivate the lifecycle key when Nextcloud creates or assigns a new
	 * identity. This is intentionally separate from ordinary API requests.
	 */
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
	private function findPersonalWorkspaceIds(string $userUid): array {
		$query = $this->db->getQueryBuilder();
		$query->select('id')
			->from('maint_spaces')
			->where($query->expr()->eq(
				'personal_owner_uid',
				$query->createNamedParameter($userUid, IQueryBuilder::PARAM_STR),
			));

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

	/**
	 * Acquire the same workspace-row write lock used by editor operations before
	 * purging child rows. Without this, a shared-workspace member could insert a
	 * new child after its table was purged but before the workspace row was
	 * deleted, leaving an orphan after the deletion transaction commits.
	 */
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

	/**
	 * Begin a transaction and acquire a database write lock for this hashed
	 * user key before reading its state. A conflicting first insert is retried
	 * after the winning transaction commits.
	 */
	private function beginLockedState(
		string $userUid,
		string $initialState,
	): string {
		$userKey = self::userKey($userUid);

		for ($attempt = 0; $attempt < 3; ++$attempt) {
			$this->db->beginTransaction();
			try {
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
						$query->createNamedParameter(
							$lockToken,
							IQueryBuilder::PARAM_STR,
						),
					)
					->where($query->expr()->eq(
						'user_key',
						$query->createNamedParameter($userKey, IQueryBuilder::PARAM_STR),
					));

				if ($query->executeStatement() === 0) {
					$this->insertState($userKey, $initialState, $lockToken, $now);
				}

				return $this->readState($userKey);
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

	private function insertState(
		string $userKey,
		string $state,
		string $lockToken,
		int $updatedAt,
	): void {
		$query = $this->db->getQueryBuilder();
		$query->insert('maint_user_state')
			->values([
				'user_key' => $query->createNamedParameter(
					$userKey,
					IQueryBuilder::PARAM_STR,
				),
				'state' => $query->createNamedParameter(
					$state,
					IQueryBuilder::PARAM_STR,
				),
				'lock_token' => $query->createNamedParameter(
					$lockToken,
					IQueryBuilder::PARAM_STR,
				),
				'updated_at' => $query->createNamedParameter(
					$updatedAt,
					IQueryBuilder::PARAM_INT,
				),
			]);
		$query->executeStatement();
	}

	private function readState(string $userKey): string {
		$state = $this->readStateOrNull($userKey);
		if ($state === null) {
			throw new \RuntimeException('Account lifecycle state disappeared');
		}

		return $state;
	}

	private function readStateOrNull(string $userKey): ?string {
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

		if ($state === false) {
			return null;
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
