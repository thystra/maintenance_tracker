<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Less frequent, structural setup of one item.
import type { CompatiblePart } from '../catalogTypes.ts'
import type { Asset, Component, MaintenanceOccurrence, WorkGroup } from '../types.ts'

import { mdiPlus } from '@mdi/js'
import { computed, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ComponentsSection from './ComponentsSection.vue'
import LinksSection from './LinksSection.vue'
import ProfileSection from './ProfileSection.vue'
import { errorMessage, forecastLabel, formatDate, keyFrom } from '../format.ts'
import { t } from '../l10n.ts'
import { createWorkGroup, getMaintenanceOccurrences, reconcileMaintenanceOccurrences } from '../services/api.ts'
import { getCompatibleParts } from '../services/catalogApi.ts'
import { can } from '../store.ts'

const props = defineProps<{ asset: Asset, components: Component[], groups: WorkGroup[] }>()
const emit = defineEmits<{ (event: 'changed'): void }>()

const parts = ref<CompatiblePart[]>([])
const queue = ref<MaintenanceOccurrence[]>([])
const queueError = ref('')
const groupOpen = ref(false)
const groupDraft = reactive({ name: '', description: '' })
const busy = ref(false)
const formError = ref('')

const uniqueParts = computed(() => [...new Map(parts.value.map((entry) => [entry.part.uuid, entry])).values()])

async function refreshQueue(): Promise<void> {
	busy.value = true
	queueError.value = ''
	try {
		queue.value = (await reconcileMaintenanceOccurrences(props.asset.uuid)).items
	} catch (reason) {
		queueError.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

function openGroup(): void {
	Object.assign(groupDraft, { name: '', description: '' })
	formError.value = ''
	groupOpen.value = true
}

async function saveGroup(): Promise<false | void> {
	if (groupDraft.name.trim() === '') {
		formError.value = t('maintenance_tracker', 'Enter a name.')
		return false
	}
	busy.value = true
	formError.value = ''
	try {
		await createWorkGroup(props.asset.uuid, { uuid: crypto.randomUUID(), key: keyFrom(groupDraft.name), name: groupDraft.name.trim(), description: groupDraft.description.trim() || null })
		groupOpen.value = false
		emit('changed')
	} catch (reason) {
		formError.value = errorMessage(reason)
		return false
	} finally {
		busy.value = false
	}
}

const buttons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => { groupOpen.value = false } },
	{ label: t('maintenance_tracker', 'Add group'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value },
])

onMounted(async () => {
	try {
		parts.value = (await getCompatibleParts(props.asset.uuid)).items
	} catch {
		parts.value = []
	}
	try {
		queue.value = (await getMaintenanceOccurrences(props.asset.uuid)).items
	} catch (reason) {
		queueError.value = errorMessage(reason)
	}
})
</script>

<template>
	<div class="setup">
		<section class="card" aria-labelledby="setup-profile">
			<h3 id="setup-profile">
				{{ t('maintenance_tracker', 'Maintenance profile') }}
			</h3>
			<ProfileSection :asset="asset" @changed="emit('changed')" />
		</section>
		<section class="card" aria-labelledby="setup-components">
			<h3 id="setup-components">
				{{ t('maintenance_tracker', 'Components and specifications') }}
			</h3>
			<ComponentsSection :assetUuid="asset.uuid" :components="components" @changed="emit('changed')" />
		</section>
		<section class="card" aria-labelledby="setup-links">
			<h3 id="setup-links">
				{{ t('maintenance_tracker', 'Linked items') }}
			</h3>
			<LinksSection :asset="asset" />
		</section>
		<section class="card" aria-labelledby="setup-groups">
			<div class="bar">
				<h3 id="setup-groups">
					{{ t('maintenance_tracker', 'Task groups') }}
				</h3>
				<NcButton
					v-if="can.manageInventory"
					variant="tertiary"
					@click="openGroup">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('maintenance_tracker', 'Add group') }}
				</NcButton>
			</div>
			<p class="muted">
				{{ t('maintenance_tracker', 'Groups bundle tasks that are usually done together, such as a “Spring service”.') }}
			</p>
			<ul class="plain">
				<li v-for="group in groups" :key="group.uuid">
					<strong>{{ group.name }}</strong>
					<span v-if="group.description" class="muted"> · {{ group.description }}</span>
				</li>
			</ul>
		</section>
		<section class="card" aria-labelledby="setup-parts">
			<h3 id="setup-parts">
				{{ t('maintenance_tracker', 'Parts that fit') }}
			</h3>
			<p v-if="uniqueParts.length === 0" class="muted">
				{{ t('maintenance_tracker', 'No parts are marked as fitting this item. Mark fitment on the Parts page.') }}
			</p>
			<ul class="plain">
				<li v-for="entry in uniqueParts" :key="entry.part.uuid">
					<strong>{{ entry.part.description }}</strong>
					<span class="muted"> · {{ entry.part.manufacturer }} {{ entry.part.partNumber }}</span>
				</li>
			</ul>
			<RouterLink class="link" :to="{ name: 'parts' }">
				{{ t('maintenance_tracker', 'Open parts') }}
			</RouterLink>
		</section>

		<section class="card" aria-labelledby="setup-queue">
			<div class="bar">
				<h3 id="setup-queue">
					{{ t('maintenance_tracker', 'Work queue') }}
				</h3>
				<NcButton
					v-if="can.manageInventory"
					variant="tertiary"
					:disabled="busy"
					@click="refreshQueue">
					{{ t('maintenance_tracker', 'Update work queue') }}
				</NcButton>
			</div>
			<p class="muted">
				{{ t('maintenance_tracker', 'Open work items kept for tasks that are due soon, due or overdue. They close when the work is logged.') }}
			</p>
			<NcNoteCard v-if="queueError" type="error">
				<p role="alert">
					{{ queueError }}
				</p>
			</NcNoteCard>
			<p v-if="queue.length === 0" class="muted">
				{{ t('maintenance_tracker', 'The work queue is empty.') }}
			</p>
			<ul class="plain">
				<li v-for="entry in queue" :key="entry.uuid">
					<strong>{{ entry.current?.definition.title ?? entry.definitionUuid }}</strong>
					<span class="muted"> · {{ entry.current ? forecastLabel(entry.current.forecast.state) : '' }} · {{ t('maintenance_tracker', 'opened {date}', { date: formatDate(entry.openedAt) }) }}</span>
				</li>
			</ul>
		</section>

		<NcDialog
			:open="groupOpen"
			:name="t('maintenance_tracker', 'Add task group')"
			:buttons="buttons"
			isForm
			@submit="saveGroup"
			@update:open="groupOpen = $event">
			<div class="form-stack">
				<NcNoteCard v-if="formError" type="error">
					<p role="alert">
						{{ formError }}
					</p>
				</NcNoteCard>
				<NcTextField
					v-model="groupDraft.name"
					:label="t('maintenance_tracker', 'Name')"
					maxlength="255"
					required />
				<NcTextField v-model="groupDraft.description" :label="t('maintenance_tracker', 'Description (optional)')" />
			</div>
		</NcDialog>
	</div>
</template>

<style scoped>
.setup {
	display: grid;
	gap: 16px;
}

.card {
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.card h3 {
	margin: 0 0 8px;
	font-size: 17px;
}

.bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.plain {
	display: grid;
	gap: 4px;
	margin: 0 0 8px;
	padding: 0;
	list-style: none;
}

.link {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.form-stack {
	display: grid;
	gap: 12px;
	padding-bottom: 8px;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
