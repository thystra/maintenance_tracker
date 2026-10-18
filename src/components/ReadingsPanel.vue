<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Meters (odometer, hour meter, cycle counter) and their readings.
import type { Component, Meter, MeterDimension, Reading } from '../types.ts'

import { mdiDeleteOutline, mdiGaugeEmpty, mdiPlus } from '@mdi/js'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConfirmDialog from './ConfirmDialog.vue'
import SelectField from './SelectField.vue'
import { dimensionLabel, errorMessage, formatDate, keyFrom, localNow, meterUnits, toIso } from '../format.ts'
import { t } from '../l10n.ts'
import { archiveMeter, createMeter, createReading, getReadings } from '../services/api.ts'
import { can } from '../store.ts'

const props = defineProps<{ assetUuid: string, meters: Meter[], components: Component[] }>()
const emit = defineEmits<{ (event: 'changed'): void }>()

const readings = ref<Record<string, Reading[]>>({})
const expanded = ref<string | null>(null)
const error = ref('')
const busy = ref(false)
const readingFor = ref<Meter | null>(null)
const meterOpen = ref(false)
const removing = ref<Meter | null>(null)
const readingDraft = reactive({ value: '', observedAt: '', notes: '' })
const meterDraft = reactive({ name: '', dimension: 'distance' as string | null, unit: 'km' as string | null, componentUuid: null as string | null, monotonic: true })
const formError = ref('')

const dimensionOptions = (['distance', 'runtime', 'usage_count'] as MeterDimension[]).map((value) => ({ value, label: dimensionLabel(value) }))
const unitOptions = computed(() => meterUnits((meterDraft.dimension ?? 'distance') as MeterDimension).map((value) => ({ value, label: value })))
const componentOptions = computed(() => props.components.map((component) => ({ value: component.uuid, label: component.name })))

const latest = (uuid: string): Reading | null => (readings.value[uuid] ?? []).find((reading) => reading.effective) ?? null

watch(() => meterDraft.dimension, () => {
	meterDraft.unit = meterUnits((meterDraft.dimension ?? 'distance') as MeterDimension)[0] ?? null
})

async function load(): Promise<void> {
	try {
		const lists = await Promise.all(props.meters.map((meter) => getReadings(meter.uuid)))
		readings.value = Object.fromEntries(props.meters.map((meter, index) => [meter.uuid, lists[index]?.items ?? []]))
	} catch (reason) {
		error.value = errorMessage(reason)
	}
}

function openReading(meter: Meter): void {
	Object.assign(readingDraft, { value: '', observedAt: localNow(), notes: '' })
	formError.value = ''
	readingFor.value = meter
}

function openMeter(): void {
	Object.assign(meterDraft, { name: '', dimension: 'distance', unit: 'km', componentUuid: null, monotonic: true })
	formError.value = ''
	meterOpen.value = true
}

async function saveReading(): Promise<false | void> {
	const meter = readingFor.value
	if (meter === null) {
		return
	}
	if (!/^\d+(\.\d+)?$/.test(readingDraft.value.trim())) {
		formError.value = t('maintenance_tracker', 'Enter the reading as a number, for example 48250 or 1210.5.')
		return false
	}
	busy.value = true
	formError.value = ''
	try {
		await createReading(meter.uuid, { uuid: crypto.randomUUID(), observedAt: toIso(readingDraft.observedAt), value: readingDraft.value.trim(), unit: meter.displayUnit, source: { type: 'manual', reference: null }, notes: readingDraft.notes.trim() || null })
		readingFor.value = null
		await load()
		emit('changed')
	} catch (reason) {
		formError.value = errorMessage(reason)
		return false
	} finally {
		busy.value = false
	}
}

async function saveMeter(): Promise<false | void> {
	if (meterDraft.name.trim() === '' || meterDraft.unit === null) {
		formError.value = t('maintenance_tracker', 'Enter a name and choose a unit.')
		return false
	}
	busy.value = true
	formError.value = ''
	try {
		await createMeter(props.assetUuid, { uuid: crypto.randomUUID(), key: keyFrom(meterDraft.name), name: meterDraft.name.trim(), dimension: (meterDraft.dimension ?? 'distance') as MeterDimension, displayUnit: meterDraft.unit, monotonic: meterDraft.monotonic, componentUuid: meterDraft.componentUuid })
		meterOpen.value = false
		emit('changed')
	} catch (reason) {
		formError.value = errorMessage(reason)
		return false
	} finally {
		busy.value = false
	}
}

async function remove(): Promise<void> {
	const meter = removing.value
	if (meter === null) {
		return
	}
	busy.value = true
	try {
		await archiveMeter(meter.uuid, meter.revision)
		removing.value = null
		emit('changed')
	} catch (reason) {
		removing.value = null
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

const readingButtons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => { readingFor.value = null } },
	{ label: t('maintenance_tracker', 'Save reading'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value },
])
const meterButtons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => { meterOpen.value = false } },
	{ label: t('maintenance_tracker', 'Add meter'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value },
])

watch(() => props.meters, load)
onMounted(load)
</script>

<template>
	<div>
		<div class="panel-bar">
			<p class="muted">
				{{ t('maintenance_tracker', 'Odometers, hour meters and counters. Readings drive distance- and hour-based tasks.') }}
			</p>
			<NcButton v-if="can.manageInventory" @click="openMeter">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('maintenance_tracker', 'Add meter') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<p v-if="meters.length === 0" class="muted">
			{{ t('maintenance_tracker', 'No meters. Add one if this item has an odometer, hour meter or usage counter.') }}
		</p>
		<ul class="list">
			<NcListItem
				v-for="meter in meters"
				:key="meter.uuid"
				:name="meter.name"
				:forceDisplayActions="true"
				@click="expanded = expanded === meter.uuid ? null : meter.uuid">
				<template #icon>
					<NcIconSvgWrapper :path="mdiGaugeEmpty" />
				</template>
				<template #subname>
					<template v-if="latest(meter.uuid)">
						{{ t('maintenance_tracker', '{value} {unit} on {date}', { value: latest(meter.uuid)?.originalValue ?? '', unit: latest(meter.uuid)?.originalUnit ?? '', date: formatDate(latest(meter.uuid)?.observedAt) }) }}
					</template>
					<template v-else>
						{{ t('maintenance_tracker', 'No readings yet') }}
					</template>
				</template>
				<template #extra-actions>
					<NcButton
						v-if="can.addReadings"
						variant="secondary"
						:aria-label="t('maintenance_tracker', 'Add reading for {meter}', { meter: meter.name })"
						@click.stop="openReading(meter)">
						{{ t('maintenance_tracker', 'Add reading') }}
					</NcButton>
				</template>
				<template v-if="can.manageInventory" #actions>
					<NcActionButton closeAfterClick @click="removing = meter">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDeleteOutline" />
						</template>
						{{ t('maintenance_tracker', 'Delete meter') }}
					</NcActionButton>
				</template>
			</NcListItem>
		</ul>
		<template v-for="meter in meters" :key="`history-${meter.uuid}`">
			<section v-if="expanded === meter.uuid" class="history" :aria-label="t('maintenance_tracker', 'Readings for {meter}', { meter: meter.name })">
				<h3>{{ t('maintenance_tracker', 'Readings for {meter}', { meter: meter.name }) }}</h3>
				<table>
					<thead>
						<tr>
							<th scope="col">
								{{ t('maintenance_tracker', 'Date') }}
							</th>
							<th scope="col">
								{{ t('maintenance_tracker', 'Reading') }}
							</th>
							<th scope="col">
								{{ t('maintenance_tracker', 'Notes') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="reading in readings[meter.uuid] ?? []" :key="reading.uuid" :class="{ superseded: !reading.effective }">
							<td>{{ formatDate(reading.observedAt) }}</td>
							<td>{{ reading.originalValue }} {{ reading.originalUnit }}</td>
							<td>{{ reading.notes ?? '' }}</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>

		<NcDialog
			:open="readingFor !== null"
			:name="t('maintenance_tracker', 'Add reading')"
			:buttons="readingButtons"
			isForm
			@submit="saveReading"
			@update:open="readingFor = $event ? readingFor : null">
			<div class="form-stack">
				<NcNoteCard v-if="formError" type="error">
					<p role="alert">
						{{ formError }}
					</p>
				</NcNoteCard>
				<NcTextField
					v-model="readingDraft.value"
					:label="readingFor ? t('maintenance_tracker', 'Reading ({unit})', { unit: readingFor.displayUnit }) : t('maintenance_tracker', 'Reading')"
					inputmode="decimal"
					required />
				<label class="native-field">
					<span>{{ t('maintenance_tracker', 'When') }}</span>
					<input v-model="readingDraft.observedAt" type="datetime-local" required>
				</label>
				<NcTextField v-model="readingDraft.notes" :label="t('maintenance_tracker', 'Notes (optional)')" />
			</div>
		</NcDialog>

		<NcDialog
			:open="meterOpen"
			:name="t('maintenance_tracker', 'Add meter')"
			:buttons="meterButtons"
			isForm
			@submit="saveMeter"
			@update:open="meterOpen = $event">
			<div class="form-stack">
				<NcNoteCard v-if="formError" type="error">
					<p role="alert">
						{{ formError }}
					</p>
				</NcNoteCard>
				<NcTextField
					v-model="meterDraft.name"
					:label="t('maintenance_tracker', 'Name')"
					:helperText="t('maintenance_tracker', 'For example “Odometer” or “Engine hours”.')"
					maxlength="255"
					required />
				<div class="form-row">
					<SelectField v-model="meterDraft.dimension" :options="dimensionOptions" :label="t('maintenance_tracker', 'Measures')" />
					<SelectField v-model="meterDraft.unit" :options="unitOptions" :label="t('maintenance_tracker', 'Unit')" />
				</div>
				<SelectField
					v-if="components.length > 0"
					v-model="meterDraft.componentUuid"
					:options="componentOptions"
					:label="t('maintenance_tracker', 'Belongs to component (optional)')"
					clearable />
				<NcCheckboxRadioSwitch v-model="meterDraft.monotonic" type="switch">
					{{ t('maintenance_tracker', 'Only counts up (reject lower readings)') }}
				</NcCheckboxRadioSwitch>
			</div>
		</NcDialog>

		<ConfirmDialog
			:open="removing !== null"
			:name="t('maintenance_tracker', 'Delete meter?')"
			:message="t('maintenance_tracker', 'Delete the meter “{name}”? Tasks scheduled by it stop being forecast by distance or hours.', { name: removing?.name ?? '' })"
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

.history {
	margin-top: 12px;
	overflow-x: auto;
}

.history h3 {
	font-size: 16px;
}

table {
	width: 100%;
	border-collapse: collapse;
}

th,
td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.superseded {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
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

.native-field input {
	width: 100%;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
