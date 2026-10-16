<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\ITempManager;
use Throwable;
use ZipArchive;

/**
 * Explicit, user-requested workspace export.
 *
 * Phase 1 reads every permitted table inside the workspace write lock, so the
 * export is consistent with serialized writes, and records the audit event in
 * the same short database transaction. Phase 2 runs after that transaction:
 * evidence goes through the viewer-scoped evidence projection (Nextcloud
 * Files), and optional evidence bytes are read from the viewer's own Files.
 * Files are never touched inside the app transaction.
 */
final class ExportService {
	public const FORMATS = ['json', 'zip'];

	public function __construct(
		private ExportPolicy $policy,
		private WorkspaceService $workspaces,
		private EvidenceRepository $evidence,
		private EvidenceService $evidenceService,
		private OutsideLinkSettings $outsideLinks,
		private AuditService $audit,
		private IDBConnection $db,
		private ITempManager $tempManager,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return array{fileName: string, mime: string, json?: string, path?: string}
	 */
	public function export(WorkspaceContext $context, string $viewerUid, string $format, bool $includeFiles): array {
		if (!in_array($format, self::FORMATS, true)) {
			throw new ValidationException('format must be json or zip');
		}
		if ($includeFiles && $format !== 'zip') {
			throw new ValidationException('includeFiles requires format zip');
		}
		$document = $this->document($context, $viewerUid, $format, $includeFiles);
		$stamp = gmdate('Ymd-His', $this->timeFactory->getTime());
		$base = 'maintenance-tracker-' . substr($context->workspace()->getUuid(), 0, 8) . '-' . $stamp;
		$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		if ($format === 'json') {
			return ['fileName' => $base . '.json', 'mime' => 'application/json', 'json' => $json];
		}
		return ['fileName' => $base . '.zip', 'mime' => 'application/zip', 'path' => $this->zip($document, $json, $viewerUid, $includeFiles)];
	}

	/** @return array<string, mixed> */
	public function document(WorkspaceContext $context, string $viewerUid, string $format, bool $includeFiles): array {
		$workspace = $context->workspace();
		$linksEnabled = $this->outsideLinks->enabled();
		$sections = $this->policy->sections($context->role(), $linksEnabled);
		$tables = [];
		$now = $this->timeFactory->getTime();

		$this->workspaces->runLocked($viewerUid, $context, function () use ($workspace, $sections, $linksEnabled, $format, $includeFiles, $viewerUid, &$tables): void {
			foreach ($sections['included'] as $section) {
				if (!isset(ExportPolicy::TABLES[$section])) {
					continue;
				}
				$rows = $this->rows(ExportPolicy::TABLES[$section], $workspace->getId());
				if ($section === 'members') {
					$rows = array_map(static fn (array $row): array => ['user_uid' => $row['user_uid'], 'role' => $row['role'], 'created_at' => $row['created_at']], $rows);
				}
				$tables[$section] = array_map(fn (array $row): array => $this->policy->row($section, $row, $linksEnabled), $rows);
			}
			$this->audit->record($workspace->getId(), 'workspace.exported', $viewerUid, $workspace->getUuid(), null, [
				'format' => $format,
				'includeFiles' => $includeFiles,
				'sections' => count($sections['included']),
			]);
		});

		$evidence = [];
		if (in_array('evidence', $sections['included'], true)) {
			foreach ($this->evidence->allForWorkspace($workspace->getId()) as $row) {
				$item = $this->evidenceService->toApi($context, $row, $viewerUid);
				unset($item['canChange']);
				if (is_array($item['file'] ?? null)) {
					unset($item['file']['filesUrl'], $item['file']['downloadUrl'], $item['file']['previewUrl']);
				}
				$evidence[] = $item;
			}
		}

		return [
			'format' => ExportPolicy::FORMAT,
			'formatVersion' => ExportPolicy::FORMAT_VERSION,
			'appVersion' => Application::APP_VERSION,
			'exportedAt' => gmdate('Y-m-d\TH:i:s\Z', $now),
			'exportedBy' => $viewerUid,
			'role' => $context->role(),
			'workspace' => ['uuid' => $workspace->getUuid(), 'name' => $workspace->getName(), 'kind' => $workspace->getKind()],
			'sensitivity' => 'This export can contain private notes, costs, serial numbers, locations and evidence. Store and share it carefully.',
			'notes' => [
				'Rows keep their local numeric "id" so references between tables (for example asset_id) can be followed inside this export; UUIDs are the stable identities.',
				'Timestamps are Unix seconds (UTC). Meter canonical values are integer mm, s or count.',
				'Evidence lists only what your own Nextcloud Files can open; other files are reported as not_shared or missing without names.',
			],
			'sections' => $sections,
			'tables' => $tables,
			'evidence' => $evidence,
		];
	}

	/** @return list<array<string, mixed>> */
	private function rows(string $table, int $workspaceId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from($table)
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		$result = $q->executeQuery();
		try {
			/** @var list<array<string, mixed>> $rows */
			$rows = $result->fetchAllAssociative();
			return $rows;
		} finally {
			$result->closeCursor();
		}
	}

	/** @param array<string, mixed> $document */
	private function zip(array $document, string $json, string $viewerUid, bool $includeFiles): string {
		$path = $this->tempManager->getTemporaryFile('.zip');
		if ($path === false) {
			throw new \RuntimeException('Could not allocate a temporary export file');
		}
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Could not create the export archive');
		}
		$files = [];
		$skipped = [];
		if ($includeFiles) {
			[$files, $skipped] = $this->stageFiles($document['evidence'], $viewerUid);
		}
		$manifest = [
			'format' => $document['format'],
			'formatVersion' => $document['formatVersion'],
			'appVersion' => $document['appVersion'],
			'exportedAt' => $document['exportedAt'],
			'workspace' => $document['workspace'],
			'role' => $document['role'],
			'sensitivity' => $document['sensitivity'],
			'csv' => ['dialect' => 'rfc4180', 'encoding' => 'utf-8', 'spreadsheetEscape' => ExportPolicy::SPREADSHEET_ESCAPE],
			'sections' => $document['sections'],
			'files' => array_map(static fn (array $f): array => ['evidenceUuid' => $f['uuid'], 'entry' => $f['entry'], 'size' => $f['size']], $files),
			'skippedFiles' => $skipped,
		];
		$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$zip->addFromString('data.json', $json);
		foreach ($document['tables'] as $section => $rows) {
			$zip->addFromString('csv/' . $section . '.csv', ExportPolicy::csv($rows));
		}
		$zip->addFromString('csv/evidence.csv', ExportPolicy::csv(array_map(static fn (array $item): array => [
			'uuid' => $item['uuid'],
			'kind' => $item['kind'],
			'title' => $item['title'],
			'notes' => $item['notes'],
			'availability' => $item['availability'],
			'file_name' => is_array($item['file'] ?? null) ? $item['file']['name'] : null,
			'links' => $item['links'],
			'archived' => $item['archived'],
			'created_at' => $item['createdAt'],
		], $document['evidence'])));
		foreach ($files as $file) {
			$zip->addFile($file['tmp'], $file['entry']);
		}
		if (!$zip->close()) {
			throw new \RuntimeException('Could not finish the export archive');
		}
		return $path;
	}

	/**
	 * Copy readable evidence files to temporary files, within the reviewed
	 * per-file and total bounds.
	 *
	 * @param list<array<string, mixed>> $evidence
	 * @return array{0: list<array{uuid: string, entry: string, size: int, tmp: string}>, 1: list<array{evidenceUuid: string, reason: string}>}
	 */
	private function stageFiles(array $evidence, string $viewerUid): array {
		$files = [];
		$skipped = [];
		$total = 0;
		$byUuid = [];
		foreach ($this->evidenceRows($evidence) as $row) {
			$byUuid[(string)$row['uuid']] = $row;
		}
		foreach ($evidence as $item) {
			if (($item['availability'] ?? null) !== 'available' || ($item['archived'] ?? false) || !isset($byUuid[$item['uuid']])) {
				continue;
			}
			$node = $this->evidenceService->readableFile($viewerUid, $byUuid[$item['uuid']]);
			if ($node === null) {
				$skipped[] = ['evidenceUuid' => $item['uuid'], 'reason' => 'not_readable'];
				continue;
			}
			$size = (int)$node->getSize();
			if ($size > ExportPolicy::MAX_FILE_BYTES || $total + $size > ExportPolicy::MAX_TOTAL_FILE_BYTES) {
				$skipped[] = ['evidenceUuid' => $item['uuid'], 'reason' => 'size_limit'];
				continue;
			}
			try {
				$source = $node->fopen('rb');
				$tmp = $this->tempManager->getTemporaryFile();
				if ($source === false || $tmp === false || ($target = fopen($tmp, 'wb')) === false) {
					throw new \RuntimeException('stream');
				}
				stream_copy_to_stream($source, $target);
				fclose($source);
				fclose($target);
			} catch (Throwable) {
				$skipped[] = ['evidenceUuid' => $item['uuid'], 'reason' => 'read_failed'];
				continue;
			}
			$total += $size;
			$files[] = ['uuid' => $item['uuid'], 'entry' => ExportPolicy::fileEntry($item['uuid'], (string)$node->getName()), 'size' => $size, 'tmp' => $tmp];
		}
		return [$files, $skipped];
	}

	/**
	 * @param list<array<string, mixed>> $evidence
	 * @return list<array<string, mixed>>
	 */
	private function evidenceRows(array $evidence): array {
		$rows = [];
		foreach ($evidence as $item) {
			$row = $this->evidence->findByUuid(null, (string)$item['uuid']);
			if ($row !== null) {
				$rows[] = $row;
			}
		}
		return $rows;
	}
}
