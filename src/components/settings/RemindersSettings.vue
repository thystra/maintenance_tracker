<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Personal delivery: notifications and calendar entries for due work.
import type { IntegrationSettings, WritableCalendar } from '../../integrationTypes.ts'

import { computed, onMounted, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import SelectField from '../SelectField.vue'
import { errorMessage } from '../../format.ts'
import { t } from '../../l10n.ts'
import { getIntegrationSettings, listWritableCalendars, updateIntegrationSettings } from '../../services/integrationApi.ts'

const settings = ref<IntegrationSettings | null>(null)
const calendars = ref<WritableCalendar[]>([])
const busy = ref(false)
const error = ref('')
const status = ref('')

const calendarOptions = computed(() => calendars.value.map((calendar) => ({ value: calendar.uri, label: calendar.displayName })))

async function save(change: Partial<Pick<IntegrationSettings, 'reminders' | 'calendarUri'>>): Promise<void> {
	busy.value = true
	error.value = ''
	status.value = ''
	try {
		settings.value = await updateIntegrationSettings(change)
		status.value = t('maintenance_tracker', 'Saved. Reminders and calendar entries are delivered by the Nextcloud background job.')
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

onMounted(async () => {
	try {
		settings.value = await getIntegrationSettings()
		calendars.value = settings.value.calendarAvailable ? await listWritableCalendars() : []
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
			{{ t('maintenance_tracker', 'These are your personal choices and apply to all your workspaces.') }}
		</p>
		<template v-if="settings">
			<NcCheckboxRadioSwitch
				:modelValue="settings.reminders"
				type="switch"
				:disabled="busy"
				@update:modelValue="save({ reminders: $event })">
				{{ t('maintenance_tracker', 'Send me Nextcloud notifications when maintenance is due soon, due, or overdue') }}
			</NcCheckboxRadioSwitch>
			<template v-if="settings.calendarAvailable">
				<div class="row narrow">
					<SelectField
						:modelValue="settings.calendarUri"
						:options="calendarOptions"
						:label="t('maintenance_tracker', 'Add due dates to calendar')"
						:placeholder="t('maintenance_tracker', 'Do not add calendar entries')"
						:disabled="busy"
						clearable
						@update:modelValue="save({ calendarUri: $event })" />
				</div>
				<p class="muted">
					{{ t('maintenance_tracker', 'Only your own writable calendars are listed. Create a dedicated calendar such as “Maintenance” in the Calendar app first if you prefer. Entries are titled “Maintenance due” without task or asset names, are added once per due date, and are not removed automatically after the work is done. Tasks that are due only by meter readings have no date and are not added.') }}
				</p>
			</template>
			<p v-else class="muted">
				{{ t('maintenance_tracker', 'Calendar integration is unavailable because the Nextcloud calendar backend is disabled.') }}
			</p>
			<p class="muted">
				{{ t('maintenance_tracker', 'Workspace activity appears in the Nextcloud Activity app under “Maintenance”.') }}
			</p>
		</template>
	</div>
</template>
