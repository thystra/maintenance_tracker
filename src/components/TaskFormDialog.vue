<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Add or edit a maintenance task and its repeat limits. Multiple limits use
// combination:any (due when the earliest is reached)—same as profile schedules.
import type { CalendarScheduleUnit, Component, Meter, WorkDefinition, WorkGroup, WorkSchedule, WorkScheduleRule } from '../types.ts'

import { mdiDeleteOutline, mdiPlus } from '@mdi/js'
import { computed, reactive, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
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

type LimitKind = 'time' | 'meter'
interface LimitRow {
	id: string
	kind: LimitKind
	every: string
	unit: CalendarScheduleUnit | null
	meterUuid: string | null
	meterEvery: string
}

const draft = reactive({
	title: '',
	kind: 'maintenance' as string | null,
	limits: [] as LimitRow[],
	componentUuid: null as string | null,
	groupUuid: null as string | null,
	instructions: '',
	nextDueOn: '',
	nextDueMeter: '',
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
const limitKindOptions = computed(() => [
	{ value: 'time', label: t('maintenance_tracker', 'Every so often (time)') },
	...(props.meters.length > 0
		? [{ value: 'meter', label: t('maintenance_tracker', 'By meter reading (distance, hours, uses)') }]
		: []),
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

const usesTime = computed(() => draft.limits.some((limit) => limit.kind === 'time'))
const usesMeter = computed(() => draft.limits.some((limit) => limit.kind === 'meter'))
const selectedMeter = computed(() => {
	const meterLimit = draft.limits.find((limit) => limit.kind === 'meter')
	return props.meters.find((meter) => meter.uuid === meterLimit?.meterUuid) ?? null
})
const isBusinessDays = computed(() => props.task?.schedule !== 'none' && props.task?.schedule.rules.some((rule) => rule.type === 'business_days'))
const canAddLimit = computed(() => !isBusinessDays.value && draft.limits.length < 8)
const addLimitLabel = computed(() => {
	if (draft.limits.length === 0) {
		return t('maintenance_tracker', 'Add limit')
	}
	return t('maintenance_tracker', 'Add another limit')
})

function newId(): string {
	return crypto.randomUUID()
}

function blankLimit(kind: LimitKind): LimitRow {
	const meter = props.meters[0]
	return {
		id: newId(),
		kind,
		every: '3',
		unit: 'month',
		meterUuid: meter?.uuid ?? null,
		meterEvery: meter?.dimension === 'distance' ? '5000' : '100',
	}
}

function limitsFromSchedule(schedule: WorkDefinition['schedule']): LimitRow[] {
	if (schedule === 'none') {
		return []
	}
	const rows: LimitRow[] = []
	for (const rule of schedule.rules) {
		if (rule.type === 'calendar') {
			rows.push({
				id: newId(),
				kind: 'time',
				every: String(rule.interval.value),
				unit: rule.interval.unit,
				meterUuid: props.meters[0]?.uuid ?? null,
				meterEvery: '',
			})
		} else if (rule.type === 'meter') {
			const meter = props.meters.find((candidate) => candidate.uuid === rule.meterUuid)
			rows.push({
				id: newId(),
				kind: 'meter',
				every: '12',
				unit: 'month',
				meterUuid: rule.meterUuid,
				meterEvery: String(rule.interval.value),
			})
			if (meter === undefined) {
				// Keep the stored meter UUID even if the list has not loaded yet.
			}
		}
		// business_days stays read-only via isBusinessDays; skip editing rows.
	}
	return rows
}

function setLimitKind(index: number, kind: string | null): void {
	const next = kind === 'meter' ? 'meter' : 'time'
	const current = draft.limits[index]
	if (current === undefined || current.kind === next) {
		return
	}
	draft.limits[index] = { ...blankLimit(next), id: current.id }
}

function removeLimit(index: number): void {
	draft.limits.splice(index, 1)
}

function addLimit(): void {
	const hasTime = draft.limits.some((limit) => limit.kind === 'time')
	const hasMeter = draft.limits.some((limit) => limit.kind === 'meter')
	if (draft.limits.length === 0) {
		draft.limits.push(blankLimit('time'))
		return
	}
	if (!hasMeter && props.meters.length > 0) {
		draft.limits.push(blankLimit('meter'))
		return
	}
	if (!hasTime) {
		draft.limits.push(blankLimit('time'))
		return
	}
	if (props.meters.length > 0) {
		draft.limits.push(blankLimit('meter'))
	}
}

function meterFor(limit: LimitRow): Meter | null {
	return props.meters.find((meter) => meter.uuid === limit.meterUuid) ?? null
}

function meterEveryLabel(limit: LimitRow): string {
	const meter = meterFor(limit)
	return meter
		? t('maintenance_tracker', 'Every ({unit})', { unit: meter.displayUnit })
		: t('maintenance_tracker', 'Every')
}

watch(() => props.open, (open) => {
	if (!open) {
		return
	}
	error.value = ''
	const task = props.task
	const schedule = task?.schedule ?? 'none'
	Object.assign(draft, {
		title: task?.title ?? '',
		kind: task?.kind ?? 'maintenance',
		limits: task === null || task === undefined
			? [blankLimit('time')]
			: limitsFromSchedule(schedule),
		componentUuid: task?.componentUuid ?? null,
		groupUuid: task?.groupUuid ?? null,
		instructions: task?.instructions ?? '',
		nextDueOn: task?.nextDue?.dueOn ?? '',
		nextDueMeter: '',
	})
}, { immediate: true })

function schedule(): WorkSchedule {
	if (draft.limits.length === 0) {
		return 'none'
	}
	const rules: WorkScheduleRule[] = []
	for (const limit of draft.limits) {
		if (limit.kind === 'time') {
			rules.push({ type: 'calendar', interval: { value: Number(limit.every), unit: limit.unit ?? 'month' } })
			continue
		}
		const meter = meterFor(limit)
		if (meter === null) {
			continue
		}
		rules.push({
			type: 'meter',
			meterUuid: meter.uuid,
			interval: { value: limit.meterEvery.trim(), unit: meter.displayUnit },
		})
	}
	if (rules.length === 0) {
		return 'none'
	}
	return { combination: 'any', rules }
}

async function submit(): Promise<void> {
	if (draft.title.trim() === '') {
		error.value = t('maintenance_tracker', 'Enter what needs to be done.')
		return
	}
	for (const limit of draft.limits) {
		if (limit.kind === 'time' && !/^[1-9]\d*$/.test(limit.every.trim())) {
			error.value = t('maintenance_tracker', 'Enter how often as a whole number.')
			return
		}
		if (limit.kind === 'meter') {
			const meter = meterFor(limit)
			if (meter === null || limit.meterEvery.trim() === '') {
				error.value = t('maintenance_tracker', 'Choose a meter and enter the interval.')
				return
			}
		}
	}
	busy.value = true
	error.value = ''
	try {
		const nextDue = (() => {
			if (props.task === null || props.task === undefined || draft.limits.length === 0) {
				return undefined
			}
			const dueOn = draft.nextDueOn.trim() === '' ? null : draft.nextDueOn.trim()
			const dueValue = draft.nextDueMeter.trim() === '' ? null : draft.nextDueMeter.trim()
			if (dueOn === null && dueValue === null) {
				return props.task.nextDue ? null : undefined
			}
			const payload: { dueOn: string | null, dueValue?: string, dueUnit?: string, dueCanonicalValue?: number | null } = { dueOn }
			if (dueValue !== null) {
				payload.dueValue = dueValue
				if (selectedMeter.value !== null) {
					payload.dueUnit = selectedMeter.value.displayUnit
				}
			} else if (props.task.nextDue?.dueCanonicalValue !== null && props.task.nextDue?.dueCanonicalValue !== undefined) {
				payload.dueCanonicalValue = null
			}
			return payload
		})()
		const common = {
			title: draft.title.trim(),
			kind: draft.kind ?? 'maintenance',
			groupUuid: draft.groupUuid,
			instructions: draft.instructions.trim() || null,
			...(nextDue !== undefined ? { nextDue } : {}),
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
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => {
		emit('update:open', false)
		return false
	} },
	{ label: props.task ? t('maintenance_tracker', 'Save') : t('maintenance_tracker', 'Add task'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value, callback: () => false },
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
				<fieldset class="limits" data-test="task-limits">
					<legend>{{ t('maintenance_tracker', 'Repeat limits') }}</legend>
					<p v-if="draft.limits.length === 0" class="muted">
						{{ t('maintenance_tracker', 'Does not repeat. Add a limit to make it recurring.') }}
					</p>
					<p v-else-if="draft.limits.length > 1" class="muted">
						{{ t('maintenance_tracker', 'Due when the earliest limit is reached (for example 3 months or 5,000 miles).') }}
					</p>
					<div
						v-for="(limit, index) in draft.limits"
						:key="limit.id"
						class="limit-row"
						:data-test="'task-limit-' + index">
						<SelectField
							:modelValue="limit.kind"
							:options="limitKindOptions"
							:label="t('maintenance_tracker', 'Limit')"
							@update:modelValue="setLimitKind(index, $event)" />
						<template v-if="limit.kind === 'time'">
							<NcTextField v-model="limit.every" :label="t('maintenance_tracker', 'Every')" inputmode="numeric" />
							<SelectField v-model="limit.unit" :options="unitOptions" :label="t('maintenance_tracker', 'Unit')" />
						</template>
						<template v-else>
							<SelectField v-model="limit.meterUuid" :options="meterOptions" :label="t('maintenance_tracker', 'Meter')" />
							<NcTextField
								v-model="limit.meterEvery"
								:label="meterEveryLabel(limit)"
								inputmode="decimal" />
						</template>
						<NcButton
							variant="tertiary"
							class="limit-remove"
							type="button"
							:aria-label="t('maintenance_tracker', 'Remove limit {number}', { number: index + 1 })"
							data-test="task-limit-remove"
							@click="removeLimit(index)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDeleteOutline" />
							</template>
						</NcButton>
					</div>
					<NcButton
						v-if="canAddLimit"
						variant="secondary"
						type="button"
						data-test="task-limit-add"
						@click="addLimit">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPlus" />
						</template>
						{{ addLimitLabel }}
					</NcButton>
					<p v-if="meters.length === 0" class="muted">
						{{ t('maintenance_tracker', 'To schedule by distance or hours, add a meter on the item\'s Readings tab first.') }}
					</p>
				</fieldset>
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
			<div v-if="task && draft.limits.length > 0" class="next-due" data-test="task-next-due">
				<p class="muted">
					{{ t('maintenance_tracker', 'If this task has never been logged, set when it is next due. The dashboard uses these values until the first completion is recorded.') }}
				</p>
				<div class="form-row">
					<label v-if="usesTime" class="field">
						<span>{{ t('maintenance_tracker', 'Next due date') }}</span>
						<input v-model="draft.nextDueOn" type="date" data-test="task-next-due-date">
					</label>
					<NcTextField
						v-if="usesMeter"
						v-model="draft.nextDueMeter"
						:label="selectedMeter ? t('maintenance_tracker', 'Next due ({unit})', { unit: selectedMeter.displayUnit }) : t('maintenance_tracker', 'Next due meter reading')"
						inputmode="decimal" />
				</div>
			</div>
			<NcTextArea v-model="draft.instructions" :label="t('maintenance_tracker', 'Instructions (optional)')" resize="vertical" />
		</div>
	</NcDialog>
</template>

<style scoped>
.field {
	display: grid;
	gap: 4px;
}

.field input {
	width: 100%;
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
	align-items: end;
}

.muted {
	color: var(--color-text-maxcontrast);
}

.limits {
	margin: 0;
	padding: 8px 10px 10px;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large);
}

.limits legend {
	padding: 0 4px;
}

.limit-row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 8px;
}

.limit-row > * {
	flex: 1 1 140px;
}

.limit-row > .limit-remove {
	flex: 0 0 auto;
}
</style>
