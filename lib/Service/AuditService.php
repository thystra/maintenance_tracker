<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use JsonException;
use OCA\MaintenanceTracker\Db\AuditMapper;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;

final class AuditService {
	public const MAX_DETAILS_BYTES = 4096;

	public function __construct(
		private AuditMapper $mapper,
		private AuditEventCatalog $catalog,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param array<string, scalar|null> $details
	 */
	public function record(
		int $workspaceId,
		string $eventType,
		string $actorUid,
		string $subjectId,
		?int $subjectRevision = null,
		array $details = [],
	): void {
		$definition = $this->catalog->definition($eventType);
		$unknown = array_diff(array_keys($details), $definition['detailKeys']);
		if ($unknown !== []) {
			throw new \LogicException(
				'Unsupported audit detail keys for ' . $eventType . ': ' . implode(', ', $unknown),
			);
		}

		$detailsJson = null;
		if ($details !== []) {
			try {
				$detailsJson = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
			} catch (JsonException $exception) {
				throw new \LogicException('Audit details must be JSON encodable', 0, $exception);
			}
			if (strlen($detailsJson) > self::MAX_DETAILS_BYTES) {
				throw new \LogicException('Audit details exceed the reviewed storage bound');
			}
		}

		$this->mapper->append(
			$workspaceId,
			$eventType,
			AuditEventCatalog::VERSION,
			$actorUid,
			$definition['subjectType'],
			$subjectId,
			$subjectRevision,
			$definition['level'],
			$detailsJson,
			$this->timeFactory->getTime(),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list(int $workspaceId, int $limit): array {
		if ($limit < 1 || $limit > 100) {
			throw new ValidationException('limit must be between 1 and 100');
		}

		return array_map(
			static function (array $row): array {
				$details = [];
				if (is_string($row['details_json'] ?? null) && $row['details_json'] !== '') {
					try {
						$decoded = json_decode($row['details_json'], true, 8, JSON_THROW_ON_ERROR);
						if (is_array($decoded)) {
							$details = $decoded;
						}
					} catch (JsonException) {
						$details = ['invalidStoredDetails' => true];
					}
				}

				return [
					'id' => (int)$row['id'],
					'eventType' => (string)$row['event_type'],
					'eventVersion' => (int)$row['event_version'],
					'actorUid' => (string)$row['actor_uid'],
					'subjectType' => (string)$row['subject_type'],
					'subjectId' => (string)$row['subject_id'],
					'subjectRevision' => $row['subject_revision'] === null
						? null
						: (int)$row['subject_revision'],
					'level' => (string)$row['level'],
					'details' => $details,
					'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['created_at']),
				];
			},
			$this->mapper->findForWorkspace($workspaceId, $limit),
		);
	}
}
