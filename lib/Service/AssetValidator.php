<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use DateTimeImmutable;
use OCA\MaintenanceTracker\Exception\ValidationException;

final class AssetValidator {
	private const CREATE_FIELDS = [
		'uuid',
		'category',
		'name',
		'manufacturer',
		'model',
		'modelYear',
		'serialNumber',
		'notes',
		'status',
		'profileKey',
		'profileVersion',
		'acquiredOn',
		'purchasePriceMinor',
		'currency',
	];

	private const PATCH_FIELDS = [
		'category',
		'name',
		'manufacturer',
		'model',
		'modelYear',
		'serialNumber',
		'notes',
		'status',
		'profileKey',
		'profileVersion',
		'acquiredOn',
		'purchasePriceMinor',
		'currency',
	];

	private const STATUSES = ['active', 'suppressed', 'retired'];

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function forCreate(array $input): array {
		$this->rejectUnknownFields($input, self::CREATE_FIELDS);

		if (!array_key_exists('name', $input)) {
			throw new ValidationException('The asset name is required');
		}

		$result = [
			'uuid' => $this->uuid($input['uuid'] ?? null),
			'category' => $this->category($input['category'] ?? 'other'),
			'name' => $this->requiredText($input['name'], 'name', 255),
			'manufacturer' => $this->optionalText($input['manufacturer'] ?? null, 'manufacturer', 255),
			'model' => $this->optionalText($input['model'] ?? null, 'model', 255),
			'modelYear' => $this->modelYear($input['modelYear'] ?? null),
			'serialNumber' => $this->optionalText($input['serialNumber'] ?? null, 'serialNumber', 255),
			'notes' => $this->optionalText($input['notes'] ?? null, 'notes', 20000, true),
			'status' => $this->status($input['status'] ?? 'active'),
			'profileKey' => $this->profileKey($input['profileKey'] ?? null),
			'profileVersion' => $this->profileVersion($input['profileVersion'] ?? null),
			'acquiredOn' => $this->date($input['acquiredOn'] ?? null, 'acquiredOn'),
			'purchasePriceMinor' => $this->moneyMinor($input['purchasePriceMinor'] ?? null),
			'currency' => $this->currency($input['currency'] ?? null),
		];

		$this->validateRelationships($result);

		return $result;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function forPatch(array $input): array {
		$this->rejectUnknownFields($input, self::PATCH_FIELDS);
		if ($input === []) {
			throw new ValidationException('At least one asset field must be supplied');
		}

		$result = [];
		foreach ($input as $field => $value) {
			$result[$field] = match ($field) {
				'category' => $this->category($value),
				'name' => $this->requiredText($value, 'name', 255),
				'manufacturer' => $this->optionalText($value, 'manufacturer', 255),
				'model' => $this->optionalText($value, 'model', 255),
				'modelYear' => $this->modelYear($value),
				'serialNumber' => $this->optionalText($value, 'serialNumber', 255),
				'notes' => $this->optionalText($value, 'notes', 20000, true),
				'status' => $this->status($value),
				'profileKey' => $this->profileKey($value),
				'profileVersion' => $this->profileVersion($value),
				'acquiredOn' => $this->date($value, 'acquiredOn'),
				'purchasePriceMinor' => $this->moneyMinor($value),
				'currency' => $this->currency($value),
				default => throw new ValidationException("Unsupported field: {$field}"),
			};
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function validateRelationships(array $values): void {
		$profileKey = $values['profileKey'] ?? null;
		$profileVersion = $values['profileVersion'] ?? null;
		if (($profileKey === null) !== ($profileVersion === null)) {
			throw new ValidationException('profileKey and profileVersion must be set or cleared together');
		}

		$price = $values['purchasePriceMinor'] ?? null;
		$currency = $values['currency'] ?? null;
		if (($price === null) !== ($currency === null)) {
			throw new ValidationException('purchasePriceMinor and currency must be set or cleared together');
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @param list<string> $allowed
	 */
	private function rejectUnknownFields(array $input, array $allowed): void {
		$unknown = array_values(array_diff(array_keys($input), $allowed));
		if ($unknown !== []) {
			throw new ValidationException('Unknown asset fields: ' . implode(', ', $unknown));
		}
	}

	private function uuid(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value)) {
			throw new ValidationException('uuid must be a string');
		}

		$value = strtolower(trim($value));
		if (!UuidGenerator::isValid($value)) {
			throw new ValidationException('uuid must be an RFC 4122 version 4 UUID');
		}

		return $value;
	}

	private function category(mixed $value): string {
		$category = $this->requiredText($value, 'category', 64);
		if (preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $category) !== 1) {
			throw new ValidationException('category must be a lowercase key using letters, digits, underscores, or hyphens');
		}

		return $category;
	}

	private function status(mixed $value): string {
		$status = $this->requiredText($value, 'status', 16);
		if (!in_array($status, self::STATUSES, true)) {
			throw new ValidationException('status must be active, suppressed, or retired');
		}

		return $status;
	}

	private function modelYear(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		if (is_string($value) && ctype_digit($value)) {
			$value = (int)$value;
		}
		if (!is_int($value) || $value < 1000 || $value > 9999) {
			throw new ValidationException('modelYear must be a four-digit year');
		}

		return $value;
	}

	private function profileKey(mixed $value): ?string {
		$key = $this->optionalText($value, 'profileKey', 160);
		if ($key !== null && preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $key) !== 1) {
			throw new ValidationException('profileKey contains unsupported characters');
		}

		return $key;
	}

	private function profileVersion(mixed $value): ?string {
		$version = $this->optionalText($value, 'profileVersion', 32);
		if ($version !== null && preg_match(
			'/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/D',
			$version,
		) !== 1) {
			throw new ValidationException('profileVersion must be a semantic version');
		}

		return $version;
	}

	private function date(mixed $value, string $field): ?string {
		$date = $this->optionalText($value, $field, 10);
		if ($date === null) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw new ValidationException("{$field} must use YYYY-MM-DD");
		}

		return $date;
	}

	private function moneyMinor(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		if (is_string($value) && ctype_digit($value)) {
			$value = (int)$value;
		}
		if (!is_int($value) || $value < 0 || $value > 9_000_000_000_000_000) {
			throw new ValidationException('purchasePriceMinor must be a non-negative 64-bit integer');
		}

		return $value;
	}

	private function currency(mixed $value): ?string {
		$currency = $this->optionalText($value, 'currency', 3);
		if ($currency === null) {
			return null;
		}

		$currency = strtoupper($currency);
		if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
			throw new ValidationException('currency must be a three-letter ISO 4217 code');
		}

		return $currency;
	}

	private function requiredText(
		mixed $value,
		string $field,
		int $maximumLength,
		bool $allowNewlines = false,
	): string {
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be a string");
		}

		$value = trim($value);
		if ($value === '') {
			throw new ValidationException("{$field} cannot be empty");
		}
		$this->validateText($value, $field, $maximumLength, $allowNewlines);

		return $value;
	}

	private function optionalText(
		mixed $value,
		string $field,
		int $maximumLength,
		bool $allowNewlines = false,
	): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be a string or null");
		}

		$value = trim($value);
		if ($value === '') {
			return null;
		}
		$this->validateText($value, $field, $maximumLength, $allowNewlines);

		return $value;
	}

	private function validateText(
		string $value,
		string $field,
		int $maximumLength,
		bool $allowNewlines,
	): void {
		if (mb_strlen($value) > $maximumLength) {
			throw new ValidationException("{$field} exceeds {$maximumLength} characters");
		}

		$pattern = $allowNewlines
			? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'
			: '/[\x00-\x1F\x7F]/u';
		if (preg_match($pattern, $value) === 1) {
			throw new ValidationException("{$field} contains unsupported control characters");
		}
	}
}
