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
