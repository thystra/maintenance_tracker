<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts" generic="Row extends object">
// One list of a profile (meters, components, tasks, ...) as a stack of
// fieldsets with add, move and remove. Field definitions drive the inputs;
// the #extra slot adds anything a list needs beyond them.
import type { SelectOption } from '../SelectField.vue'

import { mdiArrowDown, mdiArrowUp, mdiDeleteOutline, mdiPlus } from '@mdi/js'
import { nextTick, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SelectField from '../SelectField.vue'
import MultiSelectField from './MultiSelectField.vue'
import { t } from '../../l10n.ts'

export interface RowField {
	key: string
	label: string
	type: 'text' | 'textarea' | 'number' | 'checkbox' | 'select' | 'multiselect'
	options?: SelectOption[] | ((row: Record<string, unknown>) => SelectOption[])
	clearable?: boolean
	wide?: boolean
	placeholder?: string
}

const rows = defineModel<Row[]>({ required: true })
const props = defineProps<{
	fields: RowField[]
	itemName: string
	addLabel: string
	emptyText: string
	titleOf: (row: Row) => string
	create: () => Row
}>()
const emit = defineEmits<{ (event: 'edited', row: Row, key: string): void }>()
const container = useTemplateRef<HTMLElement>('container')

// Stable v-for keys that survive reordering.
const ids = new WeakMap<object, number>()
let nextId = 1
function idOf(row: Row): number {
	let id = ids.get(row)
	if (id === undefined) {
		id = nextId++
		ids.set(row, id)
	}
	return id
}

const record = (row: Row): Record<string, unknown> => row as Record<string, unknown>
function text(row: Row, key: string): string {
	const value = record(row)[key]
	return value === undefined || value === null ? '' : String(value)
}
const list = (row: Row, key: string): string[] => (Array.isArray(record(row)[key]) ? record(row)[key] as string[] : [])
const optionsFor = (field: RowField, row: Row): SelectOption[] => typeof field.options === 'function' ? field.options(record(row)) : (field.options ?? [])

function set(row: Row, field: RowField, value: unknown): void {
	emit('edited', row, field.key)
	if (field.type === 'number') {
		const parsed = Number(value)
		record(row)[field.key] = value === '' || !Number.isFinite(parsed) ? value : parsed
		return
	}
	record(row)[field.key] = value ?? undefined
}

async function add(): Promise<void> {
	rows.value.push(props.create())
	await nextTick()
	const fieldsets = container.value?.querySelectorAll<HTMLElement>('fieldset.profile-row')
	fieldsets?.[fieldsets.length - 1]?.querySelector<HTMLElement>('input, textarea')?.focus()
}

function move(index: number, by: number): void {
	const target = index + by
	if (target < 0 || target >= rows.value.length) {
		return
	}
	const [row] = rows.value.splice(index, 1)
	rows.value.splice(target, 0, row!)
}

function remove(index: number): void {
	rows.value.splice(index, 1)
}
</script>

<template>
	<div ref="container" class="profile-rows">
		<p v-if="rows.length === 0" class="profile-rows__empty">
			{{ emptyText }}
		</p>
		<fieldset v-for="(row, index) in rows" :key="idOf(row)" class="profile-row">
			<legend>{{ titleOf(row) || t('maintenance_tracker', '{item} {number}', { item: itemName, number: index + 1 }) }}</legend>
			<div class="profile-row__fields">
				<template v-for="field in fields" :key="field.key">
					<NcTextField
						v-if="field.type === 'text' || field.type === 'number'"
						:class="{ wide: field.wide }"
						:type="field.type === 'number' ? 'number' : 'text'"
						:label="field.label"
						:placeholder="field.placeholder"
						:modelValue="text(row, field.key)"
						@update:modelValue="set(row, field, $event)" />
					<NcTextArea
						v-else-if="field.type === 'textarea'"
						class="wide"
						resize="vertical"
						:label="field.label"
						:modelValue="text(row, field.key)"
						@update:modelValue="set(row, field, $event)" />
					<NcCheckboxRadioSwitch
						v-else-if="field.type === 'checkbox'"
						class="profile-row__check"
						:modelValue="record(row)[field.key] === true"
						@update:modelValue="set(row, field, $event)">
						{{ field.label }}
					</NcCheckboxRadioSwitch>
					<SelectField
						v-else-if="field.type === 'select'"
						:class="{ wide: field.wide }"
						:label="field.label"
						:clearable="field.clearable"
						:options="optionsFor(field, row)"
						:modelValue="text(row, field.key) || null"
						@update:modelValue="set(row, field, $event)" />
					<MultiSelectField
						v-else
						class="wide"
						:label="field.label"
						:options="optionsFor(field, row)"
						:modelValue="list(row, field.key)"
						@update:modelValue="set(row, field, $event)" />
				</template>
			</div>
			<slot name="extra" :row="row" :index="index" />
			<div class="profile-row__actions">
				<NcButton
					variant="tertiary"
					:disabled="index === 0"
					:aria-label="t('maintenance_tracker', 'Move {name} up', { name: titleOf(row) || itemName })"
					@click="move(index, -1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowUp" />
					</template>
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="index === rows.length - 1"
					:aria-label="t('maintenance_tracker', 'Move {name} down', { name: titleOf(row) || itemName })"
					@click="move(index, 1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowDown" />
					</template>
				</NcButton>
				<NcButton variant="tertiary" @click="remove(index)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDeleteOutline" />
					</template>
					{{ t('maintenance_tracker', 'Remove') }}
					<span class="hidden-visually">{{ titleOf(row) || itemName }}</span>
				</NcButton>
			</div>
		</fieldset>
		<NcButton @click="add">
			<template #icon>
				<NcIconSvgWrapper :path="mdiPlus" />
			</template>
			{{ addLabel }}
		</NcButton>
	</div>
</template>

<style scoped>
.profile-rows__empty {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
}

.profile-row {
	margin: 0 0 12px;
	padding: 8px 12px 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.profile-row legend {
	padding: 0 4px;
	font-weight: bold;
}

.profile-row__fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 8px 12px;
	align-items: end;
}

.profile-row__fields .wide {
	grid-column: 1 / -1;
}

.profile-row__check {
	align-self: center;
}

.profile-row__actions {
	display: flex;
	justify-content: flex-end;
	gap: 4px;
	margin-top: 4px;
}
</style>
