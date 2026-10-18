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
}>(), { clearable: false, disabled: false, required: false, placeholder: '' })

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
		:options="options"
		:inputLabel="label"
		:clearable="clearable"
		:disabled="disabled"
		:required="required && modelValue === null"
		:placeholder="placeholder"
		:label="optionLabelKey" />
</template>

<style scoped>
.select-field {
	width: 100%;
	min-width: 0 !important;
}
</style>
