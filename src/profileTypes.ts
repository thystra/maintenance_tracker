/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Profile-v2 document as edited in the profile builder. Mirrors
// lib/Service/ProfileValidator.php and schemas/profile-v2.schema.json; the
// server validates every save.

export type MeterDimension = 'distance' | 'runtime' | 'usage_count'

export interface ProfileMeter { key: string, name: string, dimension: MeterDimension, displayUnit: string, monotonic: boolean }
export interface ProfileComponent { key: string, name: string, parentKey?: string, quantity: number, enabledByDefault: boolean, notes?: string, compatiblePartKeys: string[] }
export interface ProfileOffer { label: string, sku?: string, url: string }
export interface ProfilePart { key: string, manufacturer: string, partNumber: string, description: string, offers: ProfileOffer[] }
export interface ProfileWorkGroup { key: string, name: string, description?: string, sortOrder: number }
export interface ProfileInterval { value: number | string, unit: string }
export type ProfileRule
	= | { type: 'calendar', interval: ProfileInterval }
		| { type: 'business_days', interval: ProfileInterval, weekdays: string[] }
		| { type: 'meter', meterKey: string, interval: ProfileInterval }
export type ProfileSchedule = 'none' | { combination: 'any', rules: ProfileRule[] }
export interface ProfileWorkDefinition {
	key: string
	title: string
	kind: string
	groupKey?: string
	componentKey?: string
	enabledByDefault: boolean
	instructions?: string
	notes?: string
	schedule: ProfileSchedule
	compatiblePartKeys: string[]
}
export interface ProfileSpecification { key: string, label: string, value: string | number | boolean, unit?: string, componentKey?: string, regime?: string }
export interface ProfileFuelRegime { key: string, name: string, isDefault: boolean }
export interface ProfileFuel {
	settings?: { distanceUnit?: string, volumeUnit?: string, economyUnit?: string, fuelType?: string }
	regimes: ProfileFuelRegime[]
}

export interface ProfileDocumentV2 {
	schemaVersion: 2
	id: string
	version: string
	name: string
	category: string
	description: string
	dataLicense: string
	provenance: { author: string, sourceUrl?: string, sourceRevision?: string }
	applicability: { manufacturer?: string, model?: string, yearFrom?: number, yearTo?: number, tags?: string[] }
	meters: ProfileMeter[]
	components: ProfileComponent[]
	parts: ProfilePart[]
	workGroups: ProfileWorkGroup[]
	workDefinitions: ProfileWorkDefinition[]
	specifications?: ProfileSpecification[]
	fuel?: ProfileFuel
}

/** Largest profile file accepted (the server enforces the same bound). */
export const MAX_PROFILE_BYTES = 1048576
