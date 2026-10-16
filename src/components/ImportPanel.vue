<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ImportResult } from '../services/workspaceApi.ts'

import { ref } from 'vue'
import { n, t } from '../l10n.ts'
import { importWorkspace, ocsMessage } from '../services/workspaceApi.ts'

const emit = defineEmits<{ (event: 'imported', workspace: string): void }>()

const file = ref<File | null>(null)
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
		<h2 id="import-heading">
			{{ t('maintenance_tracker', 'Restore an export') }}
		</h2>
		<p class="hint">
			{{ t('maintenance_tracker', 'Creates a new workspace that you own from a complete export made by a workspace owner. Members and the audit trail are not restored; evidence files in a ZIP export are copied into your Files.') }}
		</p>
		<form class="form-grid" @submit.prevent="run">
			<label>
				<span>{{ t('maintenance_tracker', 'Export file (ZIP or JSON)') }}</span>
				<input
					type="file"
					accept=".zip,.json,application/zip,application/json"
					required
					@change="choose">
			</label>
			<label>
				<span>{{ t('maintenance_tracker', 'New workspace name (optional)') }}</span>
				<input v-model="name" maxlength="120">
			</label>
			<button type="submit" :disabled="busy || file === null" :aria-busy="busy ? 'true' : 'false'">
				{{ busy ? t('maintenance_tracker', 'Restoring…') : t('maintenance_tracker', 'Restore') }}
			</button>
		</form>
		<p v-if="error" class="notice notice--error" role="alert">
			{{ error }}
		</p>
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
.hint {
	color: var(--color-text-maxcontrast);
}
</style>
