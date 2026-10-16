<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\unit\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\AuditEventCatalog;
use OCA\MaintenanceTracker\Service\OutsideLinkPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutsideLinkPolicyTest extends TestCase {
	/** @return array<string, array{string}> */
	public static function accepted(): array {
		return [
			'plain' => ['https://example.com'],
			'path query fragment' => ['https://shop.example.co.uk/p/OF-123?ref=mt&x=1#specs'],
			'port' => ['https://example.com:8443/item'],
			'percent encoded' => ['https://example.com/a%20b'],
		];
	}

	/** @return array<string, array{mixed}> */
	public static function rejected(): array {
		return [
			'http' => ['http://example.com'],
			'uppercase scheme' => ['HTTPS://example.com'],
			'javascript' => ['javascript:alert(1)'],
			'javascript disguised' => ['https:javascript:alert(1)'],
			'data' => ['data:text/html,<script>alert(1)</script>'],
			'protocol relative' => ['//example.com'],
			'credentials' => ['https://user:pass@example.com/'],
			'user only' => ['https://user@example.com/'],
			'at in path is fine but authority trick' => ['https://example.com@evil.example/'],
			'backslash' => ['https://example.com\\@evil.example/'],
			'whitespace' => ['https://exa mple.com/'],
			'leading space' => [' https://example.com/'],
			'newline' => ["https://example.com/\nx"],
			'unicode host' => ['https://exämple.com/'],
			'ipv6' => ['https://[::1]/'],
			'empty host' => ['https:///path'],
			'too long' => ['https://example.com/' . str_repeat('a', 2049)],
			'not string' => [42],
			'empty' => [''],
		];
	}

	#[DataProvider('accepted')]
	public function testAccepts(string $url): void {
		self::assertSame($url, OutsideLinkPolicy::validate($url, 'url'));
	}

	#[DataProvider('rejected')]
	public function testRejects(mixed $url): void {
		$this->expectException(ValidationException::class);
		OutsideLinkPolicy::validate($url, 'url');
	}

	public function testLinkAuditVocabulary(): void {
		$catalog = new AuditEventCatalog();
		self::assertSame('link.created', $catalog->eventForChange('link', 'upsert', 1));
		self::assertSame('link.archived', $catalog->eventForChange('link', 'delete', 2));
	}
}
