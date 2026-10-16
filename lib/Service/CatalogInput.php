<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

/**
 * Shared bounded input normalization for the v0.1.10 parts, vendor, link, and
 * cost surfaces. Every helper rejects rather than coerces unexpected types.
 */
final class CatalogInput {
	/** @param array<string,mixed> $input @param list<string> $allowed */
	public static function known(array $input, array $allowed, string $label): void {
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) {
			throw new ValidationException('Unknown ' . $label . ' fields: ' . implode(', ', $unknown));
		}
	}

	public static function text(mixed $value, string $field, int $max): string {
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be a string");
		}
		$value = trim($value);
		if ($value === '') {
			throw new ValidationException("{$field} cannot be empty");
		}
		if (mb_strlen($value) > $max) {
			throw new ValidationException("{$field} exceeds {$max} characters");
		}
		if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
			throw new ValidationException("{$field} contains unsupported control characters");
		}
		return $value;
	}

	public static function optionalText(mixed $value, string $field, int $max, bool $newlines = false): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be a string");
		}
		$value = trim($value);
		if (mb_strlen($value) > $max) {
			throw new ValidationException("{$field} exceeds {$max} characters");
		}
		$pattern = $newlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
		if (preg_match('//u', $value) !== 1 || preg_match($pattern, $value) === 1) {
			throw new ValidationException("{$field} contains unsupported control characters");
		}
		return $value === '' ? null : $value;
	}

	public static function uuid(mixed $value, string $field): string {
		if (!is_string($value) || !UuidGenerator::isValid(strtolower(trim($value)))) {
			throw new ValidationException("{$field} must be an RFC 4122 version 4 UUID");
		}
		return strtolower(trim($value));
	}

	public static function optionalUuid(mixed $value, string $field): ?string {
		return $value === null || $value === '' ? null : self::uuid($value, $field);
	}

	public static function bool(mixed $value, string $field): bool {
		if (!is_bool($value)) {
			throw new ValidationException("{$field} must be a boolean");
		}
		return $value;
	}

	/** Strict calendar date in YYYY-MM-DD form. */
	public static function date(mixed $value, string $field): string {
		if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new ValidationException("{$field} must be a calendar date in YYYY-MM-DD form");
		}
		return $value;
	}

	public static function formatTimestamp(int $value): string {
		return gmdate('Y-m-d\TH:i:s\Z', $value);
	}
}
