<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ExportFormat } from '../services/workspaceApi.ts'

import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { t } from '../l10n.ts'
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
	status.value = t('maintenance_tracker', 'Preparing export…')
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
		status.value = t('maintenance_tracker', 'Export ready: {fileName}', { fileName: result.fileName })
	} catch (reason) {
		status.value = ''
		error.value = reason instanceof Error ? reason.message : t('maintenance_tracker', 'The export could not be created.')
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<section class="export-panel" aria-labelledby="export-heading">
		<h3 id="export-heading">
			{{ t('maintenance_tracker', 'Export data') }}
		</h3>
		<p class="muted">
			{{ t('maintenance_tracker', 'Download what your role ({role}) can read in this workspace. Exports can contain private notes, costs, serial numbers and locations; store them carefully. Evidence lists only files your own Files can open.', { role: role }) }}
		</p>
		<form class="inline-form" @submit.prevent="run">
			<fieldset>
				<legend>{{ t('maintenance_tracker', 'Format') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="format"
					type="radio"
					value="zip"
					name="export-format">
					{{ t('maintenance_tracker', 'ZIP (JSON plus one CSV per table)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="format"
					type="radio"
					value="json"
					name="export-format">
					{{ t('maintenance_tracker', 'Single JSON document') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcCheckboxRadioSwitch
				v-model="includeFiles"
				type="switch"
				:disabled="format !== 'zip'"
				aria-describedby="export-files-hint">
				{{ t('maintenance_tracker', 'Include evidence files') }}
			</NcCheckboxRadioSwitch>
			<p id="export-files-hint" class="muted">
				{{ t('maintenance_tracker', 'Only files you can open are added, up to 100 MiB each and 512 MiB in total; skipped files are listed in manifest.json.') }}
			</p>
			<NcButton
				type="submit"
				variant="primary"
				:disabled="busy"
				:aria-busy="busy ? 'true' : 'false'">
				{{ busy ? t('maintenance_tracker', 'Exporting…') : t('maintenance_tracker', 'Download export') }}
			</NcButton>
		</form>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<p class="export-status" role="status" aria-live="polite">
			{{ status }}
		</p>
	</section>
</template>

<style scoped>
fieldset {
	margin: 0;
	padding: 0;
	border: 0;
}

legend {
	font-weight: bold;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
