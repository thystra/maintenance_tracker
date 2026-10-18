<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// NcSelect (multiple) bound to a list of plain values.
import type { SelectOption } from '../SelectField.vue'

import { computed } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'

const props = withDefaults(defineProps<{
	modelValue: string[]
	options: SelectOption[]
	label: string
	placeholder?: string
}>(), { placeholder: '' })

const emit = defineEmits<{ (event: 'update:modelValue', value: string[]): void }>()

const selected = computed({
	get: () => props.options.filter((option) => props.modelValue.includes(option.value)),
	set: (options: SelectOption[] | null) => emit('update:modelValue', (options ?? []).map((option) => option.value)),
})
const optionLabelKey = 'label'
</script>

<template>
	<NcSelect
		v-model="selected"
		class="multi-select-field"
		multiple
		keepOpen
		:options="options"
		:inputLabel="label"
		:placeholder="placeholder"
		:label="optionLabelKey" />
</template>

<style scoped>
.multi-select-field {
	width: 100%;
	min-width: 0 !important;
}
</style>
