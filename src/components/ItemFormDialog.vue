<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Add or edit a maintained item. Only the name is required; everything else
// can be filled in later from the item page.
import type { Asset, CreateAsset } from '../types.ts'

import { computed, reactive, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SelectField from './SelectField.vue'
import { errorMessage } from '../format.ts'
import { t } from '../l10n.ts'
import { createAsset, updateAsset } from '../services/api.ts'
import { setItemGroup } from '../services/groupApi.ts'
import { groupOptions, state } from '../store.ts'

const props = defineProps<{ open: boolean, asset?: Asset | null, defaultGroup?: string | null }>()
const emit = defineEmits<{ (event: 'update:open', value: boolean): void, (event: 'saved', asset: Asset): void }>()

const blank = () => ({ name: '', category: state.categories[0]?.key ?? 'vehicle', manufacturer: '', model: '', modelYear: '', serialNumber: '', notes: '' })
const draft = reactive(blank())
const error = ref('')
const busy = ref(false)
const group = ref<string | null>(null)
const groupChoices = computed(() => groupOptions())

const categoryOptions = computed(() => state.categories.map((category) => ({ value: category.key, label: category.name })))
const title = computed(() => props.asset ? t('maintenance_tracker', 'Edit item') : t('maintenance_tracker', 'Add item'))

watch(() => props.open, (open) => {
	if (!open) {
		return
	}
	error.value = ''
	const asset = props.asset
	Object.assign(draft, asset
		? { name: asset.name, category: asset.category, manufacturer: asset.manufacturer ?? '', model: asset.model ?? '', modelYear: asset.modelYear === null ? '' : String(asset.modelYear), serialNumber: asset.serialNumber ?? '', notes: asset.notes ?? '' }
		: blank())
	group.value = asset ? state.groupOf[asset.uuid] ?? null : props.defaultGroup ?? null
}, { immediate: true })

async function submit(): Promise<void> {
	if (draft.name.trim() === '') {
		error.value = t('maintenance_tracker', 'Enter a name.')
		return
	}
	const year = draft.modelYear.trim()
	if (year !== '' && !/^\d{4}$/.test(year)) {
		error.value = t('maintenance_tracker', 'Enter the year as four digits, for example 2019.')
		return
	}
	const fields: CreateAsset = {
		name: draft.name.trim(),
		category: draft.category,
		manufacturer: draft.manufacturer.trim() || null,
		model: draft.model.trim() || null,
		modelYear: year === '' ? null : Number(year),
		serialNumber: draft.serialNumber.trim() || null,
		notes: draft.notes.trim() || null,
	}
	busy.value = true
	error.value = ''
	try {
		const saved = props.asset
			? await updateAsset(props.asset.uuid, props.asset.revision, fields)
			: await createAsset({ ...fields, uuid: crypto.randomUUID() })
		if ((state.groupOf[saved.uuid] ?? null) !== group.value) {
			await setItemGroup(saved.uuid, group.value)
		}
		emit('saved', saved)
		emit('update:open', false)
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

const buttons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => emit('update:open', false) },
	{ label: props.asset ? t('maintenance_tracker', 'Save') : t('maintenance_tracker', 'Add item'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value },
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="title"
		:buttons="buttons"
		isForm
		size="normal"
		@submit="submit"
		@update:open="emit('update:open', $event)">
		<div class="form-stack">
			<NcNoteCard v-if="error" type="error" role="alert">
				{{ error }}
			</NcNoteCard>
			<NcTextField
				v-model="draft.name"
				:label="t('maintenance_tracker', 'Name')"
				:helperText="t('maintenance_tracker', 'What you call it, for example “Family car” or “Furnace”.')"
				maxlength="255"
				required />
			<div class="form-row">
				<SelectField v-model="draft.category" :options="categoryOptions" :label="t('maintenance_tracker', 'Category')" />
				<SelectField
					v-if="groupChoices.length > 0"
					v-model="group"
					:options="groupChoices"
					:label="t('maintenance_tracker', 'Group')"
					:placeholder="t('maintenance_tracker', 'No group')"
					clearable />
			</div>
			<div class="form-row">
				<NcTextField v-model="draft.manufacturer" :label="t('maintenance_tracker', 'Make or manufacturer')" maxlength="255" />
				<NcTextField v-model="draft.model" :label="t('maintenance_tracker', 'Model')" maxlength="255" />
			</div>
			<div class="form-row">
				<NcTextField
					v-model="draft.modelYear"
					:label="t('maintenance_tracker', 'Year')"
					inputmode="numeric"
					maxlength="4" />
				<NcTextField v-model="draft.serialNumber" :label="t('maintenance_tracker', 'Serial number or VIN')" maxlength="255" />
			</div>
			<NcTextArea v-model="draft.notes" :label="t('maintenance_tracker', 'Notes')" resize="vertical" />
		</div>
	</NcDialog>
</template>

<style scoped>
.form-stack {
	display: grid;
	gap: 12px;
	padding-bottom: 8px;
}

.form-row {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 12px;
}
</style>
