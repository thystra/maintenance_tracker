<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Add or edit a maintenance task (work definition) and its repeat schedule.
import type { CalendarScheduleUnit, Component, Meter, WorkDefinition, WorkGroup, WorkSchedule } from '../types.ts'

import { computed, reactive, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SelectField from './SelectField.vue'
import { errorMessage, keyFrom } from '../format.ts'
import { t } from '../l10n.ts'
import { createWorkDefinition, updateWorkDefinition } from '../services/api.ts'

const props = defineProps<{
	open: boolean
	assetUuid: string
	task?: WorkDefinition | null
	meters: Meter[]
	components: Component[]
	groups: WorkGroup[]
}>()
const emit = defineEmits<{ (event: 'update:open', value: boolean): void, (event: 'saved', task: WorkDefinition): void }>()

type Repeat = 'none' | 'time' | 'meter' | 'either'
const draft = reactive({
	title: '',
	kind: 'maintenance' as string | null,
	repeat: 'time' as Repeat | null,
	every: '12',
	unit: 'month' as CalendarScheduleUnit | null,
	meterUuid: null as string | null,
	meterEvery: '',
	componentUuid: null as string | null,
	groupUuid: null as string | null,
	instructions: '',
})
const error = ref('')
const busy = ref(false)

const kindOptions = [
	{ value: 'maintenance', label: t('maintenance_tracker', 'Maintenance') },
	{ value: 'inspection', label: t('maintenance_tracker', 'Inspection') },
	{ value: 'replacement', label: t('maintenance_tracker', 'Replacement') },
	{ value: 'cleaning', label: t('maintenance_tracker', 'Cleaning') },
	{ value: 'calibration', label: t('maintenance_tracker', 'Calibration') },
	{ value: 'other', label: t('maintenance_tracker', 'Other') },
]
const repeatOptions = computed(() => [
	{ value: 'time', label: t('maintenance_tracker', 'Every so often (time)') },
	...(props.meters.length > 0
		? [
				{ value: 'meter', label: t('maintenance_tracker', 'By meter reading (distance, hours, uses)') },
				{ value: 'either', label: t('maintenance_tracker', 'Time or meter, whichever comes first') },
			]
		: []),
	{ value: 'none', label: t('maintenance_tracker', 'No schedule (log it when needed)') },
])
const unitOptions = [
	{ value: 'day', label: t('maintenance_tracker', 'days') },
	{ value: 'week', label: t('maintenance_tracker', 'weeks') },
	{ value: 'month', label: t('maintenance_tracker', 'months') },
	{ value: 'year', label: t('maintenance_tracker', 'years') },
]
const meterOptions = computed(() => props.meters.map((meter) => ({ value: meter.uuid, label: `${meter.name} (${meter.displayUnit})` })))
const componentOptions = computed(() => props.components.map((component) => ({ value: component.uuid, label: component.name })))
const groupOptions = computed(() => props.groups.map((group) => ({ value: group.uuid, label: group.name })))
const selectedMeter = computed(() => props.meters.find((meter) => meter.uuid === draft.meterUuid) ?? null)
const usesTime = computed(() => draft.repeat === 'time' || draft.repeat === 'either')
const usesMeter = computed(() => draft.repeat === 'meter' || draft.repeat === 'either')
const isBusinessDays = computed(() => props.task?.schedule !== 'none' && props.task?.schedule.rules.some((rule) => rule.type === 'business_days'))

watch(() => props.open, (open) => {
	if (!open) {
		return
	}
	error.value = ''
	const task = props.task
	const schedule = task?.schedule ?? 'none'
	const calendar = schedule === 'none' ? undefined : schedule.rules.find((rule) => rule.type === 'calendar')
	const meter = schedule === 'none' ? undefined : schedule.rules.find((rule) => rule.type === 'meter')
	Object.assign(draft, {
		title: task?.title ?? '',
		kind: task?.kind ?? 'maintenance',
		repeat: task === null || task === undefined ? 'time' : (calendar && meter ? 'either' : calendar ? 'time' : meter ? 'meter' : 'none'),
		every: calendar ? String(calendar.interval.value) : '12',
		unit: calendar ? calendar.interval.unit : 'month',
		meterUuid: meter ? meter.meterUuid : (props.meters[0]?.uuid ?? null),
		meterEvery: meter ? String(meter.interval.value) : '',
		componentUuid: task?.componentUuid ?? null,
		groupUuid: task?.groupUuid ?? null,
		instructions: task?.instructions ?? '',
	})
}, { immediate: true })

function schedule(): WorkSchedule {
	if (draft.repeat === 'none' || draft.repeat === null) {
		return 'none'
	}
	const rules: Exclude<WorkSchedule, 'none'>['rules'] = []
	if (usesTime.value) {
		rules.push({ type: 'calendar', interval: { value: Number(draft.every), unit: draft.unit ?? 'month' } })
	}
	if (usesMeter.value && selectedMeter.value !== null) {
		rules.push({ type: 'meter', meterUuid: selectedMeter.value.uuid, interval: { value: draft.meterEvery.trim(), unit: selectedMeter.value.displayUnit } })
	}
	return { combination: 'any', rules }
}

async function submit(): Promise<void> {
	if (draft.title.trim() === '') {
		error.value = t('maintenance_tracker', 'Enter what needs to be done.')
		return
	}
	if (usesTime.value && !/^[1-9]\d*$/.test(draft.every.trim())) {
		error.value = t('maintenance_tracker', 'Enter how often as a whole number.')
		return
	}
	if (usesMeter.value && (selectedMeter.value === null || draft.meterEvery.trim() === '')) {
		error.value = t('maintenance_tracker', 'Choose a meter and enter the interval.')
		return
	}
	busy.value = true
	error.value = ''
	try {
		const common = {
			title: draft.title.trim(),
			kind: draft.kind ?? 'maintenance',
			groupUuid: draft.groupUuid,
			instructions: draft.instructions.trim() || null,
		}
		const saved = props.task
			? await updateWorkDefinition(props.task.uuid, props.task.revision, isBusinessDays.value ? common : { ...common, schedule: schedule() })
			: await createWorkDefinition(props.assetUuid, { ...common, uuid: crypto.randomUUID(), key: keyFrom(draft.title), componentUuid: draft.componentUuid, schedule: schedule() })
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
	{ label: props.task ? t('maintenance_tracker', 'Save') : t('maintenance_tracker', 'Add task'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value },
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="task ? t('maintenance_tracker', 'Edit task') : t('maintenance_tracker', 'Add maintenance task')"
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
				v-model="draft.title"
				:label="t('maintenance_tracker', 'Task')"
				:helperText="t('maintenance_tracker', 'For example “Oil change”, “Replace furnace filter”, “Test smoke alarms”.')"
				maxlength="255"
				required />
			<SelectField v-model="draft.kind" :options="kindOptions" :label="t('maintenance_tracker', 'Type')" />
			<p v-if="isBusinessDays" class="muted">
				{{ t('maintenance_tracker', 'This task repeats on business days. Its schedule is kept as it is.') }}
			</p>
			<template v-else>
				<SelectField v-model="draft.repeat" :options="repeatOptions" :label="t('maintenance_tracker', 'Repeat')" />
				<div v-if="usesTime" class="form-row">
					<NcTextField v-model="draft.every" :label="t('maintenance_tracker', 'Every')" inputmode="numeric" />
					<SelectField v-model="draft.unit" :options="unitOptions" :label="t('maintenance_tracker', 'Unit')" />
				</div>
				<div v-if="usesMeter" class="form-row">
					<SelectField v-model="draft.meterUuid" :options="meterOptions" :label="t('maintenance_tracker', 'Meter')" />
					<NcTextField
						v-model="draft.meterEvery"
						:label="selectedMeter ? t('maintenance_tracker', 'Every ({unit})', { unit: selectedMeter.displayUnit }) : t('maintenance_tracker', 'Every')"
						inputmode="decimal" />
				</div>
				<p v-if="meters.length === 0" class="muted">
					{{ t('maintenance_tracker', 'To schedule by distance or hours, add a meter on the item\'s Readings tab first.') }}
				</p>
			</template>
			<SelectField
				v-if="!task && components.length > 0"
				v-model="draft.componentUuid"
				:options="componentOptions"
				:label="t('maintenance_tracker', 'Applies to component (optional)')"
				clearable />
			<SelectField
				v-if="groups.length > 0"
				v-model="draft.groupUuid"
				:options="groupOptions"
				:label="t('maintenance_tracker', 'Group (optional)')"
				clearable />
			<NcTextArea v-model="draft.instructions" :label="t('maintenance_tracker', 'Instructions (optional)')" resize="vertical" />
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

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
