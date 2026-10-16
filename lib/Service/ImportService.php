<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Workspace;
use OCA\MaintenanceTracker\Db\WorkspaceMapper;
use OCA\MaintenanceTracker\Db\WorkspaceMember;
use OCA\MaintenanceTracker\Db\WorkspaceMemberMapper;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use Throwable;
use ZipArchive;

/**
 * Restore a v0.1.13 workspace export into a NEW workspace owned by the caller.
 *
 * 1. Read and validate the upload completely in memory and temp files (no
 *    database, no Files).
 * 2. One database transaction (account lifecycle lock): create the workspace
 *    and owner membership, insert every restorable row with remapped local ids
 *    and fresh UUIDs, and record workspace.imported. Any failure rolls back
 *    the whole restore.
 * 3. After commit, outside any transaction: write evidence files from the ZIP
 *    into the caller's Files and attach them through EvidenceService, which
 *    takes its own short lock for the database part.
 */
final class ImportService {
	public const FILES_FOLDER = 'Maintenance Tracker imports';

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private WorkspaceMapper $workspaceMapper,
		private WorkspaceMemberMapper $memberMapper,
		private UserLifecycleService $lifecycle,
		private UuidGenerator $uuids,
		private AuditService $audit,
		private EvidenceService $evidence,
		private IRootFolder $rootFolder,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function import(string $actorUid, string $path, ?string $name): array {
		$size = filesize($path);
		if ($size === false || $size > ImportPolicy::MAX_UPLOAD_BYTES) {
			throw new ValidationException('The import file exceeds the 640 MiB bound');
		}
		$zip = null;
		$head = (string)file_get_contents($path, false, null, 0, 4);
		if (str_starts_with($head, "PK\x03\x04")) {
			$zip = $this->openZip($path);
			$json = $this->zipEntry($zip, 'data.json', ImportPolicy::MAX_DATA_BYTES);
		} else {
			if ($size > ImportPolicy::MAX_DATA_BYTES) {
				throw new ValidationException('The export JSON exceeds the 64 MiB bound');
			}
			$json = (string)file_get_contents($path);
		}
		try {
			$document = ImportPolicy::document(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
		} catch (\JsonException) {
			throw new ValidationException('The export JSON is not valid JSON');
		}
		unset($json);
		$name = $this->name($name, $document);
		$tables = $document['tables'];
		$uuidMap = ImportPolicy::uuidMap($tables, $document['evidence'] ?? [], fn (): string => $this->uuids->generate());
		$columnTypes = $this->columnTypes();

		$counts = [];
		$workspace = $this->lifecycle->runForActiveUser($actorUid, function () use ($actorUid, $name, $tables, $uuidMap, $columnTypes, &$counts): Workspace {
			$now = $this->timeFactory->getTime();
			$workspace = new Workspace();
			$workspace->setUuid($this->uuids->generate());
			$workspace->setOwnerUid($actorUid);
			$workspace->setPersonalOwnerUid(null);
			$workspace->setName($name);
			$workspace->setKind('shared');
			$workspace->setRevision(1);
			$workspace->setCreatedAt($now);
			$workspace->setUpdatedAt($now);
			/** @var Workspace $workspace */
			$workspace = $this->workspaceMapper->insert($workspace);
			$member = new WorkspaceMember();
			$member->setWorkspaceId($workspace->getId());
			$member->setUserUid($actorUid);
			$member->setRole('owner');
			$member->setCreatedAt($now);
			$this->memberMapper->insert($member);

			$idMap = [];
			foreach (ImportPolicy::ORDER as $section) {
				$table = ExportPolicy::TABLES[$section];
				$types = $columnTypes[$table] ?? throw new \LogicException('Missing table ' . $table);
				foreach ($tables[$section] ?? [] as $row) {
					$values = ImportPolicy::remap($section, $row, $idMap, $uuidMap, $types);
					$newId = $this->insert($table, $workspace->getId(), $values, $types);
					if (isset($row['id'])) {
						$idMap[$section][(int)$row['id']] = $newId;
					}
				}
				$counts[$section] = count($tables[$section] ?? []);
			}
			$this->audit->record($workspace->getId(), 'workspace.imported', $actorUid, $workspace->getUuid(), null, [
				'formatVersion' => ExportPolicy::FORMAT_VERSION,
				'sections' => count(array_filter($counts)),
				'rows' => array_sum($counts),
			]);
			return $workspace;
		});

		$evidence = $this->restoreEvidence($actorUid, $workspace, $document['evidence'] ?? [], $uuidMap, $zip);
		$zip?->close();

		return [
			'workspace' => $workspace->toApi('owner'),
			'restored' => array_filter($counts),
			'notRestored' => array_values(array_filter(ImportPolicy::NOT_RESTORED, static fn (string $s): bool => ($tables[$s] ?? []) !== [])),
			'evidence' => $evidence,
		];
	}

	private function openZip(string $path): ZipArchive {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			throw new ValidationException('The import ZIP cannot be opened');
		}
		if ($zip->numFiles > ImportPolicy::MAX_ZIP_ENTRIES) {
			throw new ValidationException('The import ZIP has too many entries');
		}
		return $zip;
	}

	private function zipEntry(ZipArchive $zip, string $name, int $max): string {
		$stat = $zip->statName($name);
		if ($stat === false) {
			throw new ValidationException('The import ZIP has no ' . $name);
		}
		if ($stat['size'] > $max || ($stat['encryption_method'] ?? 0) !== 0) {
			throw new ValidationException($name . ' in the import ZIP is too large or encrypted');
		}
		$content = $zip->getFromName($name, $max + 1);
		if ($content === false || strlen($content) > $max) {
			throw new ValidationException($name . ' in the import ZIP cannot be read');
		}
		return $content;
	}

	/** @param array<string, mixed> $document */
	private function name(?string $name, array $document): string {
		$name = trim((string)($name ?? ''));
		if ($name === '') {
			$original = is_array($document['workspace'] ?? null) ? (string)($document['workspace']['name'] ?? '') : '';
			$name = ($original === '' ? 'Workspace' : $original) . ' (restored)';
		}
		if (mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F\/\\\\]/u', $name) === 1) {
			throw new ValidationException('name must be at most 120 characters without slashes or control characters');
		}
		return $name;
	}

	/**
	 * Column types of every exported table, from the live schema; unknown
	 * columns in an export are rejected against this list.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function columnTypes(): array {
		$schema = $this->db->createSchema();
		$prefix = (string)$this->config->getSystemValueString('dbtableprefix', 'oc_');
		$types = [];
		foreach (ExportPolicy::TABLES as $table) {
			if (!$schema->hasTable($prefix . $table)) {
				continue;
			}
			foreach ($schema->getTable($prefix . $table)->getColumns() as $column) {
				$type = $column->getType();
				$types[$table][$column->getName()] = match (true) {
					$type instanceof \Doctrine\DBAL\Types\BooleanType => 'bool',
					$type instanceof \Doctrine\DBAL\Types\IntegerType,
					$type instanceof \Doctrine\DBAL\Types\BigIntType,
					$type instanceof \Doctrine\DBAL\Types\SmallIntType => 'int',
					default => 'string',
				};
			}
		}
		return $types;
	}

	/**
	 * @param array<string, scalar|null> $values
	 * @param array<string, string> $types
	 */
	private function insert(string $table, int $workspaceId, array $values, array $types): int {
		$q = $this->db->getQueryBuilder();
		$q->insert($table)->setValue('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT));
		foreach ($values as $column => $value) {
			$paramType = $value === null ? IQueryBuilder::PARAM_NULL : match ($types[$column]) {
				'int' => IQueryBuilder::PARAM_INT,
				'bool' => IQueryBuilder::PARAM_BOOL,
				default => IQueryBuilder::PARAM_STR,
			};
			$q->setValue($column, $q->createNamedParameter($value, $paramType));
		}
		$q->executeStatement();
		return $q->getLastInsertId();
	}

	/**
	 * Recreate evidence whose bytes are in the ZIP: write each file into the
	 * caller's Files (outside any transaction), then attach it to the remapped
	 * records through EvidenceService.
	 *
	 * @param list<array<string, mixed>> $items
	 * @param array<string, string> $uuidMap
	 * @return array{restored: int, skipped: list<array{evidenceUuid: string, reason: string}>}
	 */
	private function restoreEvidence(string $actorUid, Workspace $workspace, array $items, array $uuidMap, ?ZipArchive $zip): array {
		$restored = 0;
		$skipped = [];
		$total = 0;
		$entries = [];
		if ($zip !== null) {
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$entry = (string)$zip->getNameIndex($i);
				if (preg_match('#^files/([0-9a-f-]{36})-([A-Za-z0-9._-]{1,120})$#', $entry, $m) === 1) {
					$entries[strtolower($m[1])] = [$entry, $m[2]];
				}
			}
		}
		$folder = null;
		$context = new WorkspaceContext($workspace, 'owner');
		foreach ($items as $item) {
			$oldUuid = strtolower((string)($item['uuid'] ?? ''));
			if (!isset($entries[$oldUuid])) {
				$skipped[] = ['evidenceUuid' => $oldUuid, 'reason' => ($item['archived'] ?? false) ? 'archived' : 'no_file_in_export'];
				continue;
			}
			[$entry, $fileName] = $entries[$oldUuid];
			$stat = $zip?->statName($entry);
			if ($stat === false || $stat === null || $stat['size'] > ExportPolicy::MAX_FILE_BYTES || $total + $stat['size'] > ExportPolicy::MAX_TOTAL_FILE_BYTES) {
				$skipped[] = ['evidenceUuid' => $oldUuid, 'reason' => 'size_limit'];
				continue;
			}
			try {
				$folder ??= $this->importFolder($actorUid, $workspace);
				$stream = $zip->getStream($entry);
				if ($stream === false) {
					throw new \RuntimeException('stream');
				}
				$target = $folder->newFile($folder->getNonExistingName($fileName));
				$target->putContent($stream);
				$total += $stat['size'];
				$links = [];
				foreach (is_array($item['links'] ?? null) ? $item['links'] : [] as $link) {
					$mapped = $uuidMap[strtolower((string)($link['targetUuid'] ?? ''))] ?? null;
					if ($mapped !== null) {
						$links[] = ['targetType' => (string)$link['targetType'], 'targetUuid' => $mapped];
					}
				}
				if ($links === []) {
					$skipped[] = ['evidenceUuid' => $oldUuid, 'reason' => 'no_restored_target'];
					continue;
				}
				$this->evidence->create($context, $actorUid, [
					'uuid' => $uuidMap[$oldUuid],
					'kind' => (string)($item['kind'] ?? 'other'),
					'title' => $item['title'] ?? null,
					'notes' => $item['notes'] ?? null,
					'fileId' => (int)$target->getId(),
					'links' => $links,
				]);
				$restored++;
			} catch (Throwable $exception) {
				$skipped[] = ['evidenceUuid' => $oldUuid, 'reason' => $exception instanceof ValidationException ? 'rejected' : 'write_failed'];
			}
		}
		return ['restored' => $restored, 'skipped' => $skipped];
	}

	private function importFolder(string $actorUid, Workspace $workspace): Folder {
		$userFolder = $this->rootFolder->getUserFolder($actorUid);
		$base = $userFolder->nodeExists(self::FILES_FOLDER) ? $userFolder->get(self::FILES_FOLDER) : $userFolder->newFolder(self::FILES_FOLDER);
		if (!$base instanceof Folder) {
			throw new \RuntimeException('Import folder is not a folder');
		}
		$name = preg_replace('/[\/\\\\:*?"<>|]+/', '_', $workspace->getName()) . ' ' . gmdate('Y-m-d His', $this->timeFactory->getTime());
		return $base->newFolder($base->getNonExistingName($name));
	}
}
