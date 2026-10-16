<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

/**
 * Strict validation for outside (store/vendor) links. Only absolute
 * https:// URLs with an ASCII host and no embedded credentials are accepted;
 * javascript:, data:, protocol-relative, and every non-https scheme fail by
 * construction. Links are never fetched server-side.
 */
final class OutsideLinkPolicy {
	public const MAX_LENGTH = 2048;

	public static function validate(mixed $value, string $field): string {
		if (!is_string($value)) {
			throw new ValidationException("{$field} must be an HTTPS URL");
		}
		$url = $value;
		if ($url === '' || strlen($url) > self::MAX_LENGTH) {
			throw new ValidationException("{$field} must be an HTTPS URL of at most " . self::MAX_LENGTH . ' characters');
		}
		// Printable ASCII only: no whitespace, control characters, backslashes, or
		// Unicode look-alikes that browsers normalize differently than PHP.
		if (preg_match('/^[\x21-\x7E]+$/D', $url) !== 1 || str_contains($url, '\\')) {
			throw new ValidationException("{$field} must be an ASCII HTTPS URL without spaces or backslashes");
		}
		if (strncmp($url, 'https://', 8) !== 0) {
			throw new ValidationException("{$field} must start with https://");
		}
		$authorityEnd = strcspn($url, '/?#', 8);
		$authority = substr($url, 8, $authorityEnd);
		if (str_contains($authority, '@')) {
			throw new ValidationException("{$field} must not contain credentials");
		}
		$parts = parse_url($url);
		if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
			throw new ValidationException("{$field} must be an HTTPS URL");
		}
		$host = $parts['host'];
		if (preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $host) !== 1) {
			throw new ValidationException("{$field} must use an ASCII domain host");
		}
		if ($authority !== $host && !(isset($parts['port']) && $authority === $host . ':' . $parts['port'])) {
			throw new ValidationException("{$field} has an invalid authority");
		}
		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			throw new ValidationException("{$field} must be an HTTPS URL");
		}
		return $url;
	}
}
