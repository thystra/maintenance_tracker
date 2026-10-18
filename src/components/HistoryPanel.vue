<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Activity } from '../types.ts'

import { mdiDeleteOutline, mdiPaperclip } from '@mdi/js'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ConfirmDialog from './ConfirmDialog.vue'
import EvidencePanel from './EvidencePanel.vue'
import { errorMessage, formatDate } from '../format.ts'
import { t } from '../l10n.ts'
import { archiveActivity } from '../services/api.ts'
import { can } from '../store.ts'

defineProps<{ activities: Activity[] }>()
const emit = defineEmits<{ (event: 'changed'): void }>()

const filesFor = ref<string | null>(null)
const removing = ref<Activity | null>(null)
const busy = ref(false)
const error = ref('')

async function remove(): Promise<void> {
	const activity = removing.value
	if (activity === null) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		await archiveActivity(activity.uuid, activity.revision)
		removing.value = null
		emit('changed')
	} catch (reason) {
		removing.value = null
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<p v-if="activities.length === 0" class="muted">
			{{ t('maintenance_tracker', 'Nothing logged yet. Use “Log maintenance” when work is done.') }}
		</p>
		<ol class="timeline">
			<li v-for="activity in activities" :key="activity.uuid" class="timeline__entry">
				<div class="timeline__head">
					<div>
						<strong>{{ activity.items.map((item) => item.title).join(', ') }}</strong>
						<span class="muted"> · {{ formatDate(activity.performedAt) }}</span>
					</div>
					<div class="timeline__actions">
						<NcButton
							variant="tertiary"
							:aria-expanded="filesFor === activity.uuid ? 'true' : 'false'"
							:aria-label="t('maintenance_tracker', 'Files for this record')"
							@click="filesFor = filesFor === activity.uuid ? null : activity.uuid">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPaperclip" />
							</template>
						</NcButton>
						<NcButton
							v-if="can.manageInventory"
							variant="tertiary"
							:aria-label="t('maintenance_tracker', 'Delete this record')"
							@click="removing = activity">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDeleteOutline" />
							</template>
						</NcButton>
					</div>
				</div>
				<p v-if="activity.summary" class="timeline__text">
					{{ activity.summary }}
				</p>
				<ul v-if="activity.meters.length > 0 || (activity.parts?.length ?? 0) > 0" class="timeline__facts">
					<li v-for="meter in activity.meters" :key="meter.uuid">
						{{ t('maintenance_tracker', '{meter}: {value} {unit}', { meter: meter.meterName, value: meter.originalValue, unit: meter.originalUnit }) }}
					</li>
					<li v-for="part in activity.parts ?? []" :key="part.uuid">
						{{ t('maintenance_tracker', '{quantity} {unit} {part}', { quantity: part.quantity, unit: part.unit, part: part.description }) }}<template v-if="part.vendorName">
							· {{ part.vendorName }}
						</template>
					</li>
				</ul>
				<p v-if="activity.notes" class="timeline__text">
					{{ activity.notes }}
				</p>
				<EvidencePanel
					v-if="filesFor === activity.uuid"
					targetType="activity"
					:targetUuid="activity.uuid"
					compact />
			</li>
		</ol>
		<ConfirmDialog
			:open="removing !== null"
			:name="t('maintenance_tracker', 'Delete record?')"
			:message="t('maintenance_tracker', 'Delete this maintenance record? Due dates are recalculated from the remaining history.')"
			:busy="busy"
			@update:open="removing = $event ? removing : null"
			@confirm="remove" />
	</div>
</template>

<style scoped>
.timeline {
	display: grid;
	gap: 12px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.timeline__entry {
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-inline-start: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius-large);
}

.timeline__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.timeline__actions {
	display: flex;
}

.timeline__text {
	margin: 4px 0;
	white-space: pre-wrap;
}

.timeline__facts {
	margin: 4px 0;
	padding-inline-start: 18px;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
