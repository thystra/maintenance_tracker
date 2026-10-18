<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Component, MaintenanceForecastItem, Meter, WorkDefinition, WorkGroup } from '../types.ts'

import { mdiDeleteOutline, mdiPencilOutline, mdiPlus, mdiWrenchOutline } from '@mdi/js'
import { computed, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ConfirmDialog from './ConfirmDialog.vue'
import StateBadge from './StateBadge.vue'
import TaskFormDialog from './TaskFormDialog.vue'
import { errorMessage, forecastLabel, scheduleLabel } from '../format.ts'
import { t } from '../l10n.ts'
import { archiveWorkDefinition } from '../services/api.ts'
import { can } from '../store.ts'

const props = defineProps<{
	assetUuid: string
	definitions: WorkDefinition[]
	forecast: MaintenanceForecastItem[]
	meters: Meter[]
	components: Component[]
	groups: WorkGroup[]
}>()
const emit = defineEmits<{ (event: 'changed'): void, (event: 'log', definitionUuid: string): void }>()

const formOpen = ref(false)
const editing = ref<WorkDefinition | null>(null)
const removing = ref<WorkDefinition | null>(null)
const busy = ref(false)
const error = ref('')

const active = computed(() => props.definitions.filter((definition) => definition.status !== 'retired'))
const stateOf = (uuid: string) => props.forecast.find((item) => item.definition.uuid === uuid)?.forecast.state ?? null
const groupName = (uuid: string | null) => props.groups.find((group) => group.uuid === uuid)?.name ?? ''
const componentName = (uuid: string | null) => props.components.find((component) => component.uuid === uuid)?.name ?? ''

function edit(definition: WorkDefinition | null): void {
	editing.value = definition
	formOpen.value = true
}

async function remove(): Promise<void> {
	const definition = removing.value
	if (definition === null) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		await archiveWorkDefinition(definition.uuid, definition.revision)
		removing.value = null
		emit('changed')
	} catch (reason) {
		removing.value = null
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div>
		<div class="panel-bar">
			<p class="muted">
				{{ t('maintenance_tracker', 'Recurring work for this item. Each task can repeat by time, by a meter, or both.') }}
			</p>
			<NcButton v-if="can.manageInventory" @click="edit(null)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('maintenance_tracker', 'Add task') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<p v-if="active.length === 0" class="muted">
			{{ t('maintenance_tracker', 'No tasks yet. Add one, or apply a maintenance profile on the Setup tab to get a recommended set.') }}
		</p>
		<ul class="list">
			<NcListItem
				v-for="definition in active"
				:key="definition.uuid"
				:name="definition.title"
				:forceDisplayActions="true">
				<template #subname>
					{{ [scheduleLabel(definition.schedule), componentName(definition.componentUuid), groupName(definition.groupUuid)].filter(Boolean).join(' · ') }}
				</template>
				<template #details>
					<StateBadge v-if="stateOf(definition.uuid)" :state="stateOf(definition.uuid) ?? ''" :label="forecastLabel(stateOf(definition.uuid) ?? 'unscheduled')" />
				</template>
				<template #actions>
					<NcActionButton v-if="can.log" closeAfterClick @click="emit('log', definition.uuid)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiWrenchOutline" />
						</template>
						{{ t('maintenance_tracker', 'Log as done') }}
					</NcActionButton>
					<NcActionButton v-if="can.manageInventory" closeAfterClick @click="edit(definition)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPencilOutline" />
						</template>
						{{ t('maintenance_tracker', 'Edit') }}
					</NcActionButton>
					<NcActionButton v-if="can.manageInventory" closeAfterClick @click="removing = definition">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDeleteOutline" />
						</template>
						{{ t('maintenance_tracker', 'Delete') }}
					</NcActionButton>
				</template>
			</NcListItem>
		</ul>
		<TaskFormDialog
			v-model:open="formOpen"
			:assetUuid="assetUuid"
			:task="editing"
			:meters="meters"
			:components="components"
			:groups="groups"
			@saved="emit('changed')" />
		<ConfirmDialog
			:open="removing !== null"
			:name="t('maintenance_tracker', 'Delete task?')"
			:message="t('maintenance_tracker', 'Delete the task “{title}”? Work already logged for it stays in the history.', { title: removing?.title ?? '' })"
			:busy="busy"
			@update:open="removing = $event ? removing : null"
			@confirm="remove" />
	</div>
</template>

<style scoped>
.panel-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 8px;
}

.list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
