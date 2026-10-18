<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { t } from '../l10n.ts'

const props = withDefaults(defineProps<{ open: boolean, name: string, message: string, confirmLabel?: string, busy?: boolean }>(), { confirmLabel: '', busy: false })
const emit = defineEmits<{ (event: 'update:open', value: boolean): void, (event: 'confirm'): void }>()

const buttons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => {
		emit('update:open', false)
		return false
	} },
	{ label: props.confirmLabel || t('maintenance_tracker', 'Delete'), variant: 'error' as const, disabled: props.busy, callback: () => {
		emit('confirm')
		return false
	} },
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="name"
		:message="message"
		:buttons="buttons"
		size="small"
		@update:open="emit('update:open', $event)" />
</template>
