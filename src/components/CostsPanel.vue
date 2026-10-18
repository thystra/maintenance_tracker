<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// What an item has cost: totals per currency, the entries, and a dialog
// to add one. Workspace-wide currency and tax options live in Settings.
import type { CostCategory, CostEntry, CostSettings, CostTotal, Vendor } from '../catalogTypes.ts'

import { mdiDeleteOutline, mdiPaperclip, mdiPlus } from '@mdi/js'
import { computed, onMounted, reactive, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConfirmDialog from './ConfirmDialog.vue'
import EvidencePanel from './EvidencePanel.vue'
import SelectField from './SelectField.vue'
import { errorMessage, formatDate, today } from '../format.ts'
import { n, t } from '../l10n.ts'
import { archiveCost, createCost, getAssetCosts, getVendors } from '../services/catalogApi.ts'
import { can } from '../store.ts'

const props = defineProps<{ assetUuid: string }>()

const items = ref<CostEntry[]>([])
const totals = ref<CostTotal[]>([])
const settings = ref<CostSettings | null>(null)
const vendors = ref<Vendor[]>([])
const error = ref('')
const formError = ref('')
const busy = ref(false)
const addOpen = ref(false)
const filesFor = ref<string | null>(null)
const removing = ref<CostEntry | null>(null)
const draft = reactive({ incurredOn: today(), description: '', amount: '', currency: '', lineType: 'charge' as string | null, category: 'parts' as string | null, vendorUuid: null as string | null, notes: '' })

const categoryLabels: Record<CostCategory, string> = {
	parts: t('maintenance_tracker', 'Parts'),
	labor: t('maintenance_tracker', 'Labor'),
	fluids: t('maintenance_tracker', 'Fluids'),
	fuel: t('maintenance_tracker', 'Fuel'),
	service: t('maintenance_tracker', 'Service'),
	fees: t('maintenance_tracker', 'Fees'),
	tax: t('maintenance_tracker', 'Tax'),
	other: t('maintenance_tracker', 'Other'),
}
const categoryOptions = (['parts', 'labor', 'fluids', 'fuel', 'service', 'fees', 'other'] as CostCategory[]).map((value) => ({ value, label: categoryLabels[value] }))
const lineOptions = [
	{ value: 'charge', label: t('maintenance_tracker', 'Charge') },
	{ value: 'tax', label: t('maintenance_tracker', 'Tax') },
]
const vendorOptions = computed(() => vendors.value.map((vendor) => ({ value: vendor.uuid, label: vendor.name })))

async function load(): Promise<void> {
	try {
		const list = await getAssetCosts(props.assetUuid)
		items.value = list.items
		totals.value = list.totals
		settings.value = list.settings
	} catch (reason) {
		error.value = errorMessage(reason)
	}
}

async function openAdd(): Promise<void> {
	Object.assign(draft, { incurredOn: today(), description: '', amount: '', currency: settings.value?.defaultCurrency ?? '', lineType: 'charge', category: 'parts', vendorUuid: null, notes: '' })
	formError.value = ''
	addOpen.value = true
	try {
		vendors.value = (await getVendors()).items
	} catch {
		vendors.value = []
	}
}

async function save(): Promise<false | void> {
	if (draft.description.trim() === '' || !/^\d+(\.\d+)?$/.test(draft.amount.trim())) {
		formError.value = t('maintenance_tracker', 'Enter a description and an amount such as 12.50.')
		return false
	}
	busy.value = true
	formError.value = ''
	try {
		const tax = draft.lineType === 'tax'
		await createCost(props.assetUuid, {
			uuid: crypto.randomUUID(),
			incurredOn: draft.incurredOn,
			description: draft.description.trim(),
			amount: draft.amount.trim(),
			currency: settings.value?.multiCurrency && draft.currency.trim() !== '' ? draft.currency.trim().toUpperCase() : undefined,
			lineType: tax ? 'tax' : 'charge',
			category: tax ? 'tax' : (draft.category ?? 'other') as CostCategory,
			vendorUuid: draft.vendorUuid,
			notes: draft.notes.trim() || null,
		})
		addOpen.value = false
		await load()
	} catch (reason) {
		formError.value = errorMessage(reason)
		return false
	} finally {
		busy.value = false
	}
}

async function remove(): Promise<void> {
	const entry = removing.value
	if (entry === null) {
		return
	}
	busy.value = true
	try {
		await archiveCost(entry.uuid, entry.revision)
		removing.value = null
		await load()
	} catch (reason) {
		removing.value = null
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

const buttons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => {
		addOpen.value = false
		return false
	} },
	{ label: t('maintenance_tracker', 'Add cost'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value, callback: () => false },
])

onMounted(load)
</script>

<template>
	<div>
		<div class="panel-bar">
			<ul v-if="totals.length > 0" class="totals" :aria-label="t('maintenance_tracker', 'Totals')">
				<li v-for="total in totals" :key="total.currency">
					<strong>{{ total.total }} {{ total.currency }}</strong>
					<span v-if="total.taxMinor > 0" class="muted"> {{ t('maintenance_tracker', '({charges} + {tax} tax)', { charges: total.charges, tax: total.tax }) }}</span>
					<span class="muted"> · {{ n('maintenance_tracker', '%n entry', '%n entries', total.count) }}</span>
				</li>
			</ul>
			<p v-else class="muted">
				{{ t('maintenance_tracker', 'No costs recorded yet.') }}
			</p>
			<NcButton v-if="can.addCosts" @click="openAdd">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('maintenance_tracker', 'Add cost') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<ul class="list">
			<template v-for="entry in items" :key="entry.uuid">
				<NcListItem :name="entry.description" :forceDisplayActions="true">
					<template #subname>
						{{ [formatDate(entry.incurredOn), categoryLabels[entry.category], entry.vendorName].filter(Boolean).join(' · ') }}
					</template>
					<template #details>
						<strong>{{ entry.amount }} {{ entry.currency }}</strong>
					</template>
					<template #actions>
						<NcActionButton closeAfterClick @click="filesFor = filesFor === entry.uuid ? null : entry.uuid">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPaperclip" />
							</template>
							{{ t('maintenance_tracker', 'Receipts and files') }}
						</NcActionButton>
						<NcActionButton v-if="can.manageInventory" closeAfterClick @click="removing = entry">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDeleteOutline" />
							</template>
							{{ t('maintenance_tracker', 'Delete') }}
						</NcActionButton>
					</template>
				</NcListItem>
				<li v-if="filesFor === entry.uuid" class="files">
					<EvidencePanel targetType="cost" :targetUuid="entry.uuid" compact />
				</li>
			</template>
		</ul>
		<p v-if="settings" class="muted hint">
			{{ t('maintenance_tracker', 'Currency and tax options are in Settings.') }}
		</p>

		<NcDialog
			:open="addOpen"
			:name="t('maintenance_tracker', 'Add cost')"
			:buttons="buttons"
			isForm
			@submit="save"
			@update:open="addOpen = $event">
			<div class="form-stack">
				<NcNoteCard v-if="formError" type="error">
					<p role="alert">
						{{ formError }}
					</p>
				</NcNoteCard>
				<NcTextField
					v-model="draft.description"
					:label="t('maintenance_tracker', 'Description')"
					maxlength="255"
					required />
				<div class="form-row">
					<NcTextField
						v-model="draft.amount"
						:label="t('maintenance_tracker', 'Amount')"
						inputmode="decimal"
						required />
					<NcTextField
						v-if="settings?.multiCurrency"
						v-model="draft.currency"
						:label="t('maintenance_tracker', 'Currency')"
						maxlength="3" />
					<label class="native-field">
						<span>{{ t('maintenance_tracker', 'Date') }}</span>
						<input v-model="draft.incurredOn" type="date" required>
					</label>
				</div>
				<SelectField
					v-if="settings?.taxEnabled"
					v-model="draft.lineType"
					:options="lineOptions"
					:label="t('maintenance_tracker', 'Line')" />
				<SelectField
					v-if="draft.lineType !== 'tax'"
					v-model="draft.category"
					:options="categoryOptions"
					:label="t('maintenance_tracker', 'Category')" />
				<SelectField
					v-if="vendors.length > 0"
					v-model="draft.vendorUuid"
					:options="vendorOptions"
					:label="t('maintenance_tracker', 'Vendor (optional)')"
					clearable />
				<NcTextField v-model="draft.notes" :label="t('maintenance_tracker', 'Notes (optional)')" />
			</div>
		</NcDialog>

		<ConfirmDialog
			:open="removing !== null"
			:name="t('maintenance_tracker', 'Delete cost?')"
			:message="t('maintenance_tracker', 'Delete the cost “{description}”?', { description: removing?.description ?? '' })"
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

.totals {
	margin: 0;
	padding: 0;
	list-style: none;
	font-size: 16px;
}

.list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.files {
	padding: 8px 16px 16px 52px;
}

.hint {
	margin-top: 12px;
}

.form-stack {
	display: grid;
	gap: 12px;
	padding-bottom: 8px;
}

.form-row {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 12px;
	align-items: end;
}

.native-field {
	display: grid;
	gap: 4px;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
