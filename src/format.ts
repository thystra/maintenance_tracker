/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Display helpers shared by the views. All user-facing text goes through t()/n().

import type { AssetClass, MaintenanceDueState, MaintenanceForecastState, MeterDimension, WorkSchedule } from './types.ts'
import type { WorkspaceRole } from './workspaceTypes.ts'

import { mdiCarOutline, mdiEngineOutline, mdiHammerWrench, mdiHomeOutline, mdiHvac, mdiMapMarkerOutline, mdiMedicalBag, mdiShapeOutline, mdiTruckTrailer, mdiWashingMachine } from '@mdi/js'
import { getCanonicalLocale } from '@nextcloud/l10n'
import { n, t } from './l10n.ts'

/**
 * Human-readable message for a failed request, preferring the OCS message.
 *
 * @param reason The caught value.
 */
export function errorMessage(reason: unknown): string {
	const message = (reason as { response?: { data?: { ocs?: { meta?: { message?: string } } } } })?.response?.data?.ocs?.meta?.message
	if (typeof message === 'string' && message !== '') {
		return message
	}
	return reason instanceof Error && reason.message !== '' ? reason.message : t('maintenance_tracker', 'The request could not be completed.')
}

/**
 * Locale date for an ISO date or timestamp.
 *
 * @param value ISO 8601 date (YYYY-MM-DD) or timestamp.
 */
export function formatDate(value: string | null | undefined): string {
	if (!value) {
		return ''
	}
	const date = value.length === 10 ? new Date(`${value}T12:00:00Z`) : new Date(value)
	if (Number.isNaN(date.getTime())) {
		return value
	}
	try {
		return date.toLocaleDateString(getCanonicalLocale(), { year: 'numeric', month: 'short', day: 'numeric', timeZone: value.length === 10 ? 'UTC' : undefined })
	} catch {
		return value.slice(0, 10)
	}
}

/** Local date-time for a datetime-local input, rounded to minutes. */
export function localNow(): string {
	const now = new Date()
	return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}

/** Today's local date as YYYY-MM-DD. */
export function today(): string {
	return localNow().slice(0, 10)
}

/**
 * Convert a datetime-local value to an ISO 8601 UTC timestamp without milliseconds.
 *
 * @param local Value of an input type="datetime-local".
 */
export function toIso(local: string): string {
	return new Date(local).toISOString().replace(/\.\d{3}Z$/, 'Z')
}

export function roleLabel(role: WorkspaceRole): string {
	return ({
		owner: t('maintenance_tracker', 'Owner'),
		manager: t('maintenance_tracker', 'Manager'),
		contributor: t('maintenance_tracker', 'Contributor'),
		viewer: t('maintenance_tracker', 'Viewer'),
	} satisfies Record<WorkspaceRole, string>)[role]
}

export function assetClassLabel(value: AssetClass): string {
	return ({
		vehicle: t('maintenance_tracker', 'Vehicle'),
		trailer: t('maintenance_tracker', 'Trailer'),
		building: t('maintenance_tracker', 'Building'),
		equipment: t('maintenance_tracker', 'Equipment'),
		appliance: t('maintenance_tracker', 'Appliance'),
		system: t('maintenance_tracker', 'System'),
		tool: t('maintenance_tracker', 'Tool'),
		medical_device: t('maintenance_tracker', 'Medical device'),
		location: t('maintenance_tracker', 'Location'),
		other: t('maintenance_tracker', 'Other'),
	} satisfies Record<AssetClass, string>)[value]
}

/** Material Design icon path per asset class, for list rows. */
export const assetClassIcons: Record<AssetClass, string> = {
	vehicle: mdiCarOutline,
	trailer: mdiTruckTrailer,
	building: mdiHomeOutline,
	equipment: mdiEngineOutline,
	appliance: mdiWashingMachine,
	system: mdiHvac,
	tool: mdiHammerWrench,
	medical_device: mdiMedicalBag,
	location: mdiMapMarkerOutline,
	other: mdiShapeOutline,
}

export const assetClasses: AssetClass[] = ['vehicle', 'trailer', 'building', 'equipment', 'appliance', 'system', 'tool', 'medical_device', 'location', 'other']

export function dueStateLabel(state: MaintenanceDueState): string {
	return ({
		inactive: t('maintenance_tracker', 'Inactive'),
		unscheduled: t('maintenance_tracker', 'Unscheduled'),
		baseline_required: t('maintenance_tracker', 'Not done yet'),
		upcoming: t('maintenance_tracker', 'Upcoming'),
		due: t('maintenance_tracker', 'Due'),
		overdue: t('maintenance_tracker', 'Overdue'),
		unknown: t('maintenance_tracker', 'Needs a reading'),
	} satisfies Record<MaintenanceDueState, string>)[state]
}

export function forecastLabel(state: MaintenanceForecastState): string {
	return ({
		inactive: t('maintenance_tracker', 'Inactive'),
		unscheduled: t('maintenance_tracker', 'Unscheduled'),
		setup_required: t('maintenance_tracker', 'Needs setup'),
		not_due: t('maintenance_tracker', 'OK'),
		due_soon: t('maintenance_tracker', 'Due soon'),
		due: t('maintenance_tracker', 'Due'),
		overdue: t('maintenance_tracker', 'Overdue'),
		blocked: t('maintenance_tracker', 'Blocked'),
	} satisfies Record<MaintenanceForecastState, string>)[state]
}

/**
 * Severity class used for badges: error, warning, info or ok.
 *
 * @param state Due or forecast state.
 */
export function severity(state: string): 'error' | 'warning' | 'info' | 'ok' {
	if (state === 'overdue') {
		return 'error'
	}
	if (state === 'due' || state === 'due_soon') {
		return 'warning'
	}
	if (state === 'not_due' || state === 'upcoming') {
		return 'ok'
	}
	return 'info'
}

/**
 * When a task is due, in words.
 *
 * @param dueOn Due date (YYYY-MM-DD) or null.
 * @param remainingDays Days left (negative when overdue) or null.
 */
export function dueText(dueOn: string | null | undefined, remainingDays: number | null | undefined): string {
	if (!dueOn) {
		return ''
	}
	const remaining = remainingDays ?? 0
	if (remaining === 0) {
		return t('maintenance_tracker', 'Due today')
	}
	return remaining > 0
		? n('maintenance_tracker', 'Due {date} (in %n day)', 'Due {date} (in %n days)', remaining, { date: formatDate(dueOn) })
		: n('maintenance_tracker', 'Due {date} (%n day overdue)', 'Due {date} (%n days overdue)', Math.abs(remaining), { date: formatDate(dueOn) })
}

export function calendarUnitLabel(unit: string, value: number): string {
	switch (unit) {
		case 'day': return n('maintenance_tracker', 'every %n day', 'every %n days', value)
		case 'week': return n('maintenance_tracker', 'every %n week', 'every %n weeks', value)
		case 'month': return n('maintenance_tracker', 'every %n month', 'every %n months', value)
		case 'year': return n('maintenance_tracker', 'every %n year', 'every %n years', value)
		default: return t('maintenance_tracker', 'every {value} {unit}', { value, unit })
	}
}

/**
 * Plain-language schedule, e.g. "every 6 months or every 5000 mi".
 *
 * @param schedule Work definition schedule.
 */
export function scheduleLabel(schedule: WorkSchedule): string {
	if (schedule === 'none') {
		return t('maintenance_tracker', 'No schedule')
	}
	return schedule.rules.map((rule) => {
		if (rule.type === 'calendar') {
			return calendarUnitLabel(rule.interval.unit, rule.interval.value)
		}
		if (rule.type === 'business_days') {
			return n('maintenance_tracker', 'every %n business day', 'every %n business days', rule.interval.value)
		}
		return t('maintenance_tracker', 'every {value} {unit}', { value: rule.interval.value, unit: rule.interval.unit })
	}).join(t('maintenance_tracker', ' or '))
}

export function meterUnits(dimension: MeterDimension): string[] {
	if (dimension === 'distance') {
		return ['mi', 'km', 'm', 'mm']
	}
	if (dimension === 'runtime') {
		return ['hour', 'min', 's']
	}
	return ['use']
}

export function dimensionLabel(dimension: MeterDimension): string {
	return ({
		distance: t('maintenance_tracker', 'Distance (odometer)'),
		runtime: t('maintenance_tracker', 'Running time (hour meter)'),
		usage_count: t('maintenance_tracker', 'Usage count (cycles, uses)'),
	} satisfies Record<MeterDimension, string>)[dimension]
}

/**
 * A unique-enough key such as "oil_change_k3f9" derived from a title, for
 * APIs that need a stable machine key. People never type keys.
 *
 * @param text Human title.
 */
export function keyFrom(text: string): string {
	const base = text.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40)
	const suffix = Math.random().toString(36).slice(2, 6).padEnd(4, '0')
	return base === '' ? `item_${suffix}` : `${base}_${suffix}`
}

export const partUnits = ['each', 'pair', 'set', 'kit', 'ml', 'l', 'fl_oz', 'qt', 'gal', 'g', 'kg', 'oz', 'lb', 'mm', 'cm', 'm', 'in', 'ft']
