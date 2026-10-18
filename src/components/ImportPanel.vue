<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ImportResult } from '../services/workspaceApi.ts'

import { mdiFileUploadOutline } from '@mdi/js'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { n, t } from '../l10n.ts'
import { importWorkspace, ocsMessage } from '../services/workspaceApi.ts'

const emit = defineEmits<{ (event: 'imported', workspace: string): void }>()

const file = ref<File | null>(null)
const input = ref<HTMLInputElement | null>(null)
const name = ref('')
const busy = ref(false)
const error = ref('')
const result = ref<ImportResult | null>(null)

function choose(event: Event): void {
	file.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function run(): Promise<void> {
	if (file.value === null) {
		return
	}
	busy.value = true
	error.value = ''
	result.value = null
	try {
		result.value = await importWorkspace(file.value, name.value)
		emit('imported', result.value.workspace.uuid)
	} catch (reason) {
		error.value = ocsMessage(reason)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<section class="import-panel" aria-labelledby="import-heading">
		<h3 id="import-heading">
			{{ t('maintenance_tracker', 'Restore an export') }}
		</h3>
		<p class="muted">
			{{ t('maintenance_tracker', 'Creates a new workspace that you own from a complete export made by a workspace owner. Members and the audit trail are not restored; evidence files in a ZIP export are copied into your Files.') }}
		</p>
		<form class="inline-form" @submit.prevent="run">
			<div class="row">
				<NcButton @click="input?.click()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFileUploadOutline" />
					</template>
					{{ t('maintenance_tracker', 'Choose export file') }}
				</NcButton>
				<span class="muted">{{ file ? file.name : t('maintenance_tracker', 'ZIP or JSON') }}</span>
			</div>
			<input
				ref="input"
				class="hidden-visually"
				type="file"
				tabindex="-1"
				accept=".zip,.json,application/zip,application/json"
				:aria-label="t('maintenance_tracker', 'Export file (ZIP or JSON)')"
				@change="choose">
			<NcTextField v-model="name" :label="t('maintenance_tracker', 'New workspace name (optional)')" maxlength="120" />
			<NcButton
				type="submit"
				variant="primary"
				:disabled="busy || file === null"
				:aria-busy="busy ? 'true' : 'false'">
				{{ busy ? t('maintenance_tracker', 'Restoring…') : t('maintenance_tracker', 'Restore') }}
			</NcButton>
		</form>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<p class="import-status" role="status" aria-live="polite">
			<template v-if="result">
				{{ t('maintenance_tracker', 'Restored “{name}”.', { name: result.workspace.name }) }}
				{{ n('maintenance_tracker', '%n evidence file restored.', '%n evidence files restored.', result.evidence.restored) }}
				<template v-if="result.evidence.skipped.length > 0">
					{{ n('maintenance_tracker', '%n evidence item was not restored.', '%n evidence items were not restored.', result.evidence.skipped.length) }}
				</template>
			</template>
		</p>
	</section>
</template>

<style scoped>
.muted {
	color: var(--color-text-maxcontrast);
}
</style>
