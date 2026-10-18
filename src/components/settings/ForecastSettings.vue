<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// When "due soon" starts: lead days for dated tasks, lead percent for meters.
import type { ReminderPolicy } from '../../types.ts'

import { onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage } from '../../format.ts'
import { t } from '../../l10n.ts'
import { getReminderPolicy, updateReminderPolicy } from '../../services/api.ts'
import { can } from '../../store.ts'

const policy = ref<ReminderPolicy | null>(null)
const draft = reactive({ calendarLeadDays: '', meterLeadPercent: '' })
const busy = ref(false)
const error = ref('')
const status = ref('')

function apply(value: ReminderPolicy): void {
	policy.value = value
	draft.calendarLeadDays = String(value.calendarLeadDays)
	draft.meterLeadPercent = String(value.meterLeadPercent)
}

async function save(): Promise<void> {
	busy.value = true
	error.value = ''
	status.value = ''
	try {
		apply(await updateReminderPolicy(policy.value?.revision ?? 0, { calendarLeadDays: Number(draft.calendarLeadDays), meterLeadPercent: Number(draft.meterLeadPercent) }))
		status.value = t('maintenance_tracker', 'Saved.')
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

onMounted(async () => {
	try {
		apply(await getReminderPolicy())
	} catch (reason) {
		error.value = errorMessage(reason)
	}
})
</script>

<template>
	<div class="mt-section">
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="status" type="success">
			<p role="status">
				{{ status }}
			</p>
		</NcNoteCard>
		<p class="muted">
			{{ t('maintenance_tracker', 'Decides when a task shows as “due soon” on the dashboard and in reminders, for everyone in this workspace.') }}
		</p>
		<form v-if="policy" class="inline-form" @submit.prevent="save">
			<div class="row">
				<NcTextField
					v-model="draft.calendarLeadDays"
					:label="t('maintenance_tracker', 'Days before a due date')"
					inputmode="numeric"
					:disabled="!can.manageForecastPolicy"
					required />
				<NcTextField
					v-model="draft.meterLeadPercent"
					:label="t('maintenance_tracker', 'Percent of a meter interval left')"
					inputmode="numeric"
					:disabled="!can.manageForecastPolicy"
					required />
			</div>
			<p class="muted">
				{{ t('maintenance_tracker', 'Example: with 10 percent, an oil change every 5000 km shows as due soon in the last 500 km.') }}
				<template v-if="policy.source === 'default'">
					{{ t('maintenance_tracker', 'These are the defaults.') }}
				</template>
			</p>
			<NcButton
				v-if="can.manageForecastPolicy"
				type="submit"
				variant="primary"
				:disabled="busy">
				{{ t('maintenance_tracker', 'Save') }}
			</NcButton>
		</form>
	</div>
</template>
