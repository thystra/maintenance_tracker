/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type DistanceUnit = 'mi' | 'km'
export type VolumeUnit = 'gal' | 'l'
export type EconomyUnit = 'mpg' | 'km_l' | 'l_100km'
export type FuelType = 'gasoline' | 'diesel' | 'e85' | 'lpg' | 'cng' | 'other'
export type FuelTabMode = 'auto' | 'on' | 'off'
export type FuelPeriod = '30d' | '90d' | '365d' | 'year' | 'all' | 'custom'

export interface FuelSettings {
	assetUuid: string
	fuelTab: FuelTabMode
	shown: boolean
	reason: 'enabled' | 'disabled' | 'vehicle' | 'odometer' | null
	distanceUnit: DistanceUnit
	volumeUnit: VolumeUnit
	economyUnit: EconomyUnit
	fuelType: FuelType | null
	odometerMeter: { uuid: string, name: string, displayUnit: string } | null
	/** The next fill-up creates an "Odometer" meter (no distance meter yet and the caller may add one). */
	createsOdometer: boolean
	revision: number
}

/** An item with the Fuel tab, as listed for the quick "Log fuel" dialog. */
export interface FuelVehicle {
	assetUuid: string
	name: string
	settings: FuelSettings
	regimes: FuelRegime[]
	lastFilledAt: string | null
	lastOdometer: string | null
	lastOdometerUnit: DistanceUnit | null
}

export interface FuelOdometerLink {
	assetUuid: string
	meterUuid: string
	meterName: string
	meterCreated: boolean
	linked: number
	skipped: { uuid: string, reason: string }[]
}

export interface FuelRegime { uuid: string, assetUuid: string, name: string, isDefault: boolean, sort: number, revision: number }

export interface FuelSegment {
	valid: boolean
	invalidReason: 'missed_fill' | 'odometer' | null
	mixed: boolean
	regimeUuid: string | null
	distance: number
	volume: number
	economy: number | null
	fills: number
}

export interface FuelTicket {
	uuid: string
	assetUuid: string
	filledAt: string
	odometer: string
	odometerUnit: DistanceUnit
	odometerMm: number
	meterUuid: string | null
	readingUuid: string | null
	volume: string
	volumeUnit: VolumeUnit
	volumeUl: number
	fullFill: boolean
	missedPrevious: boolean
	fuelType: FuelType | null
	station: string | null
	regimeUuid: string | null
	regimeName: string | null
	costUuid: string | null
	totalCost: string | null
	currency: string | null
	notes: string | null
	revision: number
	segment?: FuelSegment | null
}

export interface FuelTicketList { assetUuid: string, items: FuelTicket[], openFills: number, settings: FuelSettings, regimes: FuelRegime[] }

export interface FuelTicketInput {
	uuid?: string
	filledAt?: string
	odometer?: string
	odometerUnit?: DistanceUnit
	volume?: string
	volumeUnit?: VolumeUnit
	fullFill?: boolean
	missedPrevious?: boolean
	fuelType?: FuelType | null
	station?: string | null
	regimeUuid?: string | null
	totalCost?: string | null
	currency?: string | null
	notes?: string | null
}

export interface FuelAggregate { economy: number | null, best: number | null, worst: number | null, segments: number, distance: number }

/** Fuel cost per distance unit in one currency, over the costed stretches (`distance`). */
export interface FuelCostPerDistance { currency: string, amount: string, distance: number }

export interface FuelStats {
	assetUuid: string
	period: FuelPeriod
	from: string | null
	to: string | null
	economyUnit: EconomyUnit
	distanceUnit: DistanceUnit
	volumeUnit: VolumeUnit
	overall: FuelAggregate
	regimes: Array<FuelAggregate & { regimeUuid: string | null, name: string | null, costPerDistance: FuelCostPerDistance[] }>
	mixedSegments: number
	fills: number
	volume: number
	spend: Array<{ currency: string, amount: string, amountMinor: number }>
	costPerDistance: FuelCostPerDistance[]
	chart: Array<{ date: string, economy: number | null, regimeUuid: string | null, mixed: boolean }>
	invalidSegments: number
	openFills: number
}

export interface FuelSummaryItem { assetUuid: string, name: string, lastFilledAt: string, economyUnit: EconomyUnit, lastEconomy: number | null, economy90d: number | null, segments90d: number }
