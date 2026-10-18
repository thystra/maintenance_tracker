/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export interface Part {
	uuid: string
	manufacturer: string
	partNumber: string
	description: string
	notes: string | null
	vendorUuid: string | null
	vendorName: string | null
	/** Exact decimal string; never parsed as a float. */
	unitPrice: string | null
	currency: string | null
	fits: PartFit[]
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}

export interface PartFit {
	assetUuid: string
	assetName: string
	/** manual: recorded here; profile: supplied by a maintenance profile or fitment data. */
	source: 'manual' | 'profile'
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
	notes?: string | null
	vendorUuid?: string | null
	unitPrice?: string | null
	currency?: string | null
}

export type UpdatePart = Partial<Omit<CreatePart, 'uuid' | 'manufacturer' | 'partNumber'>>

export interface Vendor {
	uuid: string
	name: string
	notes: string | null
	/** https only; null while the administrator disables outside links. */
	website: string | null
	address: string | null
	phone: string | null
	email: string | null
	contactName: string | null
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}

export interface VendorList {
	workspace: string
	items: Vendor[]
	/** False while the administrator disables outside links; websites are then hidden and rejected. */
	websitesEnabled?: boolean
}

export interface CreateVendor {
	uuid?: string
	name: string
	notes?: string | null
	website?: string | null
	address?: string | null
	phone?: string | null
	email?: string | null
	contactName?: string | null
}

export interface CompatiblePart {
	subjectType: 'asset' | 'component' | 'work_definition'
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
	activityUuid?: string | null
	vendorUuid?: string | null
	notes?: string | null
}

export type LinkSubjectType = 'part' | 'vendor'

export interface OutsideLink {
	uuid: string
	subjectType: LinkSubjectType
	subjectUuid: string
	label: string
	/** Server-validated https URL; render only with rel="noopener noreferrer". */
	url: string
	source: 'manual' | 'profile'
	revision: number
	createdAt: string
	updatedAt: string
	archived: boolean
}

export interface OutsideLinkList {
	/** False when the Nextcloud administrator has disabled outside links. */
	enabled: boolean
	items: OutsideLink[]
}
