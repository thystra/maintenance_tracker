<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// NcSelect bound to a plain value instead of an option object.
import { computed } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export interface SelectOption {
	value: string
	label: string
}

const props = withDefaults(defineProps<{
	modelValue: string | null
	options: SelectOption[]
	label: string
	clearable?: boolean
	disabled?: boolean
	required?: boolean
	placeholder?: string
	wrapLabels?: boolean
}>(), { clearable: false, disabled: false, required: false, placeholder: '', wrapLabels: false })

const emit = defineEmits<{ (event: 'update:modelValue', value: string | null): void }>()

const selected = computed({
	get: () => props.options.find((option) => option.value === props.modelValue) ?? null,
	set: (option: SelectOption | null) => emit('update:modelValue', option?.value ?? null),
})
// NcSelect's own `label` prop names the option field that holds the text.
const optionLabelKey = 'label'
</script>

<template>
	<NcSelect
		v-model="selected"
		class="select-field"
		:class="{ 'select-field--wrap': wrapLabels }"
		:options="options"
		:inputLabel="label"
		:clearable="clearable"
		:disabled="disabled"
		:required="required && modelValue === null"
		:placeholder="placeholder"
		:label="optionLabelKey">
		<!-- Opt in where labels must be readable in full, rather than using
		     NcSelect's default middle-ellipsised option renderer. -->
		<template v-if="wrapLabels" #option="option">
			<span class="select-field__label">{{ option.label }}</span>
		</template>
		<template v-if="wrapLabels" #selected-option="option">
			<span class="select-field__label">{{ option.label }}</span>
		</template>
	</NcSelect>
</template>

<style scoped>
.select-field {
	width: 100%;
	min-width: 0 !important;
}

.select-field__label {
	display: block;
	min-width: 0;
	white-space: normal;
	overflow-wrap: anywhere;
}

.select-field--wrap.vs--single :deep(.vs__selected) {
	/* Also keep the selected label in flow while the dropdown is open. */
	position: static;
	flex: 1;
	height: auto;
	padding-inline: 0;
	min-height: calc(var(--default-clickable-area) - 2 * var(--vs-border-width) - var(--default-grid-baseline));
	white-space: normal;
}

.select-field--wrap.vs--single:not(.vs--searching) :deep(.vs__search) {
	/* The empty search input must not reserve space beside the full label.
	   Keep it focusable; normal input layout resumes as soon as typing starts. */
	position: absolute;
	inset: 0;
	width: 100%;
	margin: 0;
	padding: 0;
}

.select-field--wrap :deep(.vs__dropdown-option) {
	white-space: normal;
}
</style>
