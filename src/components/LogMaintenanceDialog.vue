<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// The most common task: record that maintenance was done. Item, what was done
// and when are enough; a meter reading, a part and a cost are optional extras.
import type { Part } from '../catalogTypes.ts'
import type { Meter, WorkDefinition } from '../types.ts'

import { computed, reactive, ref, watch } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SelectField from './SelectField.vue'
import { errorMessage, localNow, partUnits, toIso } from '../format.ts'
import { t } from '../l10n.ts'
import { createActivity, getMeters, getWorkDefinitions } from '../services/api.ts'
import { createCost, getCostSettings, getParts } from '../services/catalogApi.ts'
import { can, state } from '../store.ts'

const props = defineProps<{ open: boolean, assetUuid?: string | null, definitionUuid?: string | null }>()
const emit = defineEmits<{ (event: 'update:open', value: boolean): void, (event: 'saved', assetUuid: string): void }>()

const OTHER = '__other__'
const draft = reactive({
	assetUuid: null as string | null,
	task: null as string | null,
	title: '',
	performedAt: localNow(),
	notes: '',
	meterUuid: null as string | null,
	meterValue: '',
	partUuid: null as string | null,
	partQuantity: '1',
	partUnit: 'each' as string | null,
	cost: '',
})
const more = ref(false)
const definitions = ref<WorkDefinition[]>([])
const meters = ref<Meter[]>([])
const parts = ref<Part[]>([])
const currency = ref('')
const error = ref('')
const busy = ref(false)

const assetOptions = computed(() => state.assets.map((asset) => ({ value: asset.uuid, label: asset.name })))
const taskOptions = computed(() => [
	...definitions.value.filter((definition) => definition.status === 'active').map((definition) => ({ value: definition.uuid, label: definition.title })),
	{ value: OTHER, label: t('maintenance_tracker', 'Something else (describe it)') },
])
const meterOptions = computed(() => meters.value.map((meter) => ({ value: meter.uuid, label: `${meter.name} (${meter.displayUnit})` })))
const partOptions = computed(() => parts.value.map((part) => ({ value: part.uuid, label: `${part.description} · ${part.manufacturer} ${part.partNumber}` })))
const unitOptions = partUnits.map((unit) => ({ value: unit, label: unit }))
const selectedMeter = computed(() => meters.value.find((meter) => meter.uuid === draft.meterUuid) ?? null)

async function loadForAsset(uuid: string | null): Promise<void> {
	definitions.value = []
	meters.value = []
	if (uuid === null) {
		return
	}
	try {
		const [definitionList, meterList] = await Promise.all([getWorkDefinitions(uuid), getMeters(uuid)])
		definitions.value = definitionList.items
		meters.value = meterList.items
		draft.meterUuid = meters.value[0]?.uuid ?? null
		if (draft.task !== null && draft.task !== OTHER && !definitions.value.some((definition) => definition.uuid === draft.task)) {
			draft.task = null
		}
	} catch (reason) {
		error.value = errorMessage(reason)
	}
}

watch(() => props.open, async (open) => {
	if (!open) {
		return
	}
	error.value = ''
	more.value = false
	Object.assign(draft, { assetUuid: props.assetUuid ?? null, task: props.definitionUuid ?? null, title: '', performedAt: localNow(), notes: '', meterValue: '', partUuid: null, partQuantity: '1', partUnit: 'each', cost: '' })
	await loadForAsset(draft.assetUuid)
	try {
		const [partList, settings] = await Promise.all([getParts(), getCostSettings()])
		parts.value = partList.items
		currency.value = settings.defaultCurrency
	} catch {
		// Parts and costs are optional extras; the form still works without them.
	}
}, { immediate: true })

watch(() => draft.assetUuid, (uuid, before) => {
	if (props.open && uuid !== before) {
		void loadForAsset(uuid)
	}
})

async function submit(): Promise<void> {
	if (draft.assetUuid === null) {
		error.value = t('maintenance_tracker', 'Choose the item.')
		return
	}
	if (draft.task === null || (draft.task === OTHER && draft.title.trim() === '')) {
		error.value = t('maintenance_tracker', 'Choose what was done or describe it.')
		return
	}
	if (draft.performedAt === '') {
		error.value = t('maintenance_tracker', 'Enter when it was done.')
		return
	}
	const assetUuid = draft.assetUuid
	busy.value = true
	error.value = ''
	try {
		const activityUuid = crypto.randomUUID()
		const item = draft.task === OTHER
			? { uuid: crypto.randomUUID(), title: draft.title.trim(), kind: 'maintenance' }
			: { uuid: crypto.randomUUID(), definitionUuid: draft.task }
		const meter = selectedMeter.value
		await createActivity(assetUuid, {
			uuid: activityUuid,
			performedAt: toIso(draft.performedAt),
			notes: draft.notes.trim() || null,
			items: [item],
			meters: meter !== null && draft.meterValue.trim() !== ''
				? [{ uuid: crypto.randomUUID(), meterUuid: meter.uuid, reading: { uuid: crypto.randomUUID(), value: draft.meterValue.trim(), unit: meter.displayUnit } }]
				: [],
			parts: draft.partUuid !== null
				? [{ uuid: crypto.randomUUID(), partUuid: draft.partUuid, quantity: draft.partQuantity.trim() || '1', unit: draft.partUnit ?? 'each' }]
				: [],
		})
		if (draft.cost.trim() !== '' && can.addCosts) {
			const description = draft.task === OTHER ? draft.title.trim() : (definitions.value.find((definition) => definition.uuid === draft.task)?.title ?? t('maintenance_tracker', 'Maintenance'))
			await createCost(assetUuid, { uuid: crypto.randomUUID(), activityUuid, incurredOn: draft.performedAt.slice(0, 10), description, amount: draft.cost.trim(), category: 'service' })
		}
		emit('saved', assetUuid)
		emit('update:open', false)
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

const buttons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => emit('update:open', false) },
	{ label: t('maintenance_tracker', 'Save'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value },
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('maintenance_tracker', 'Log maintenance')"
		:buttons="buttons"
		isForm
		size="normal"
		@submit="submit"
		@update:open="emit('update:open', $event)">
		<div class="form-stack">
			<NcNoteCard v-if="error" type="error" role="alert">
				{{ error }}
			</NcNoteCard>
			<SelectField
				v-model="draft.assetUuid"
				:options="assetOptions"
				:label="t('maintenance_tracker', 'Item')"
				required />
			<SelectField
				v-model="draft.task"
				:options="taskOptions"
				:label="t('maintenance_tracker', 'What was done')"
				:disabled="draft.assetUuid === null"
				required />
			<NcTextField
				v-if="draft.task === OTHER"
				v-model="draft.title"
				:label="t('maintenance_tracker', 'Description')"
				:helperText="t('maintenance_tracker', 'For example “Replaced wiper blades”.')"
				maxlength="255"
				required />
			<label class="native-field">
				<span>{{ t('maintenance_tracker', 'When') }}</span>
				<input v-model="draft.performedAt" type="datetime-local" required>
			</label>
			<NcCheckboxRadioSwitch v-model="more" type="switch">
				{{ t('maintenance_tracker', 'Add a reading, part, cost or notes') }}
			</NcCheckboxRadioSwitch>
			<template v-if="more">
				<div v-if="meters.length > 0" class="form-row">
					<SelectField v-model="draft.meterUuid" :options="meterOptions" :label="t('maintenance_tracker', 'Meter')" />
					<NcTextField
						v-model="draft.meterValue"
						:label="selectedMeter ? t('maintenance_tracker', 'Reading in {unit}', { unit: selectedMeter.displayUnit }) : t('maintenance_tracker', 'Reading')"
						inputmode="decimal" />
				</div>
				<div v-if="parts.length > 0" class="form-row">
					<SelectField
						v-model="draft.partUuid"
						:options="partOptions"
						:label="t('maintenance_tracker', 'Part used')"
						clearable />
					<NcTextField v-model="draft.partQuantity" :label="t('maintenance_tracker', 'Quantity')" inputmode="decimal" />
					<SelectField v-model="draft.partUnit" :options="unitOptions" :label="t('maintenance_tracker', 'Unit')" />
				</div>
				<NcTextField
					v-if="can.addCosts"
					v-model="draft.cost"
					:label="t('maintenance_tracker', 'Cost ({currency})', { currency })"
					:helperText="t('maintenance_tracker', 'Optional. Adds a service cost line for this work; add more lines on the item\'s Costs tab.')"
					inputmode="decimal" />
				<NcTextArea v-model="draft.notes" :label="t('maintenance_tracker', 'Notes')" resize="vertical" />
			</template>
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
	align-items: end;
}

.native-field {
	display: grid;
	gap: 4px;
}

.native-field input {
	width: 100%;
	margin: 0;
}
</style>
