<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Create, rename, move and delete item groups. Deleting a group never deletes
// items: its subgroups and items move up to its parent.
import { computed, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConfirmDialog from './ConfirmDialog.vue'
import SelectField from './SelectField.vue'
import { errorMessage } from '../format.ts'
import { closeGroupDialog, groupDialog } from '../groupActions.ts'
import { n, t } from '../l10n.ts'
import { createItemGroup, deleteItemGroup, moveItemGroup, renameItemGroup } from '../services/groupApi.ts'
import { childGroups, groupOptions, groupPath, groupSubtree, loadGroups, state } from '../store.ts'

const emit = defineEmits<{ (event: 'changed', message: string): void }>()

const name = ref('')
const parent = ref<string | null>(null)
const error = ref('')
const busy = ref(false)

const formOpen = computed(() => groupDialog.mode === 'create' || groupDialog.mode === 'rename' || groupDialog.mode === 'move')
const title = computed(() => {
	switch (groupDialog.mode) {
		case 'create': return t('maintenance_tracker', 'New group')
		case 'rename': return t('maintenance_tracker', 'Rename group')
		case 'move': return t('maintenance_tracker', 'Move group')
		default: return ''
	}
})
// A group cannot move into itself or below itself.
const parentOptions = computed(() => groupOptions(groupDialog.mode === 'move' && groupDialog.group ? groupSubtree(groupDialog.group.uuid) : new Set()))
const parentLabel = computed(() => groupDialog.mode === 'move' ? t('maintenance_tracker', 'Move into') : t('maintenance_tracker', 'Inside group'))

const deleteMessage = computed(() => {
	const group = groupDialog.group
	if (group === null) {
		return ''
	}
	const parentGroup = groupPath(group.parentUuid).at(-1)
	const subgroups = childGroups(group.uuid).length
	const direct = state.assets.filter((asset) => state.groupOf[asset.uuid] === group.uuid).length
	const target = parentGroup ? t('maintenance_tracker', 'into “{name}”', { name: parentGroup.name }) : t('maintenance_tracker', 'to the top level (items become ungrouped)')
	const contents = [
		n('maintenance_tracker', '%n subgroup', '%n subgroups', subgroups),
		n('maintenance_tracker', '%n item', '%n items', direct),
	].join(', ')
	return t('maintenance_tracker', 'Delete the group “{name}”? No items are deleted. Its contents ({contents}) move {target}.', { name: group.name, contents, target })
})

watch(() => groupDialog.mode, (mode) => {
	error.value = ''
	name.value = mode === 'rename' ? groupDialog.group?.name ?? '' : ''
	parent.value = mode === 'move' ? groupDialog.group?.parentUuid ?? null : groupDialog.parentUuid
})

async function submit(): Promise<void> {
	const group = groupDialog.group
	busy.value = true
	error.value = ''
	try {
		if (groupDialog.mode === 'create') {
			if (name.value.trim() === '') {
				error.value = t('maintenance_tracker', 'Enter a name.')
				return
			}
			await createItemGroup({ uuid: crypto.randomUUID(), name: name.value.trim(), parentUuid: parent.value })
			emit('changed', t('maintenance_tracker', 'Group {name} created.', { name: name.value.trim() }))
		} else if (groupDialog.mode === 'rename' && group) {
			if (name.value.trim() === '') {
				error.value = t('maintenance_tracker', 'Enter a name.')
				return
			}
			await renameItemGroup(group.uuid, group.revision, name.value.trim())
			emit('changed', t('maintenance_tracker', 'Group renamed to {name}.', { name: name.value.trim() }))
		} else if (groupDialog.mode === 'move' && group) {
			await moveItemGroup(group.uuid, group.revision, parent.value)
			emit('changed', t('maintenance_tracker', 'Group {name} moved.', { name: group.name }))
		}
		await loadGroups()
		closeGroupDialog()
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

async function confirmDelete(): Promise<void> {
	const group = groupDialog.group
	if (group === null) {
		return
	}
	busy.value = true
	try {
		await deleteItemGroup(group.uuid, group.revision)
		await loadGroups()
		emit('changed', t('maintenance_tracker', 'Group {name} deleted.', { name: group.name }))
		closeGroupDialog()
	} catch (reason) {
		closeGroupDialog()
		emit('changed', errorMessage(reason))
	} finally {
		busy.value = false
	}
}

// Both buttons return false so NcDialog does not run its own delayed close:
// that close lands 300 ms later and would shut a group dialog opened again in
// the meantime. submit() closes the dialog itself once the change is saved and
// keeps it open with the error otherwise.
const buttons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => {
		closeGroupDialog()
		return false
	} },
	{ label: groupDialog.mode === 'create' ? t('maintenance_tracker', 'Create group') : t('maintenance_tracker', 'Save'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value, callback: () => false },
])

function formClosed(open: boolean): void {
	if (!open && formOpen.value) {
		closeGroupDialog()
	}
}

function deleteClosed(open: boolean): void {
	if (!open && groupDialog.mode === 'delete') {
		closeGroupDialog()
	}
}

</script>

<template>
	<div>
		<NcDialog
			:open="formOpen"
			:name="title"
			:buttons="buttons"
			isForm
			size="small"
			@submit="submit"
			@update:open="formClosed">
			<div class="form-stack">
				<NcNoteCard v-if="error" type="error" role="alert">
					{{ error }}
				</NcNoteCard>
				<NcTextField
					v-if="groupDialog.mode !== 'move'"
					v-model="name"
					:label="t('maintenance_tracker', 'Group name')"
					:helperText="groupDialog.mode === 'create' ? t('maintenance_tracker', 'For example “Vehicles”, “Upstairs” or “Small engines”.') : ''"
					maxlength="120"
					required />
				<p v-if="groupDialog.mode === 'move'" class="muted">
					{{ t('maintenance_tracker', 'Moving a group takes its subgroups and items with it.') }}
				</p>
				<SelectField
					v-if="groupDialog.mode !== 'rename'"
					v-model="parent"
					:options="parentOptions"
					:label="parentLabel"
					:placeholder="t('maintenance_tracker', 'Top level')"
					clearable />
			</div>
		</NcDialog>
		<ConfirmDialog
			:open="groupDialog.mode === 'delete'"
			:name="t('maintenance_tracker', 'Delete group?')"
			:message="deleteMessage"
			:busy="busy"
			@update:open="deleteClosed"
			@confirm="confirmDelete" />
	</div>
</template>

<style scoped>
.form-stack {
	display: grid;
	gap: 12px;
	padding-bottom: 8px;
}

.muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
