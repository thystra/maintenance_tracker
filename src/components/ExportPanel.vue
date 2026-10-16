<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ExportFormat } from '../services/workspaceApi.ts'

import { ref } from 'vue'
import { downloadExport } from '../services/workspaceApi.ts'

const props = defineProps<{ workspace: string, role: string }>()

const format = ref<ExportFormat>('zip')
const includeFiles = ref(false)
const busy = ref(false)
const error = ref('')
const status = ref('')

async function run(): Promise<void> {
	busy.value = true
	error.value = ''
	status.value = 'Preparing export…'
	try {
		const result = await downloadExport(props.workspace, format.value, format.value === 'zip' && includeFiles.value)
		const url = URL.createObjectURL(result.blob)
		const link = document.createElement('a')
		link.href = url
		link.download = result.fileName
		document.body.appendChild(link)
		link.click()
		link.remove()
		window.setTimeout(() => URL.revokeObjectURL(url), 1000)
		status.value = `Export ready: ${result.fileName}`
	} catch (reason) {
		status.value = ''
		error.value = reason instanceof Error ? reason.message : 'The export could not be created.'
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<section class="export-panel" aria-labelledby="export-heading">
		<h2 id="export-heading">
			Export data
		</h2>
		<p class="hint">
			Download what your role ({{ role }}) can read in this workspace. Exports can contain private notes, costs, serial numbers and locations; store them carefully.
			Evidence lists only files your own Files can open.
		</p>
		<form class="form-grid" @submit.prevent="run">
			<fieldset>
				<legend>Format</legend>
				<label class="choice"><input v-model="format" type="radio" value="zip"><span>ZIP (JSON plus one CSV per table)</span></label>
				<label class="choice"><input v-model="format" type="radio" value="json"><span>Single JSON document</span></label>
			</fieldset>
			<label class="choice">
				<input
					v-model="includeFiles"
					type="checkbox"
					:disabled="format !== 'zip'"
					aria-describedby="export-files-hint">
				<span>Include evidence files</span>
			</label>
			<p id="export-files-hint" class="hint">
				Only files you can open are added, up to 100 MiB each and 512 MiB in total; skipped files are listed in manifest.json.
			</p>
			<button type="submit" :disabled="busy" :aria-busy="busy ? 'true' : 'false'">
				{{ busy ? 'Exporting…' : 'Download export' }}
			</button>
		</form>
		<p v-if="error" class="notice notice--error" role="alert">
			{{ error }}
		</p>
		<p class="export-status" role="status" aria-live="polite">
			{{ status }}
		</p>
	</section>
</template>

<style scoped>
.choice {
	display: flex;
	gap: 8px;
	align-items: center;
}

fieldset {
	border: 0;
	margin: 0;
	padding: 0;
}

legend {
	font-weight: bold;
}

.hint {
	color: var(--color-text-maxcontrast);
}
</style>
