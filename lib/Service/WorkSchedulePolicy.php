<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

final class WorkSchedulePolicy {
	private const WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
	private const CALENDAR_UNITS = ['day', 'week', 'month', 'year'];

	public function __construct(private MeterValueConverter $values) {
	}

	/**
	 * Missing schedule is rejected by the caller before this method. This method
	 * therefore never supplies an implicit default.
	 *
	 * @param callable(string): array{id:int,assetId:int,dimension:string} $resolveMeter
	 * @return array{type:string, combination:?string, rules:list<array<string,mixed>>}
	 */
	public function normalize(mixed $schedule, int $assetId, callable $resolveMeter): array {
		if ($schedule === 'none') {
			return ['type' => 'none', 'combination' => null, 'rules' => []];
		}
		if (!is_array($schedule) || array_is_list($schedule)) {
			throw new ValidationException('schedule must be "none" or a schedule policy object');
		}
		$this->assertKnownFields($schedule, ['combination', 'rules'], 'schedule');
		if (!array_key_exists('combination', $schedule) || !array_key_exists('rules', $schedule)) {
			throw new ValidationException('A schedule policy requires combination and rules');
		}
		if ($schedule['combination'] !== 'any') {
			throw new ValidationException('schedule.combination currently supports only "any"');
		}
		if (!is_array($schedule['rules']) || !array_is_list($schedule['rules']) || count($schedule['rules']) < 1 || count($schedule['rules']) > 8) {
			throw new ValidationException('schedule.rules must contain between 1 and 8 rules');
		}

		$rules = [];
		foreach ($schedule['rules'] as $position => $rule) {
			if (!is_array($rule) || array_is_list($rule)) {
				throw new ValidationException('Each schedule rule must be an object');
			}
			$type = $rule['type'] ?? null;
			if (!is_string($type)) {
				throw new ValidationException('Each schedule rule requires a type');
			}
			$rules[] = match ($type) {
				'calendar' => $this->calendarRule($rule, $position),
				'business_days' => $this->businessDaysRule($rule, $position),
				'meter' => $this->meterRule($rule, $position, $assetId, $resolveMeter),
				default => throw new ValidationException('Unsupported schedule rule type'),
			};
		}

		return ['type' => 'rules', 'combination' => 'any', 'rules' => $rules];
	}

	/** @return array<string,mixed> */
	private function calendarRule(array $rule, int $position): array {
		$this->assertKnownFields($rule, ['type', 'interval'], 'calendar rule');
		$interval = $this->intervalObject($rule['interval'] ?? null);
		$value = $this->positiveInteger($interval['value'] ?? null, 'calendar interval value', 10000);
		$unit = $interval['unit'] ?? null;
		if (!is_string($unit) || !in_array($unit, self::CALENDAR_UNITS, true)) {
			throw new ValidationException('Calendar interval unit must be day, week, month, or year');
		}
		return [
			'position' => $position,
			'type' => 'calendar',
			'intervalValue' => (string)$value,
			'intervalUnit' => $unit,
			'canonicalValue' => null,
			'meterId' => null,
			'weekdayMask' => null,
		];
	}

	/** @return array<string,mixed> */
	private function businessDaysRule(array $rule, int $position): array {
		$this->assertKnownFields($rule, ['type', 'interval', 'weekdays'], 'business-days rule');
		$interval = $this->intervalObject($rule['interval'] ?? null);
		$value = $this->positiveInteger($interval['value'] ?? null, 'business-day interval value', 1000000);
		if (($interval['unit'] ?? null) !== 'business_day') {
			throw new ValidationException('Business-day interval unit must be business_day');
		}
		$weekdays = $rule['weekdays'] ?? null;
		if (!is_array($weekdays) || !array_is_list($weekdays) || $weekdays === [] || count($weekdays) > 7) {
			throw new ValidationException('business_days weekdays must contain 1 to 7 explicit weekdays');
		}
		$mask = 0;
		foreach ($weekdays as $weekday) {
			if (!is_string($weekday) || !in_array($weekday, self::WEEKDAYS, true)) {
				throw new ValidationException('Unsupported business-day weekday');
			}
			$bit = array_search($weekday, self::WEEKDAYS, true);
			$mask |= 1 << $bit;
		}
		if (count(array_unique($weekdays)) !== count($weekdays)) {
			throw new ValidationException('business_days weekdays must be unique');
		}
		return [
			'position' => $position,
			'type' => 'business_days',
			'intervalValue' => (string)$value,
			'intervalUnit' => 'business_day',
			'canonicalValue' => null,
			'meterId' => null,
			'weekdayMask' => $mask,
		];
	}

	/** @param callable(string): array{id:int,assetId:int,dimension:string} $resolveMeter @return array<string,mixed> */
	private function meterRule(array $rule, int $position, int $assetId, callable $resolveMeter): array {
		$this->assertKnownFields($rule, ['type', 'meterUuid', 'interval'], 'meter rule');
		$meterUuid = $rule['meterUuid'] ?? null;
		if (!is_string($meterUuid) || !UuidGenerator::isValid(strtolower(trim($meterUuid)))) {
			throw new ValidationException('meter rule meterUuid must be an RFC 4122 version 4 UUID');
		}
		$meter = $resolveMeter(strtolower(trim($meterUuid)));
		if ($meter['assetId'] !== $assetId) {
			throw new ValidationException('Scheduled meter must belong to the same asset as the work definition');
		}
		$interval = $this->intervalObject($rule['interval'] ?? null);
		$converted = $this->values->toCanonical($meter['dimension'], $interval['value'], $interval['unit']);
		if ($converted['canonicalValue'] < 1) {
			throw new ValidationException('Meter schedule interval must be greater than zero');
		}
		return [
			'position' => $position,
			'type' => 'meter',
			'intervalValue' => $converted['originalValue'],
			'intervalUnit' => $converted['originalUnit'],
			'canonicalValue' => $converted['canonicalValue'],
			'meterId' => $meter['id'],
			'weekdayMask' => null,
		];
	}

	/** @return array<string,mixed> */
	private function intervalObject(mixed $interval): array {
		if (!is_array($interval) || array_is_list($interval)) {
			throw new ValidationException('Schedule rule interval must be an object');
		}
		$this->assertKnownFields($interval, ['value', 'unit'], 'schedule interval');
		if (!array_key_exists('value', $interval) || !array_key_exists('unit', $interval)) {
			throw new ValidationException('Schedule interval requires value and unit');
		}
		return $interval;
	}

	private function positiveInteger(mixed $value, string $field, int $maximum): int {
		if (!is_int($value) || $value < 1 || $value > $maximum) {
			throw new ValidationException("{$field} must be an integer between 1 and {$maximum}");
		}
		return $value;
	}

	/** @param list<string> $allowed */
	private function assertKnownFields(array $input, array $allowed, string $label): void {
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) {
			throw new ValidationException('Unknown ' . $label . ' fields: ' . implode(', ', $unknown));
		}
	}

	/** @return list<string> */
	public function weekdaysFromMask(int $mask): array {
		$weekdays = [];
		foreach (self::WEEKDAYS as $bit => $weekday) {
			if (($mask & (1 << $bit)) !== 0) {
				$weekdays[] = $weekday;
			}
		}
		return $weekdays;
	}
}
