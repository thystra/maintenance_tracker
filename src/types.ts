/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type AssetStatus = 'active' | 'suppressed' | 'retired'

export interface Asset {
	uuid: string
	category: string
	name: string
	manufacturer: string | null
	model: string | null
	modelYear: number | null
	serialNumber: string | null
	notes: string | null
	status: AssetStatus
	profile: {
		key: string
		version: string
	} | null
	acquiredOn: string | null
	purchasePrice: {
		minor: number
		currency: string
	} | null
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}

export interface AssetList {
	workspace: string
	items: Asset[]
	nextCursor: string | null
}

export interface CreateAsset {
	uuid?: string
	category: string
	name: string
	manufacturer?: string | null
	model?: string | null
	modelYear?: number | null
	serialNumber?: string | null
	notes?: string | null
}

export interface Capabilities {
	app: string
	appVersion: string
	apiVersion: string
	apiStability: 'experimental'
	features: string[]
}
