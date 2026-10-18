/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export interface ItemGroup {
	uuid: string
	parentUuid: string | null
	name: string
	sort: number
	revision: number
	createdAt: string
	updatedAt: string
}

export interface ItemGroupList {
	workspace: string
	items: ItemGroup[]
	assignments: { assetUuid: string, groupUuid: string }[]
}

export interface ItemGroupDeleted {
	uuid: string
	movedGroups: number
	movedItems: number
	ungroupedItems: number
}
