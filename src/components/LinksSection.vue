<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// How this item relates to others: lasting links (stored at, installed in)
// and time-bound assignments (this truck tows that trailer for a trip).
import type { Asset, Assignment, Relationship, RelationshipType } from '../types.ts'

import { mdiDeleteOutline, mdiPlus } from '@mdi/js'
import { computed, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SelectField from './SelectField.vue'
import { errorMessage, formatDate, today } from '../format.ts'
import { t } from '../l10n.ts'
import { archiveAssignment, archiveRelationship, createAssignment, createRelationship, getAssignments, getRelationships, getRelationshipTypes } from '../services/api.ts'
import { can, state } from '../store.ts'

const props = defineProps<{ asset: Asset }>()

interface Row { kind: 'relationship' | 'assignment', uuid: string, revision: number, label: string, other: string, otherUuid: string, when: string }

const types = ref<RelationshipType[]>([])
const relationships = ref<Relationship[]>([])
const assignments = ref<Assignment[]>([])
const error = ref('')
const formError = ref('')
const busy = ref(false)
const open = ref(false)
const draft = reactive({ type: null as string | null, target: null as string | null, temporary: false, from: today(), until: '', context: '' })

const rows = computed<Row[]>(() => {
	const mine = props.asset.uuid
	const toRow = (kind: Row['kind'], entry: Relationship | Assignment, when: string): Row | null => {
		if (entry.sourceAsset.uuid === mine) {
			return { kind, uuid: entry.uuid, revision: entry.revision, label: entry.label, other: entry.targetAsset.name, otherUuid: entry.targetAsset.uuid, when }
		}
		if (entry.targetAsset.uuid === mine) {
			return { kind, uuid: entry.uuid, revision: entry.revision, label: entry.inverseLabel, other: entry.sourceAsset.name, otherUuid: entry.sourceAsset.uuid, when }
		}
		return null
	}
	return [
		...relationships.value.map((entry) => toRow('relationship', entry, entry.context ?? '')),
		...assignments.value.map((entry) => toRow('assignment', entry, entry.effectiveUntil
			? t('maintenance_tracker', '{from} to {until}', { from: formatDate(entry.effectiveFrom), until: formatDate(entry.effectiveUntil) })
			: t('maintenance_tracker', 'since {from}', { from: formatDate(entry.effectiveFrom) }))),
	].filter((row): row is Row => row !== null)
})
const typeOptions = computed(() => types.value
	.filter((type) => type.sourceClasses.includes(props.asset.assetClass))
	.map((type) => ({ value: type.key, label: type.label })))
const selectedType = computed(() => types.value.find((type) => type.key === draft.type) ?? null)
const targetOptions = computed(() => state.assets
	.filter((asset) => asset.uuid !== props.asset.uuid && (selectedType.value === null || selectedType.value.targetClasses.includes(asset.assetClass)))
	.map((asset) => ({ value: asset.uuid, label: asset.name })))

async function load(): Promise<void> {
	try {
		const [typeList, relationshipList, assignmentList] = await Promise.all([getRelationshipTypes(), getRelationships(), getAssignments()])
		types.value = typeList.items
		relationships.value = relationshipList.items
		assignments.value = assignmentList.items
	} catch (reason) {
		error.value = errorMessage(reason)
	}
}

function openAdd(): void {
	Object.assign(draft, { type: typeOptions.value[0]?.value ?? null, target: null, temporary: false, from: today(), until: '', context: '' })
	formError.value = ''
	open.value = true
}

async function save(): Promise<false | void> {
	if (draft.type === null || draft.target === null) {
		formError.value = t('maintenance_tracker', 'Choose how they are linked and the other item.')
		return false
	}
	busy.value = true
	formError.value = ''
	try {
		const common = { uuid: crypto.randomUUID(), sourceAssetUuid: props.asset.uuid, targetAssetUuid: draft.target, type: draft.type, context: draft.context.trim() || null }
		if (draft.temporary) {
			await createAssignment({ ...common, isPrimary: true, effectiveFrom: draft.from, effectiveUntil: draft.until || null })
		} else {
			await createRelationship(common)
		}
		open.value = false
		await load()
	} catch (reason) {
		formError.value = errorMessage(reason)
		return false
	} finally {
		busy.value = false
	}
}

async function remove(row: Row): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		await (row.kind === 'relationship' ? archiveRelationship(row.uuid, row.revision) : archiveAssignment(row.uuid, row.revision))
		await load()
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

const buttons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => {
		open.value = false
		return false
	} },
	{ label: t('maintenance_tracker', 'Link'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value, callback: () => false },
])

onMounted(load)
</script>

<template>
	<div>
		<div class="bar">
			<p class="muted">
				{{ t('maintenance_tracker', 'Where this item is kept, what it is installed in, what it tows or powers.') }}
			</p>
			<NcButton v-if="can.manageInventory && state.assets.length > 1" variant="tertiary" @click="openAdd">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('maintenance_tracker', 'Link item') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<p v-if="rows.length === 0" class="muted">
			{{ t('maintenance_tracker', 'Not linked to other items.') }}
		</p>
		<ul class="plain">
			<li v-for="row in rows" :key="row.uuid">
				<span>
					{{ row.label }}
					<RouterLink :to="{ name: 'item', params: { uuid: row.otherUuid } }">{{ row.other }}</RouterLink>
					<span v-if="row.when" class="muted"> · {{ row.when }}</span>
				</span>
				<NcButton
					v-if="can.manageInventory"
					variant="tertiary"
					:disabled="busy"
					:aria-label="t('maintenance_tracker', 'Remove link to {name}', { name: row.other })"
					@click="remove(row)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDeleteOutline" />
					</template>
				</NcButton>
			</li>
		</ul>

		<NcDialog
			:open="open"
			:name="t('maintenance_tracker', 'Link to another item')"
			:buttons="buttons"
			isForm
			@submit="save"
			@update:open="open = $event">
			<div class="form-stack">
				<NcNoteCard v-if="formError" type="error">
					<p role="alert">
						{{ formError }}
					</p>
				</NcNoteCard>
				<SelectField v-model="draft.type" :options="typeOptions" :label="t('maintenance_tracker', '{name} …', { name: asset.name })" />
				<SelectField v-model="draft.target" :options="targetOptions" :label="t('maintenance_tracker', 'Other item')" />
				<NcCheckboxRadioSwitch v-model="draft.temporary" type="switch">
					{{ t('maintenance_tracker', 'Only for a period (for example a trip or a season)') }}
				</NcCheckboxRadioSwitch>
				<div v-if="draft.temporary" class="form-row">
					<label class="native-field">
						<span>{{ t('maintenance_tracker', 'From') }}</span>
						<input v-model="draft.from" type="date" required>
					</label>
					<label class="native-field">
						<span>{{ t('maintenance_tracker', 'Until (optional)') }}</span>
						<input v-model="draft.until" type="date">
					</label>
				</div>
				<NcTextField v-model="draft.context" :label="t('maintenance_tracker', 'Note (optional)')" maxlength="64" />
			</div>
		</NcDialog>
	</div>
</template>

<style scoped>
.bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.plain {
	display: grid;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.plain li {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.plain a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.form-stack {
	display: grid;
	gap: 12px;
	padding-bottom: 8px;
}

.form-row {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 12px;
}

.native-field {
	display: grid;
	gap: 4px;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
