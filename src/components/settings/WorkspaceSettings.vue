<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Which workspace you are in, switching, creating a shared one, renaming.
import { mdiAccountGroupOutline, mdiAccountOutline, mdiCheck } from '@mdi/js'
import { ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, roleLabel } from '../../format.ts'
import { t } from '../../l10n.ts'
import { createWorkspace, renameWorkspace } from '../../services/workspaceApi.ts'
import { activeWorkspace, can, loadWorkspaces, state, switchWorkspace } from '../../store.ts'

const newName = ref('')
const rename = ref('')
const busy = ref(false)
const error = ref('')
const status = ref('')

watch(activeWorkspace, (workspace) => {
	rename.value = workspace?.name ?? ''
}, { immediate: true })

async function run(operation: () => Promise<void>): Promise<void> {
	busy.value = true
	error.value = ''
	status.value = ''
	try {
		await operation()
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

function create() {
	return run(async () => {
		const name = newName.value.trim()
		if (name === '') {
			throw new Error(t('maintenance_tracker', 'Enter a name for the new workspace.'))
		}
		const created = await createWorkspace(name)
		await loadWorkspaces()
		await switchWorkspace(created.uuid)
		newName.value = ''
		status.value = t('maintenance_tracker', 'Created and switched to {name}. Add members below.', { name: created.name })
	})
}

function save() {
	return run(async () => {
		const workspace = activeWorkspace.value
		if (workspace === null) {
			return
		}
		await renameWorkspace(workspace.uuid, workspace.revision ?? 1, rename.value.trim())
		await loadWorkspaces()
		status.value = t('maintenance_tracker', 'Renamed.')
	})
}

function choose(uuid: string) {
	return run(async () => {
		await switchWorkspace(uuid)
		status.value = t('maintenance_tracker', 'Switched to {name}.', { name: activeWorkspace.value?.name ?? '' })
	})
}
</script>

<template>
	<div class="mt-section">
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="status" type="success">
			<p role="status">
				{{ status }}
			</p>
		</NcNoteCard>
		<p class="muted">
			{{ t('maintenance_tracker', 'A workspace holds items, parts, vendors and history. Your personal workspace is private; create a shared workspace for a household, fleet or team and invite members.') }}
		</p>
		<h3>{{ t('maintenance_tracker', 'Your workspaces') }}</h3>
		<ul class="list">
			<NcListItem
				v-for="workspace in state.workspaces"
				:key="workspace.uuid"
				:name="workspace.name"
				:active="workspace.uuid === activeWorkspace?.uuid"
				compact
				@click="workspace.uuid === activeWorkspace?.uuid ? undefined : choose(workspace.uuid)">
				<template #icon>
					<NcIconSvgWrapper :path="workspace.kind === 'personal' ? mdiAccountOutline : mdiAccountGroupOutline" />
				</template>
				<template #subname>
					{{ workspace.kind === 'personal' ? t('maintenance_tracker', 'Personal · {role}', { role: roleLabel(workspace.role) }) : t('maintenance_tracker', 'Shared · {role}', { role: roleLabel(workspace.role) }) }}
				</template>
				<template v-if="workspace.uuid === activeWorkspace?.uuid" #indicator>
					<NcIconSvgWrapper :path="mdiCheck" :name="t('maintenance_tracker', 'Current workspace')" />
				</template>
			</NcListItem>
		</ul>

		<form v-if="can.ownWorkspace && activeWorkspace" class="inline-form" @submit.prevent="save">
			<h3>{{ t('maintenance_tracker', 'Rename current workspace') }}</h3>
			<div class="row">
				<NcTextField
					v-model="rename"
					:label="t('maintenance_tracker', 'Workspace name')"
					maxlength="120"
					required />
				<NcButton type="submit" :disabled="busy || rename.trim() === '' || rename.trim() === activeWorkspace.name">
					{{ t('maintenance_tracker', 'Rename') }}
				</NcButton>
			</div>
		</form>

		<form class="inline-form" @submit.prevent="create">
			<h3>{{ t('maintenance_tracker', 'Create a shared workspace') }}</h3>
			<div class="row">
				<NcTextField v-model="newName" :label="t('maintenance_tracker', 'Name, for example “Family cars”')" maxlength="120" />
				<NcButton type="submit" variant="primary" :disabled="busy || newName.trim() === ''">
					{{ t('maintenance_tracker', 'Create') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>
