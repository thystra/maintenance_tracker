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

export interface AssetReference {
	uuid: string
	name: string
	assetClass: AssetClass
	archived: boolean
}

export interface RelationshipType {
	key: string
	label: string
	inverseKey: string
	inverseLabel: string
	symmetric: boolean
	sourceClasses: AssetClass[]
	targetClasses: AssetClass[]
}

export interface RelationshipTypeList { items: RelationshipType[] }

export interface Relationship {
	uuid: string
	type: string
	label: string
	inverseType: string
	inverseLabel: string
	sourceAsset: AssetReference
	targetAsset: AssetReference
	context: string | null
	isDefault: boolean
	notes: string | null
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}

export interface RelationshipList { workspace: string, items: Relationship[] }
export interface CreateRelationship {
	uuid?: string
	sourceAssetUuid: string
	targetAssetUuid: string
	type: string
	context?: string | null
	isDefault?: boolean
	notes?: string | null
}

export interface Assignment {
	uuid: string
	type: string
	label: string
	inverseType: string
	inverseLabel: string
	sourceAsset: AssetReference
	targetAsset: AssetReference
	context: string | null
	isPrimary: boolean
	effectiveFrom: string
	effectiveUntil: string | null
	notes: string | null
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}

export interface AssignmentList { workspace: string, items: Assignment[] }
export interface CreateAssignment {
	uuid?: string
	sourceAssetUuid: string
	targetAssetUuid: string
	type: string
	context?: string | null
	isPrimary?: boolean
	effectiveFrom: string
	effectiveUntil?: string | null
	notes?: string | null
}

export type MeterDimension = 'distance' | 'runtime' | 'usage_count'
export interface Meter {
	uuid: string
	componentUuid: string | null
	key: string
	name: string
	dimension: MeterDimension
	canonicalUnit: 'mm' | 's' | 'count'
	displayUnit: string
	monotonic: boolean
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}
export interface MeterList { workspace: string, items: Meter[] }
export interface CreateMeter { uuid?: string, componentUuid?: string | null, key: string, name: string, dimension: MeterDimension, displayUnit: string, monotonic?: boolean }

export interface ReadingSource { type: string, reference: string | null }
export interface Reading {
	uuid: string
	observedAt: string
	canonicalValue: number
	originalValue: string
	originalUnit: string
	source: ReadingSource
	notes: string | null
	supersedesUuid: string | null
	supersededByUuid: string | null
	effective: boolean
	createdAt: string
}
export interface ReadingList { workspace: string, items: Reading[] }
export interface CreateReading { uuid?: string, observedAt: string, value: string | number, unit?: string, source?: ReadingSource, notes?: string | null }

export interface WorkGroup {
	uuid: string
	key: string
	name: string
	description: string | null
	sortOrder: number
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}
export interface WorkGroupList { workspace: string, items: WorkGroup[] }
export interface CreateWorkGroup { uuid?: string, key: string, name: string, description?: string | null, sortOrder?: number }

export type CalendarScheduleUnit = 'day' | 'week' | 'month' | 'year'
export type BusinessWeekday = 'sun' | 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat'
export interface CalendarScheduleRule { type: 'calendar', interval: { value: number, unit: CalendarScheduleUnit } }
export interface BusinessDaysScheduleRule { type: 'business_days', interval: { value: number, unit: 'business_day' }, weekdays: BusinessWeekday[] }
export interface MeterScheduleRule { type: 'meter', meterUuid: string, interval: { value: string | number, unit: string } }
export type WorkScheduleRule = CalendarScheduleRule | BusinessDaysScheduleRule | MeterScheduleRule
export type WorkSchedule = 'none' | { combination: 'any', rules: WorkScheduleRule[] }
export interface WorkDefinition {
	uuid: string
	componentUuid: string | null
	groupUuid: string | null
	key: string
	title: string
	kind: string
	instructions: string | null
	notes: string | null
	status: AssetStatus
	schedule: WorkSchedule
	revision: number
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}
export interface WorkDefinitionList { workspace: string, items: WorkDefinition[] }
export interface CreateWorkDefinition {
	uuid?: string
	componentUuid?: string | null
	groupUuid?: string | null
	key: string
	title: string
	kind: string
	instructions?: string | null
	notes?: string | null
	status?: AssetStatus
	schedule: WorkSchedule
}

export interface ActivityItem {
	uuid: string
	definitionUuid: string | null
	componentUuid: string | null
	componentName: string | null
	title: string
	kind: string
	notes: string | null
}
export interface ActivityMeterSnapshot {
	uuid: string
	meterUuid: string
	meterName: string
	readingUuid: string
	observedAt: string
	canonicalValue: number
	originalValue: string
	originalUnit: string
}
export interface Activity {
	uuid: string
	performedAt: string
	summary: string | null
	notes: string | null
	revision: number
	items: ActivityItem[]
	meters: ActivityMeterSnapshot[]
	createdAt: string
	updatedAt: string
	deletedAt: string | null
}
export interface ActivityList { workspace: string, items: Activity[] }
export interface CreateActivityItem { uuid: string, definitionUuid?: string | null, componentUuid?: string | null, title?: string, kind?: string, notes?: string | null }
export interface CreateActivityMeter { uuid: string, meterUuid: string, readingUuid?: string, reading?: { uuid: string, value: string | number, unit: string, notes?: string | null } }
export interface CreateActivity { uuid: string, performedAt: string, summary?: string | null, notes?: string | null, items: CreateActivityItem[], meters?: CreateActivityMeter[] }
