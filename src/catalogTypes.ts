/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export interface Part {
	uuid: string
	manufacturer: string
	partNumber: string
	description: string
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}

export interface PartList {
	workspace: string
	items: Part[]
}

export interface CreatePart {
	uuid?: string
	manufacturer: string
	partNumber: string
	description: string
}

export interface Vendor {
	uuid: string
	name: string
	notes: string | null
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}

export interface VendorList {
	workspace: string
	items: Vendor[]
}

export interface CreateVendor {
	uuid?: string
	name: string
	notes?: string | null
}

export interface CompatiblePart {
	subjectType: 'component' | 'work_definition'
	subjectUuid: string
	part: { uuid: string, manufacturer: string, partNumber: string, description: string, archived: boolean }
}

export interface CompatiblePartList {
	assetUuid: string
	items: CompatiblePart[]
}

export type CostLineType = 'charge' | 'tax'
export type CostCategory = 'parts' | 'labor' | 'fluids' | 'service' | 'fees' | 'tax' | 'other'

export interface CostSettings {
	defaultCurrency: string
	taxEnabled: boolean
	multiCurrency: boolean
	revision: number
	source?: 'default' | 'workspace'
	currenciesInUse?: string[]
}

export interface CostEntry {
	uuid: string
	assetUuid: string
	activityUuid: string | null
	vendorUuid: string | null
	vendorName: string | null
	partUuid: string | null
	lineType: CostLineType
	category: CostCategory
	description: string
	/** Exact integer amount in the currency's minor units. */
	amountMinor: number
	/** Exact decimal string rendered from amountMinor; never parsed as a float. */
	amount: string
	currency: string
	exponent: number
	incurredOn: string
	notes: string | null
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}

export interface CostTotal {
	currency: string
	exponent: number
	chargesMinor: number
	taxMinor: number
	totalMinor: number
	charges: string
	tax: string
	total: string
	count: number
}

export interface AssetCostList {
	assetUuid: string
	items: CostEntry[]
	totals: CostTotal[]
	settings: CostSettings
}

export interface CreateCost {
	uuid: string
	incurredOn: string
	description: string
	/** Decimal string such as "12.50"; numbers are rejected by the server. */
	amount: string
	currency?: string
	lineType?: CostLineType
	category?: CostCategory
	vendorUuid?: string | null
	notes?: string | null
}
