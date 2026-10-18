/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DistanceUnit, EconomyUnit, FuelPeriod, FuelType, VolumeUnit } from './fuelTypes.ts'

import { getCanonicalLocale } from '@nextcloud/l10n'
import { t } from './l10n.ts'

export function economyUnitLabel(unit: EconomyUnit): string {
	return ({
		mpg: t('maintenance_tracker', 'MPG'),
		km_l: t('maintenance_tracker', 'km/L'),
		l_100km: t('maintenance_tracker', 'L/100 km'),
	})[unit]
}

export function distanceUnitLabel(unit: DistanceUnit): string {
	return unit === 'mi' ? t('maintenance_tracker', 'mi') : t('maintenance_tracker', 'km')
}

export function volumeUnitLabel(unit: VolumeUnit): string {
	return unit === 'gal' ? t('maintenance_tracker', 'gal') : t('maintenance_tracker', 'L')
}

export function fuelTypeLabel(type: FuelType): string {
	return ({
		gasoline: t('maintenance_tracker', 'Gasoline'),
		diesel: t('maintenance_tracker', 'Diesel'),
		e85: t('maintenance_tracker', 'E85'),
		lpg: t('maintenance_tracker', 'LPG'),
		cng: t('maintenance_tracker', 'CNG'),
		other: t('maintenance_tracker', 'Other fuel'),
	})[type]
}

export function periodLabel(period: FuelPeriod): string {
	return ({
		'30d': t('maintenance_tracker', 'Last 30 days'),
		'90d': t('maintenance_tracker', 'Last 90 days'),
		'365d': t('maintenance_tracker', 'Last 365 days'),
		year: t('maintenance_tracker', 'This year'),
		all: t('maintenance_tracker', 'All time'),
		custom: t('maintenance_tracker', 'Custom period'),
	})[period]
}

/**
 * Locale-formatted number, or an en dash when there is no value.
 *
 * @param value The number.
 * @param digits Fraction digits.
 */
export function formatNumber(value: number | null | undefined, digits = 1): string {
	if (value === null || value === undefined || !Number.isFinite(value)) {
		return '–'
	}
	try {
		return value.toLocaleString(getCanonicalLocale(), { minimumFractionDigits: digits, maximumFractionDigits: digits })
	} catch {
		return value.toFixed(digits)
	}
}

/**
 * Economy with its unit, for example "28.4 MPG".
 *
 * @param value Economy value.
 * @param unit Economy unit.
 */
export function formatEconomy(value: number | null | undefined, unit: EconomyUnit): string {
	if (value === null || value === undefined) {
		return '–'
	}
	return t('maintenance_tracker', '{value} {unit}', { value: formatNumber(value, 1), unit: economyUnitLabel(unit) })
}

export const ECONOMY_UNITS: EconomyUnit[] = ['mpg', 'km_l', 'l_100km']
export const FUEL_TYPES: FuelType[] = ['gasoline', 'diesel', 'e85', 'lpg', 'cng', 'other']
export const PERIODS: FuelPeriod[] = ['30d', '90d', '365d', 'year', 'all', 'custom']
