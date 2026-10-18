/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Small shared state for the whole app: the active workspace, the caller's
// role in it, and caches that several pages need (items, categories).

import type { ItemGroup } from './groupTypes.ts'
import type { Asset, Category } from './types.ts'
import type { WorkspaceRole, WorkspaceSummary } from './workspaceTypes.ts'

import { computed, reactive } from 'vue'
import { currentWorkspace, getAssets, getCategories, selectWorkspace } from './services/api.ts'
import { getItemGroups } from './services/groupApi.ts'
import { listWorkspaces } from './services/workspaceApi.ts'

export const state = reactive({
	workspaces: [] as WorkspaceSummary[],
	assets: [] as Asset[],
	categories: [] as Category[],
	/** Item groups (flat; parentUuid builds the tree) and asset UUID => group UUID. */
	groups: [] as ItemGroup[],
	groupOf: {} as Record<string, string>,
	loaded: false,
	assetsLoaded: false,
	/** Bumped on every workspace switch so pages reload their data. */
	generation: 0,
	/** Page heading override for detail pages (the item name); cleared on navigation. */
	pageTitle: '',
})

export const activeWorkspace = computed<WorkspaceSummary | null>(() => {
	const chosen = currentWorkspace()
	void state.generation
	return state.workspaces.find((workspace) => chosen === null ? workspace.kind === 'personal' : workspace.uuid === chosen)
		?? state.workspaces.find((workspace) => workspace.kind === 'personal')
		?? null
})

const role = computed<WorkspaceRole>(() => activeWorkspace.value?.role ?? 'viewer')

/** What the caller's role allows; mirrors lib/Service/AuthorizationCatalog.php. */
export const can = reactive({
	manageInventory: computed(() => role.value === 'owner' || role.value === 'manager'),
	log: computed(() => role.value !== 'viewer'),
	addCosts: computed(() => role.value !== 'viewer'),
	addReadings: computed(() => role.value !== 'viewer'),
	manageCatalog: computed(() => role.value === 'owner' || role.value === 'manager'),
	readAudit: computed(() => role.value === 'owner' || role.value === 'manager'),
	ownWorkspace: computed(() => role.value === 'owner'),
	readMembers: computed(() => role.value === 'owner' || role.value === 'manager'),
	manageMembers: computed(() => role.value === 'owner'),
	manageSettings: computed(() => role.value === 'owner'),
	manageForecastPolicy: computed(() => role.value === 'owner' || role.value === 'manager'),
})

export async function loadWorkspaces(): Promise<void> {
	state.workspaces = await listWorkspaces()
	const chosen = currentWorkspace()
	if (chosen !== null && !state.workspaces.some((workspace) => workspace.uuid === chosen)) {
		// The stored workspace is gone (left, deleted, or transferred away).
		selectWorkspace(null)
	}
	state.loaded = true
}

export async function loadAssets(): Promise<Asset[]> {
	const all: Asset[] = []
	let cursor: string | null = null
	do {
		const page = await getAssets(cursor)
		all.push(...page.items)
		cursor = page.nextCursor
	} while (cursor !== null)
	state.assets = all.sort((left, right) => left.name.localeCompare(right.name))
	state.assetsLoaded = true
	return state.assets
}

export async function loadCategories(): Promise<Category[]> {
	state.categories = (await getCategories()).items
	return state.categories
}

export function categoryName(key: string): string {
	return state.categories.find((category) => category.key === key)?.name ?? key
}

export function assetName(uuid: string | null | undefined): string {
	return state.assets.find((asset) => asset.uuid === uuid)?.name ?? ''
}

/**
 * Switch the active workspace without reloading the page.
 *
 * @param uuid Workspace UUID.
 */
export async function switchWorkspace(uuid: string): Promise<void> {
	const target = state.workspaces.find((workspace) => workspace.uuid === uuid)
	selectWorkspace(target === undefined || target.kind === 'personal' ? null : target.uuid)
	state.assets = []
	state.assetsLoaded = false
	state.groups = []
	state.groupOf = {}
	state.generation++
	await Promise.all([loadAssets(), loadCategories(), loadGroups()])
}

export async function loadGroups(): Promise<void> {
	const list = await getItemGroups()
	state.groups = list.items
	state.groupOf = Object.fromEntries(list.assignments.map((entry) => [entry.assetUuid, entry.groupUuid]))
}

/**
 * Child groups of a group (null: top-level groups), in display order.
 *
 * @param parentUuid Parent group UUID, or null for the top level.
 */
export function childGroups(parentUuid: string | null): ItemGroup[] {
	return state.groups
		.filter((group) => group.parentUuid === parentUuid)
		.sort((left, right) => left.sort - right.sort || left.name.localeCompare(right.name))
}

/**
 * The group and every group below it.
 *
 * @param uuid Group UUID.
 */
export function groupSubtree(uuid: string): Set<string> {
	const out = new Set<string>([uuid])
	let grew = true
	while (grew) {
		grew = false
		for (const group of state.groups) {
			if (group.parentUuid !== null && out.has(group.parentUuid) && !out.has(group.uuid)) {
				out.add(group.uuid)
				grew = true
			}
		}
	}
	return out
}

/**
 * Ancestors of a group from the top level down to the group itself.
 *
 * @param uuid Group UUID.
 */
export function groupPath(uuid: string | null | undefined): ItemGroup[] {
	const path: ItemGroup[] = []
	const seen = new Set<string>()
	let current = state.groups.find((group) => group.uuid === uuid)
	while (current !== undefined && !seen.has(current.uuid)) {
		seen.add(current.uuid)
		path.unshift(current)
		const parent = current.parentUuid
		current = parent === null ? undefined : state.groups.find((group) => group.uuid === parent)
	}
	return path
}

/**
 * Items in a group including its subgroups.
 *
 * @param uuid Group UUID.
 */
export function itemsInGroup(uuid: string): Asset[] {
	const groups = groupSubtree(uuid)
	return state.assets.filter((asset) => {
		const group = state.groupOf[asset.uuid]
		return group !== undefined && groups.has(group)
	})
}

/**
 * Group choices for pickers, labelled with their full path ("House › Basement").
 *
 * @param exclude Groups to leave out (a group being moved and its subgroups).
 */
export function groupOptions(exclude: Set<string> = new Set()): { value: string, label: string }[] {
	const out: { value: string, label: string }[] = []
	const walk = (parent: string | null): void => {
		for (const group of childGroups(parent)) {
			if (exclude.has(group.uuid)) {
				continue
			}
			out.push({ value: group.uuid, label: groupPath(group.uuid).map((entry) => entry.name).join(' › ') })
			walk(group.uuid)
		}
	}
	walk(null)
	return out
}
