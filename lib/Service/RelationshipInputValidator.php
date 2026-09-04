<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use DateTimeImmutable;
use OCA\MaintenanceTracker\Exception\ValidationException;

final class RelationshipInputValidator {
	public function uuid(mixed $value, string $field): string {
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be an RFC 4122 version 4 UUID");
		}
		$value = strtolower(trim($value));
		if (!UuidGenerator::isValid($value)) {
			throw new ValidationException("{$field} must be an RFC 4122 version 4 UUID");
		}
		return $value;
	}

	public function optionalUuid(mixed $value, string $field): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		return $this->uuid($value, $field);
	}

	public function key(mixed $value, string $field, int $max = 64): string {
		$text = $this->text($value, $field, $max, false, false);
		if (preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $text) !== 1) {
			throw new ValidationException("{$field} must be a lowercase semantic key");
		}
		return $text;
	}

	public function optionalKey(mixed $value, string $field, int $max = 64): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		return $this->key($value, $field, $max);
	}

	public function boolean(mixed $value, string $field): bool {
		if (!is_bool($value)) {
			throw new ValidationException("{$field} must be a boolean");
		}
		return $value;
	}

	public function optionalText(mixed $value, string $field, int $max = 20000): ?string {
		return $this->text($value, $field, $max, true, true);
	}

	public function date(mixed $value, string $field): string {
		if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
			throw new ValidationException("{$field} must use YYYY-MM-DD");
		}
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		$errors = DateTimeImmutable::getLastErrors();
		if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
			|| $parsed->format('Y-m-d') !== $value) {
			throw new ValidationException("{$field} must be a real calendar date");
		}
		return $value;
	}

	public function optionalDate(mixed $value, string $field): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		return $this->date($value, $field);
	}

	public function assertDateRange(string $from, ?string $until): void {
		if ($until !== null && $until < $from) {
			throw new ValidationException('effectiveUntil must not be before effectiveFrom');
		}
	}

	private function text(mixed $value, string $field, int $max, bool $newlines, bool $optional): ?string {
		if ($optional && ($value === null || $value === '')) {
			return null;
		}
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be a string");
		}
		$value = trim($value);
		if ($value === '' && !$optional) {
			throw new ValidationException("{$field} cannot be empty");
		}
		if (mb_strlen($value) > $max) {
			throw new ValidationException("{$field} exceeds {$max} characters");
		}
		$pattern = $newlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
		if (preg_match($pattern, $value) === 1) {
			throw new ValidationException("{$field} contains unsupported control characters");
		}
		return $value === '' ? null : $value;
	}
}
