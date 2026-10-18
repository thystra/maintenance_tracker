<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Add or edit a catalog part. Manufacturer and part number are the part's
// identity and cannot change after creation; fitment is edited separately.
import type { Part, Vendor } from '../catalogTypes.ts'

import { computed, reactive, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SelectField from './SelectField.vue'
import { errorMessage } from '../format.ts'
import { t } from '../l10n.ts'
import { addPartFit, createPart, updatePart } from '../services/catalogApi.ts'
import { state } from '../store.ts'

const props = defineProps<{ open: boolean, part?: Part | null, vendors: Vendor[], defaultCurrency: string }>()
const emit = defineEmits<{ (event: 'update:open', value: boolean): void, (event: 'saved', part: Part): void }>()

const draft = reactive({ description: '', manufacturer: '', partNumber: '', vendorUuid: null as string | null, unitPrice: '', currency: '', notes: '', fitsAsset: null as string | null })
const error = ref('')
const busy = ref(false)

const vendorOptions = computed(() => props.vendors.map((vendor) => ({ value: vendor.uuid, label: vendor.name })))
const assetOptions = computed(() => state.assets.map((asset) => ({ value: asset.uuid, label: asset.name })))

watch(() => props.open, (open) => {
	if (!open) {
		return
	}
	error.value = ''
	const part = props.part
	Object.assign(draft, {
		description: part?.description ?? '',
		manufacturer: part?.manufacturer ?? '',
		partNumber: part?.partNumber ?? '',
		vendorUuid: part?.vendorUuid ?? null,
		unitPrice: part?.unitPrice ?? '',
		currency: part?.currency ?? props.defaultCurrency,
		notes: part?.notes ?? '',
		fitsAsset: null,
	})
}, { immediate: true })

async function submit(): Promise<void> {
	if (draft.description.trim() === '' || draft.manufacturer.trim() === '' || draft.partNumber.trim() === '') {
		error.value = t('maintenance_tracker', 'Enter a name, a manufacturer and a part number.')
		return
	}
	if (draft.unitPrice.trim() !== '' && !/^\d+(\.\d+)?$/.test(draft.unitPrice.trim())) {
		error.value = t('maintenance_tracker', 'Enter the price as a number such as 12.50.')
		return
	}
	busy.value = true
	error.value = ''
	const price = draft.unitPrice.trim()
	const extras = {
		description: draft.description.trim(),
		vendorUuid: draft.vendorUuid,
		unitPrice: price === '' ? null : price,
		currency: price === '' ? null : (draft.currency.trim().toUpperCase() || props.defaultCurrency),
		notes: draft.notes.trim() || null,
	}
	try {
		let saved = props.part
			? await updatePart(props.part.uuid, props.part.revision, extras)
			: await createPart({ uuid: crypto.randomUUID(), manufacturer: draft.manufacturer.trim(), partNumber: draft.partNumber.trim(), ...extras })
		if (!props.part && draft.fitsAsset !== null && !saved.fits.some((fit) => fit.assetUuid === draft.fitsAsset)) {
			saved = await addPartFit(saved.uuid, saved.revision, draft.fitsAsset)
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
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => {
		emit('update:open', false)
		return false
	} },
	{ label: props.part ? t('maintenance_tracker', 'Save') : t('maintenance_tracker', 'Add part'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value, callback: () => false },
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="part ? t('maintenance_tracker', 'Edit part') : t('maintenance_tracker', 'Add part')"
		:buttons="buttons"
		isForm
		size="normal"
		@submit="submit"
		@update:open="emit('update:open', $event)">
		<div class="form-stack">
			<NcNoteCard v-if="error" type="error">
				<p role="alert">
					{{ error }}
				</p>
			</NcNoteCard>
			<NcTextField
				v-model="draft.description"
				:label="t('maintenance_tracker', 'Name')"
				:helperText="t('maintenance_tracker', 'For example “Oil filter” or “Furnace filter 16×25×1”.')"
				maxlength="255"
				required />
			<div class="form-row">
				<NcTextField
					v-model="draft.manufacturer"
					:label="t('maintenance_tracker', 'Manufacturer')"
					:disabled="!!part"
					maxlength="255"
					required />
				<NcTextField
					v-model="draft.partNumber"
					:label="t('maintenance_tracker', 'Part number')"
					:disabled="!!part"
					maxlength="128"
					required />
			</div>
			<p v-if="part" class="muted">
				{{ t('maintenance_tracker', 'Manufacturer and part number identify the part and cannot be changed. Add a new part instead.') }}
			</p>
			<SelectField
				v-model="draft.vendorUuid"
				:options="vendorOptions"
				:label="t('maintenance_tracker', 'Usual vendor (optional)')"
				:placeholder="vendors.length === 0 ? t('maintenance_tracker', 'No vendors yet') : ''"
				clearable />
			<div class="form-row">
				<NcTextField v-model="draft.unitPrice" :label="t('maintenance_tracker', 'Typical price (optional)')" inputmode="decimal" />
				<NcTextField v-model="draft.currency" :label="t('maintenance_tracker', 'Currency')" maxlength="3" />
			</div>
			<SelectField
				v-if="!part && state.assets.length > 0"
				v-model="draft.fitsAsset"
				:options="assetOptions"
				:label="t('maintenance_tracker', 'Fits item (optional)')"
				clearable />
			<NcTextArea v-model="draft.notes" :label="t('maintenance_tracker', 'Notes (optional)')" resize="vertical" />
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
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 12px;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
