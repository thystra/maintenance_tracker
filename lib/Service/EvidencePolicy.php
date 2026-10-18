<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

/** Pure evidence rules: kinds, kind/MIME compatibility, link targets, names. */
final class EvidencePolicy {
	public const KINDS = ['photo', 'video', 'receipt', 'invoice', 'document', 'other'];
	public const TARGET_TYPES = ['asset', 'activity', 'cost', 'fuel'];
	public const MAX_LINKS = 20;
	/** Files up to this size are hashed (SHA-256) at attach time. */
	public const HASH_LIMIT_BYTES = 64 * 1024 * 1024;

	public static function kind(mixed $kind): string {
		if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
			throw new ValidationException('kind must be one of ' . implode(', ', self::KINDS));
		}
		return $kind;
	}

	public static function targetType(mixed $type): string {
		if (!is_string($type) || !in_array($type, self::TARGET_TYPES, true)) {
			throw new ValidationException('targetType must be one of ' . implode(', ', self::TARGET_TYPES));
		}
		return $type;
	}

	/** Nextcloud-detected MIME must fit the declared evidence kind. */
	public static function assertMimeFits(string $kind, string $mime): void {
		$mime = strtolower($mime);
		if ($mime === 'httpd/unix-directory') {
			throw new ValidationException('Evidence must be a file, not a folder');
		}
		$image = str_starts_with($mime, 'image/');
		$ok = match ($kind) {
			'photo' => $image,
			'video' => str_starts_with($mime, 'video/'),
			'receipt', 'invoice' => $image || $mime === 'application/pdf' || $mime === 'text/plain',
			default => true,
		};
		if (!$ok) {
			throw new ValidationException("A {$kind} must be " . match ($kind) {
				'photo' => 'an image',
				'video' => 'a video',
				default => 'an image, PDF, or plain-text file',
			});
		}
	}

	/** Bounded display title; defaults to the file name chosen by the uploader. */
	public static function defaultTitle(string $kind): string {
		return ucfirst($kind);
	}

	public static function title(mixed $title, string $fallback): string {
		if ($title === null || $title === '') {
			$title = $fallback;
		}
		return CatalogInput::text($title, 'title', 255);
	}

	/**
	 * Collision-resistant upload name: date + short random id + sanitized
	 * original name. Clients use the same scheme; the server never trusts a
	 * client path for authorization, only for locating the file it then verifies.
	 */
	public static function uploadName(string $original, string $date, string $random): string {
		$dot = strrpos($original, '.');
		$ext = $dot === false ? '' : strtolower(substr($original, $dot + 1));
		$ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) === 1 ? '.' . $ext : '';
		$base = EvidenceFolderService::segment($dot === false ? $original : substr($original, 0, $dot), 'evidence');
		return $date . '-' . substr(preg_replace('/[^a-z0-9]/', '', strtolower($random)) ?? '', 0, 8) . '-' . $base . $ext;
	}
}
