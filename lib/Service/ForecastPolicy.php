<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

final class ForecastPolicy {
	/**
	 * @param array<string,mixed> $status
	 * @param array{calendarLeadDays:int,meterLeadPercent:int,revision:int,source:string} $policy
	 * @return array{state:string,materialize:bool,triggerRulePosition:?int,reason:?string}
	 */
	public function classify(array $status, array $policy): array {
		$state = (string)($status['state'] ?? 'unknown');
		if ($state === 'overdue' || $state === 'due') {
			return [
				'state' => $state,
				'materialize' => true,
				'triggerRulePosition' => isset($status['triggerRulePosition']) ? (int)$status['triggerRulePosition'] : null,
				'reason' => null,
			];
		}
		if ($state === 'baseline_required') {
			return ['state' => 'setup_required', 'materialize' => false, 'triggerRulePosition' => null, 'reason' => 'no_completed_activity'];
		}
		if ($state === 'unknown') {
			return ['state' => 'blocked', 'materialize' => false, 'triggerRulePosition' => null, 'reason' => (string)($status['reason'] ?? 'rule_data_incomplete')];
		}
		if ($state === 'inactive' || $state === 'unscheduled') {
			return ['state' => $state, 'materialize' => false, 'triggerRulePosition' => null, 'reason' => null];
		}
		if ($state !== 'upcoming') {
			return ['state' => 'blocked', 'materialize' => false, 'triggerRulePosition' => null, 'reason' => 'unsupported_due_state'];
		}

		foreach (($status['rules'] ?? []) as $rule) {
			if (!is_array($rule) || ($rule['state'] ?? null) !== 'upcoming') {
				continue;
			}
			$position = isset($rule['position']) ? (int)$rule['position'] : null;
			if (isset($rule['remainingDays']) && is_int($rule['remainingDays'])) {
				if ($rule['remainingDays'] >= 0 && $rule['remainingDays'] <= $policy['calendarLeadDays']) {
					return ['state' => 'due_soon', 'materialize' => true, 'triggerRulePosition' => $position, 'reason' => 'calendar_lead'];
				}
				continue;
			}
			$remaining = $rule['remainingCanonicalValue'] ?? null;
			$interval = is_array($rule['interval'] ?? null) ? ($rule['interval']['canonicalValue'] ?? null) : null;
			if (is_int($remaining) && is_int($interval) && $remaining >= 0 && $interval > 0) {
				if (($remaining * 100) <= ($interval * $policy['meterLeadPercent'])) {
					return ['state' => 'due_soon', 'materialize' => true, 'triggerRulePosition' => $position, 'reason' => 'meter_lead'];
				}
			}
		}

		return ['state' => 'not_due', 'materialize' => false, 'triggerRulePosition' => null, 'reason' => null];
	}
}
