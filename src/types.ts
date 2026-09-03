/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type AssetStatus = 'active' | 'suppressed' | 'retired'
export type AssetClass = 'vehicle' | 'trailer' | 'building' | 'equipment' | 'appliance' | 'system' | 'tool' | 'medical_device' | 'location' | 'other'

export interface Asset {
	uuid: string
	category: string
	assetClass: AssetClass
	name: string
	manufacturer: string | null
	model: string | null
	modelYear: number | null
	serialNumber: string | null
	notes: string | null
	status: AssetStatus
	profile: { key: string, version: string } | null
	acquiredOn: string | null
	purchasePrice: { minor: number, currency: string } | null
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}

export interface AssetList { workspace: string, items: Asset[], nextCursor: string | null }
export interface CreateAsset { uuid?: string, category: string, assetClass?: AssetClass | null, name: string, manufacturer?: string | null, model?: string | null, modelYear?: number | null, serialNumber?: string | null, notes?: string | null }

export interface Category { uuid: string | null, key: string, name: string, defaultAssetClass: AssetClass, description: string | null, builtIn: boolean, revision: number | null }
export interface CategoryList { workspace: string, items: Category[] }
export interface CreateCategory { uuid?: string, key: string, name: string, defaultAssetClass: AssetClass, description?: string | null }

export interface Component { uuid: string, parentUuid: string | null, type: string, name: string, manufacturer: string | null, model: string | null, partNumber: string | null, serialNumber: string | null, notes: string | null, status: AssetStatus, revision: number }
export interface ComponentList { items: Component[] }
export interface CreateComponent { uuid?: string, parentUuid?: string | null, type?: string, name: string, manufacturer?: string | null, model?: string | null, partNumber?: string | null, serialNumber?: string | null, notes?: string | null }

export interface SpecificationSource { type: string, reference: string | null }
export interface Specification { uuid: string, componentUuid: string | null, key: string, label: string, value: unknown, unit: string | null, regime: string | null, source: SpecificationSource | null, revision: number }
export interface SpecificationList { items: Specification[] }
export interface CreateSpecification { uuid?: string, componentUuid?: string | null, key: string, label: string, value: unknown, unit?: string | null, regime?: string | null, source?: SpecificationSource | null }

export interface Capabilities { app: string, appVersion: string, apiVersion: string, apiStability: 'experimental', features: string[] }
