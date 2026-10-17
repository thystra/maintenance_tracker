<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { IntegrationSettings, WritableCalendar } from '../integrationTypes.ts'

import { ref } from 'vue'
import { t } from '../l10n.ts'
import { getIntegrationSettings, listWritableCalendars, updateIntegrationSettings } from '../services/integrationApi.ts'
import { ocsMessage } from '../services/workspaceApi.ts'

const open = ref(false)
const settings = ref<IntegrationSettings | null>(null)
const calendars = ref<WritableCalendar[]>([])
const calendarChoice = ref('')
const busy = ref(false)
const error = ref('')
const notice = ref('')

async function load(): Promise<void> {
	error.value = ''
	try {
		settings.value = await getIntegrationSettings()
		calendars.value = settings.value.calendarAvailable ? await listWritableCalendars() : []
		calendarChoice.value = settings.value.calendarUri ?? ''
	} catch (reason) {
		error.value = ocsMessage(reason)
	}
}

async function toggle(): Promise<void> {
	open.value = !open.value
	if (open.value) {
		await load()
	}
}

async function save(change: Partial<Pick<IntegrationSettings, 'reminders' | 'calendarUri'>>): Promise<void> {
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		settings.value = await updateIntegrationSettings(change)
		calendarChoice.value = settings.value.calendarUri ?? ''
		notice.value = t('maintenance_tracker', 'Saved. Reminders and calendar entries are delivered by the Nextcloud background job.')
	} catch (reason) {
		error.value = ocsMessage(reason)
	} finally {
		busy.value = false
	}
}

function onReminders(event: Event): void {
	save({ reminders: (event.target as HTMLInputElement).checked })
}
</script>

<template>
	<section class="panel integrations" aria-labelledby="integrations-heading">
		<div class="integrations__bar">
			<h2 id="integrations-heading">
				{{ t('maintenance_tracker', 'Reminders and calendar') }}
			</h2>
			<button type="button" :aria-expanded="open" @click="toggle">
				{{ open ? t('maintenance_tracker', 'Hide') : t('maintenance_tracker', 'Configure') }}
			</button>
		</div>
		<template v-if="open && settings">
			<p v-if="notice" class="notice" role="status">
				{{ notice }}
			</p>
			<p v-if="error" class="notice notice--error" role="alert">
				{{ error }}
			</p>
			<label class="checkbox">
				<input
					type="checkbox"
					:checked="settings.reminders"
					:disabled="busy"
					@change="onReminders">
				<span>{{ t('maintenance_tracker', 'Send me Nextcloud notifications when maintenance is due soon, due, or overdue') }}</span>
			</label>
			<template v-if="settings.calendarAvailable">
				<label><span>{{ t('maintenance_tracker', 'Add due dates to calendar') }}</span>
					<select v-model="calendarChoice" :disabled="busy" @change="save({ calendarUri: calendarChoice === '' ? null : calendarChoice })">
						<option value="">{{ t('maintenance_tracker', 'Do not add calendar entries') }}</option>
						<option v-for="calendar in calendars" :key="calendar.uri" :value="calendar.uri">{{ calendar.displayName }}</option>
					</select>
				</label>
				<p class="hint">
					{{ t('maintenance_tracker', 'Only your own writable calendars are listed. Create a dedicated calendar such as “Maintenance” in the Calendar app first if you prefer. Entries are titled “Maintenance due” without task or asset names, are added once per due date, and are not removed automatically after the work is done. Tasks that are due only by meter readings have no date and are not added.') }}
				</p>
			</template>
			<p v-else class="hint">
				{{ t('maintenance_tracker', 'Calendar integration is unavailable because the Nextcloud calendar backend is disabled.') }}
			</p>
			<p class="hint">
				{{ t('maintenance_tracker', 'Workspace activity appears in the Nextcloud Activity app under “Maintenance”.') }}
			</p>
		</template>
	</section>
</template>

<style scoped>
.integrations__bar {
	display: flex;
	gap: 12px;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
}

.checkbox {
	display: flex;
	gap: 8px;
	align-items: flex-start;
}

.hint {
	color: var(--color-text-maxcontrast);
}
</style>
