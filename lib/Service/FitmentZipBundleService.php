<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

final class FitmentZipBundleService {
	public const MAX_COMPRESSED_BYTES = 8 * 1024 * 1024;
	private const FIXED_MTIME = 315532800; // 1980-01-01T00:00:00Z, valid ZIP epoch.

	public function __construct(
		private FitmentCsvBundleService $csv,
		private FitmentPackValidator $validator,
	) {
	}

	/**
	 * @return array{pack:array<string,mixed>,canonicalJson:string,contentHash:string,summary:array<string,int>}
	 */
	public function importPath(string $path): array {
		$this->requireZip();
		$size = @filesize($path);
		if (!is_int($size) || $size <= 0) {
			throw new ValidationException('Uploaded fitment ZIP bundle is unavailable or empty');
		}
		if ($size > self::MAX_COMPRESSED_BYTES) {
			throw new ValidationException('Fitment ZIP bundle exceeds the 8 MiB reviewed compressed-size bound');
		}

		$zip = new \ZipArchive();
		$result = $zip->open($path, \ZipArchive::RDONLY);
		if ($result !== true) {
			throw new ValidationException('Uploaded fitment bundle is not a readable ZIP archive');
		}
		try {
			if ($zip->getArchiveComment() !== '') {
				throw new ValidationException('Fitment ZIP bundle archive comments are not allowed');
			}
			$allowed = array_fill_keys($this->csv->fileNames(), true);
			$seen = [];
			$stats = [];
			$expanded = 0;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i, \ZipArchive::FL_UNCHANGED);
				if (!is_array($stat) || !isset($stat['name']) || !is_string($stat['name'])) {
					throw new ValidationException('Fitment ZIP bundle contains an unreadable entry');
				}
				$name = $stat['name'];
				if (!$this->safeRootName($name) || !isset($allowed[$name])) {
					throw new ValidationException("Fitment ZIP bundle contains an unknown or unsafe entry: {$name}");
				}
				if (isset($seen[$name])) {
					throw new ValidationException("Fitment ZIP bundle contains duplicate entry {$name}");
				}
				$seen[$name] = true;
				if ($zip->getCommentIndex($i, \ZipArchive::FL_UNCHANGED) !== '') {
					throw new ValidationException("Fitment ZIP bundle entry {$name} contains an unsupported comment");
				}
				$uncompressed = $stat['size'] ?? null;
				$compressed = $stat['comp_size'] ?? null;
				$method = $stat['comp_method'] ?? null;
				$encryption = $stat['encryption_method'] ?? \ZipArchive::EM_NONE;
				if (!is_int($uncompressed) || $uncompressed < 0 || !is_int($compressed) || $compressed < 0) {
					throw new ValidationException("Fitment ZIP bundle entry {$name} has invalid size metadata");
				}
				if (!in_array($method, [\ZipArchive::CM_STORE, \ZipArchive::CM_DEFLATE], true)) {
					throw new ValidationException("Fitment ZIP bundle entry {$name} uses an unsupported compression method");
				}
				if ($encryption !== \ZipArchive::EM_NONE) {
					throw new ValidationException("Fitment ZIP bundle entry {$name} must not be encrypted");
				}
				$this->rejectSymlink($zip, $i, $name);
				$expanded += $uncompressed;
				if ($expanded > FitmentCsvBundleService::MAX_EXPANDED_BYTES) {
					throw new ValidationException('Fitment ZIP bundle exceeds the 32 MiB reviewed expanded-content bound');
				}
				$stats[$name] = ['index' => $i, 'size' => $uncompressed];
			}
			$expected = $this->csv->fileNames();
			foreach ($expected as $name) {
				if (!isset($stats[$name])) {
					throw new ValidationException("Fitment ZIP bundle is missing required entry {$name}");
				}
			}
			if (count($stats) !== count($expected)) {
				throw new ValidationException('Fitment ZIP bundle contains an invalid entry set');
			}

			$files = [];
			$actualExpanded = 0;
			foreach ($expected as $name) {
				$bytes = $zip->getFromIndex($stats[$name]['index'], $stats[$name]['size'], \ZipArchive::FL_UNCHANGED);
				if (!is_string($bytes) || strlen($bytes) !== $stats[$name]['size']) {
					throw new ValidationException("Fitment ZIP bundle entry {$name} could not be read completely");
				}
				$actualExpanded += strlen($bytes);
				if ($actualExpanded > FitmentCsvBundleService::MAX_EXPANDED_BYTES) {
					throw new ValidationException('Fitment ZIP bundle exceeds the 32 MiB reviewed expanded-content bound');
				}
				$files[$name] = $bytes;
			}
			return $this->csv->importFiles($files);
		} finally {
			$zip->close();
		}
	}

	/** @param array<string,mixed> $pack */
	public function exportBytes(array $pack): string {
		$this->requireZip();
		$files = $this->csv->exportFiles($pack);
		$path = tempnam(sys_get_temp_dir(), 'maint-fitment-');
		if (!is_string($path)) {
			throw new \RuntimeException('Could not allocate temporary fitment ZIP path');
		}
		$zip = new \ZipArchive();
		try {
			$result = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
			if ($result !== true) {
				throw new \RuntimeException('Could not create temporary fitment ZIP archive');
			}
			foreach ($this->csv->fileNames() as $name) {
				if (!$zip->addFromString($name, $files[$name])) {
					throw new \RuntimeException("Could not add {$name} to fitment ZIP archive");
				}
				if (!$zip->setCompressionName($name, \ZipArchive::CM_DEFLATE, 9)) {
					throw new \RuntimeException("Could not set compression for {$name} in fitment ZIP archive");
				}
				if (method_exists($zip, 'setMtimeName') && !$zip->setMtimeName($name, self::FIXED_MTIME)) {
					throw new \RuntimeException("Could not normalize timestamp for {$name} in fitment ZIP archive");
				}
			}
			if (!$zip->close()) {
				throw new \RuntimeException('Could not finalize fitment ZIP archive');
			}
			$zip = null;
			$size = @filesize($path);
			if (!is_int($size) || $size > self::MAX_COMPRESSED_BYTES) {
				throw new ValidationException('Generated fitment ZIP bundle exceeds the 8 MiB reviewed compressed-size bound');
			}
			$bytes = @file_get_contents($path);
			if (!is_string($bytes) || strlen($bytes) !== $size) {
				throw new \RuntimeException('Could not read generated fitment ZIP archive');
			}
			return $bytes;
		} finally {
			if ($zip instanceof \ZipArchive) {
				@$zip->close();
			}
			@unlink($path);
		}
	}

	/** @param array<string,mixed> $pack */
	public function fileName(array $pack): string {
		$v = $this->validator->validate($pack);
		return (string)$v['pack']['id'] . '-' . (string)$v['pack']['version'] . '.fitment.zip';
	}

	private function requireZip(): void {
		if (!class_exists(\ZipArchive::class)) {
			throw new \RuntimeException('Fitment ZIP interchange requires the PHP zip extension');
		}
	}

	private function safeRootName(string $name): bool {
		return $name !== ''
			&& !str_contains($name, "\0")
			&& !str_contains($name, '/')
			&& !str_contains($name, '\\')
			&& $name !== '.'
			&& $name !== '..';
	}

	private function rejectSymlink(\ZipArchive $zip, int $index, string $name): void {
		$opsys = 0;
		$attributes = 0;
		if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes, \ZipArchive::FL_UNCHANGED)) {
			throw new ValidationException("Fitment ZIP bundle entry {$name} external attributes could not be inspected");
		}
		if ($opsys === \ZipArchive::OPSYS_UNIX) {
			$mode = ($attributes >> 16) & 0170000;
			if ($mode === 0120000) {
				throw new ValidationException("Fitment ZIP bundle entry {$name} must not be a symlink");
			}
		}
	}
}
