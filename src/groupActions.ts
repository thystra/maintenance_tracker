/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// One set of group dialogs (GroupDialogs.vue, mounted in App.vue) serves the
// sidebar tree, the Items page and anywhere else that manages groups.

import type { ItemGroup } from './groupTypes.ts'

import { reactive } from 'vue'

export type GroupDialogMode = 'create' | 'rename' | 'move' | 'delete'

export const groupDialog = reactive({
	mode: null as GroupDialogMode | null,
	group: null as ItemGroup | null,
	parentUuid: null as string | null,
})

/**
 * Open a group dialog.
 *
 * @param mode What to do.
 * @param group The group acted on (rename, move, delete).
 * @param parentUuid Parent for a new group.
 */
export function openGroupDialog(mode: GroupDialogMode, group: ItemGroup | null = null, parentUuid: string | null = null): void {
	groupDialog.group = group
	groupDialog.parentUuid = parentUuid
	groupDialog.mode = mode
}

export function closeGroupDialog(): void {
	groupDialog.mode = null
}
