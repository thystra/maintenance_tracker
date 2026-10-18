<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Schedule of one profile task: no repeat, or rules of which the first one
// reached makes the task due (calendar, business days, meter).
import type { ProfileMeter, ProfileRule, ProfileSchedule } from '../../profileTypes.ts'

import { mdiDeleteOutline, mdiPlus } from '@mdi/js'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SelectField from '../SelectField.vue'
import MultiSelectField from './MultiSelectField.vue'
import { meterUnits } from '../../format.ts'
import { t } from '../../l10n.ts'

const schedule = defineModel<ProfileSchedule>({ required: true })
const props = defineProps<{ meters: ProfileMeter[], taskName: string }>()
const rules = computed<ProfileRule[]>(() => schedule.value === 'none' ? [] : schedule.value.rules)

const typeOptions = computed(() => [
	{ value: 'calendar', label: t('maintenance_tracker', 'Every so often (time)') },
	{ value: 'business_days', label: t('maintenance_tracker', 'Every so many business days') },
	...(props.meters.length > 0 ? [{ value: 'meter', label: t('maintenance_tracker', 'By meter reading') }] : []),
])
const calendarUnits = [
	{ value: 'day', label: t('maintenance_tracker', 'days') },
	{ value: 'week', label: t('maintenance_tracker', 'weeks') },
	{ value: 'month', label: t('maintenance_tracker', 'months') },
	{ value: 'year', label: t('maintenance_tracker', 'years') },
]
const weekdayOptions = [
	{ value: 'mon', label: t('maintenance_tracker', 'Monday') },
	{ value: 'tue', label: t('maintenance_tracker', 'Tuesday') },
	{ value: 'wed', label: t('maintenance_tracker', 'Wednesday') },
	{ value: 'thu', label: t('maintenance_tracker', 'Thursday') },
	{ value: 'fri', label: t('maintenance_tracker', 'Friday') },
	{ value: 'sat', label: t('maintenance_tracker', 'Saturday') },
	{ value: 'sun', label: t('maintenance_tracker', 'Sunday') },
]
const meterOptions = computed(() => props.meters.filter((meter) => meter.key !== '' || meter.name !== '').map((meter) => ({ value: meter.key, label: meter.name || meter.key })))

function emit(next: ProfileRule[]): void {
	schedule.value = next.length === 0 ? 'none' : { combination: 'any', rules: next }
}

function blank(type: string): ProfileRule {
	if (type === 'business_days') {
		return { type: 'business_days', interval: { value: 30, unit: 'business_day' }, weekdays: ['mon', 'tue', 'wed', 'thu', 'fri'] }
	}
	if (type === 'meter') {
		const meter = props.meters[0]
		return { type: 'meter', meterKey: meter?.key ?? '', interval: { value: '1000', unit: meter?.displayUnit ?? 'mi' } }
	}
	return { type: 'calendar', interval: { value: 12, unit: 'month' } }
}

function change(index: number, patch: (rule: ProfileRule) => ProfileRule): void {
	emit(rules.value.map((rule, i) => i === index ? patch(structuredClone(rule)) : rule))
}

function unitOptions(rule: ProfileRule): { value: string, label: string }[] {
	if (rule.type === 'calendar') {
		return calendarUnits
	}
	if (rule.type === 'business_days') {
		return [{ value: 'business_day', label: t('maintenance_tracker', 'business days') }]
	}
	const meter = props.meters.find((candidate) => candidate.key === rule.meterKey)
	return meterUnits(meter?.dimension ?? 'distance').map((value) => ({ value, label: value }))
}

function setMeter(index: number, key: string | null): void {
	const meter = props.meters.find((candidate) => candidate.key === key)
	change(index, (rule) => rule.type === 'meter' ? { ...rule, meterKey: key ?? '', interval: { value: rule.interval.value, unit: meter?.displayUnit ?? rule.interval.unit } } : rule)
}

function setValue(index: number, value: string): void {
	change(index, (rule) => {
		rule.interval.value = rule.type === 'meter' ? value : (value === '' ? value : Number(value))
		return rule
	})
}
</script>

<template>
	<fieldset class="schedule">
		<legend>{{ t('maintenance_tracker', 'Repeats') }}</legend>
		<p v-if="rules.length === 0" class="schedule__hint">
			{{ t('maintenance_tracker', 'Does not repeat. Add a rule to make it recurring.') }}
		</p>
		<p v-else-if="rules.length > 1" class="schedule__hint">
			{{ t('maintenance_tracker', 'Due when the first of these is reached.') }}
		</p>
		<div v-for="(rule, index) in rules" :key="index" class="schedule__rule">
			<SelectField
				:label="t('maintenance_tracker', 'Rule')"
				:options="typeOptions"
				:modelValue="rule.type"
				@update:modelValue="emit(rules.map((r, i) => i === index ? blank($event ?? 'calendar') : r))" />
			<SelectField
				v-if="rule.type === 'meter'"
				:label="t('maintenance_tracker', 'Meter')"
				:options="meterOptions"
				:modelValue="rule.meterKey || null"
				@update:modelValue="setMeter(index, $event)" />
			<NcTextField
				:label="t('maintenance_tracker', 'Every')"
				:type="rule.type === 'meter' ? 'text' : 'number'"
				:inputmode="rule.type === 'meter' ? 'decimal' : 'numeric'"
				:modelValue="String(rule.interval.value)"
				@update:modelValue="setValue(index, String($event))" />
			<SelectField
				:label="t('maintenance_tracker', 'Unit')"
				:options="unitOptions(rule)"
				:modelValue="rule.interval.unit"
				@update:modelValue="change(index, (r) => { r.interval.unit = $event ?? r.interval.unit; return r })" />
			<MultiSelectField
				v-if="rule.type === 'business_days'"
				class="schedule__weekdays"
				:label="t('maintenance_tracker', 'Working days')"
				:options="weekdayOptions"
				:modelValue="rule.weekdays"
				@update:modelValue="change(index, (r) => r.type === 'business_days' ? { ...r, weekdays: $event } : r)" />
			<NcButton
				variant="tertiary"
				class="schedule__remove"
				:aria-label="t('maintenance_tracker', 'Remove rule {number} of {task}', { number: index + 1, task: taskName })"
				@click="emit(rules.filter((_, i) => i !== index))">
				<template #icon>
					<NcIconSvgWrapper :path="mdiDeleteOutline" />
				</template>
			</NcButton>
		</div>
		<NcButton v-if="rules.length < 8" variant="secondary" @click="emit([...rules, blank(rules.length === 0 ? 'calendar' : (meters.length > 0 ? 'meter' : 'calendar'))])">
			<template #icon>
				<NcIconSvgWrapper :path="mdiPlus" />
			</template>
			{{ t('maintenance_tracker', 'Add rule') }}
		</NcButton>
	</fieldset>
</template>

<style scoped>
.schedule {
	margin: 8px 0 0;
	padding: 4px 8px 8px;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large);
}

.schedule legend {
	padding: 0 4px;
}

.schedule__hint {
	margin: 0 0 6px;
	color: var(--color-text-maxcontrast);
}

.schedule__rule {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 8px;
}

.schedule__rule > * {
	flex: 1 1 160px;
}

.schedule__rule > .schedule__remove {
	flex: 0 0 auto;
}

.schedule__rule > .schedule__weekdays {
	flex-basis: 100%;
}
</style>
