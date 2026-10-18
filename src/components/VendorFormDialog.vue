<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Vendor } from '../catalogTypes.ts'

import { computed, reactive, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage } from '../format.ts'
import { t } from '../l10n.ts'
import { createVendor, updateVendor } from '../services/catalogApi.ts'

const props = defineProps<{ open: boolean, vendor?: Vendor | null, websitesEnabled: boolean }>()
const emit = defineEmits<{ (event: 'update:open', value: boolean): void, (event: 'saved', vendor: Vendor): void }>()

const draft = reactive({ name: '', website: '', address: '', phone: '', email: '', contactName: '', notes: '' })
const error = ref('')
const busy = ref(false)

watch(() => props.open, (open) => {
	if (!open) {
		return
	}
	error.value = ''
	const vendor = props.vendor
	Object.assign(draft, {
		name: vendor?.name ?? '',
		website: vendor?.website ?? '',
		address: vendor?.address ?? '',
		phone: vendor?.phone ?? '',
		email: vendor?.email ?? '',
		contactName: vendor?.contactName ?? '',
		notes: vendor?.notes ?? '',
	})
}, { immediate: true })

async function submit(): Promise<void> {
	if (draft.name.trim() === '') {
		error.value = t('maintenance_tracker', 'Enter the vendor name.')
		return
	}
	const website = draft.website.trim()
	if (props.websitesEnabled && website !== '' && !website.startsWith('https://')) {
		error.value = t('maintenance_tracker', 'The website must start with https://.')
		return
	}
	busy.value = true
	error.value = ''
	const fields = {
		name: draft.name.trim(),
		address: draft.address.trim() || null,
		phone: draft.phone.trim() || null,
		email: draft.email.trim() || null,
		contactName: draft.contactName.trim() || null,
		notes: draft.notes.trim() || null,
		...(props.websitesEnabled ? { website: website || null } : {}),
	}
	try {
		const saved = props.vendor
			? await updateVendor(props.vendor.uuid, props.vendor.revision, fields)
			: await createVendor({ uuid: crypto.randomUUID(), ...fields })
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
	{ label: props.vendor ? t('maintenance_tracker', 'Save') : t('maintenance_tracker', 'Add vendor'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value },
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="vendor ? t('maintenance_tracker', 'Edit vendor') : t('maintenance_tracker', 'Add vendor')"
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
				v-model="draft.name"
				:label="t('maintenance_tracker', 'Name')"
				:helperText="t('maintenance_tracker', 'A shop, garage, supplier or service company.')"
				maxlength="255"
				required />
			<NcTextField
				v-if="websitesEnabled"
				v-model="draft.website"
				type="url"
				:label="t('maintenance_tracker', 'Website (https only)')"
				:placeholder="t('maintenance_tracker', 'https://')"
				maxlength="2048" />
			<NcTextArea
				v-model="draft.address"
				:label="t('maintenance_tracker', 'Address')"
				rows="2"
				resize="vertical" />
			<div class="form-row">
				<NcTextField
					v-model="draft.phone"
					type="tel"
					:label="t('maintenance_tracker', 'Phone')"
					maxlength="64" />
				<NcTextField
					v-model="draft.email"
					type="email"
					:label="t('maintenance_tracker', 'Email')"
					maxlength="254" />
			</div>
			<NcTextField
				v-model="draft.contactName"
				:label="t('maintenance_tracker', 'Contact person')"
				:helperText="t('maintenance_tracker', 'Someone’s name, phone and email are personal data: store only what you need.')"
				maxlength="255" />
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
