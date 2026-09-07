<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Tests\Unit\Service;

use OCA\MaintenanceTracker\Service\ForecastPolicy;
use PHPUnit\Framework\TestCase;

final class ForecastPolicyTest extends TestCase {
	private ForecastPolicy $policy;

	protected function setUp(): void {
		$this->policy = new ForecastPolicy();
	}

	public function testDueAndOverdueAlwaysMaterialize(): void {
		foreach (['due', 'overdue'] as $state) {
			$result = $this->policy->classify(['state' => $state, 'triggerRulePosition' => 2], $this->defaults());
			self::assertSame($state, $result['state']);
			self::assertTrue($result['materialize']);
			self::assertSame(2, $result['triggerRulePosition']);
		}
	}

	public function testCalendarLeadCreatesDueSoonWithoutChangingDueState(): void {
		$result = $this->policy->classify([
			'state' => 'upcoming',
			'rules' => [[
				'position' => 0,
				'state' => 'upcoming',
				'remainingDays' => 14,
			]],
		], $this->defaults());
		self::assertSame('due_soon', $result['state']);
		self::assertTrue($result['materialize']);
		self::assertSame('calendar_lead', $result['reason']);
	}

	public function testMeterLeadUsesPercentageOfConfiguredInterval(): void {
		$result = $this->policy->classify([
			'state' => 'upcoming',
			'rules' => [[
				'position' => 1,
				'state' => 'upcoming',
				'remainingCanonicalValue' => 500,
				'interval' => ['canonicalValue' => 5000],
			]],
		], $this->defaults());
		self::assertSame('due_soon', $result['state']);
		self::assertTrue($result['materialize']);
		self::assertSame('meter_lead', $result['reason']);
	}

	public function testOutsideLeadRemainsNotDue(): void {
		$result = $this->policy->classify([
			'state' => 'upcoming',
			'rules' => [
				['position' => 0, 'state' => 'upcoming', 'remainingDays' => 15],
				['position' => 1, 'state' => 'upcoming', 'remainingCanonicalValue' => 501, 'interval' => ['canonicalValue' => 5000]],
			],
		], $this->defaults());
		self::assertSame('not_due', $result['state']);
		self::assertFalse($result['materialize']);
	}

	public function testIncompleteAndBaselineStatesNeverCreateMaintenanceOccurrence(): void {
		$baseline = $this->policy->classify(['state' => 'baseline_required'], $this->defaults());
		$unknown = $this->policy->classify(['state' => 'unknown', 'reason' => 'meter_current_missing'], $this->defaults());
		self::assertSame('setup_required', $baseline['state']);
		self::assertFalse($baseline['materialize']);
		self::assertSame('blocked', $unknown['state']);
		self::assertFalse($unknown['materialize']);
	}

	/** @return array{calendarLeadDays:int,meterLeadPercent:int,revision:int,source:string} */
	private function defaults(): array {
		return ['calendarLeadDays' => 14, 'meterLeadPercent' => 10, 'revision' => 0, 'source' => 'default'];
	}
}
