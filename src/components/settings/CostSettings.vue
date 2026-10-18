<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { CostSettings } from '../../catalogTypes.ts'

import { onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage } from '../../format.ts'
import { t } from '../../l10n.ts'
import { getCostSettings, updateCostSettings } from '../../services/catalogApi.ts'
import { can } from '../../store.ts'

const settings = ref<CostSettings | null>(null)
const draft = reactive({ defaultCurrency: 'USD', taxEnabled: false, multiCurrency: false })
const busy = ref(false)
const error = ref('')
const status = ref('')

function apply(value: CostSettings): void {
	settings.value = value
	Object.assign(draft, { defaultCurrency: value.defaultCurrency, taxEnabled: value.taxEnabled, multiCurrency: value.multiCurrency })
}

async function save(): Promise<void> {
	busy.value = true
	error.value = ''
	status.value = ''
	try {
		apply(await updateCostSettings(settings.value?.revision ?? 0, { ...draft, defaultCurrency: draft.defaultCurrency.trim().toUpperCase() }))
		status.value = t('maintenance_tracker', 'Saved.')
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

onMounted(async () => {
	try {
		apply(await getCostSettings())
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
			{{ t('maintenance_tracker', 'These apply to everyone in this workspace. Amounts are stored exactly; there is no currency conversion.') }}
		</p>
		<form v-if="settings" class="inline-form" @submit.prevent="save">
			<div class="row narrow">
				<NcTextField
					v-model="draft.defaultCurrency"
					:label="t('maintenance_tracker', 'Currency (ISO code, e.g. USD, EUR)')"
					maxlength="3"
					:disabled="!can.manageSettings"
					required />
			</div>
			<NcCheckboxRadioSwitch v-model="draft.taxEnabled" type="switch" :disabled="!can.manageSettings">
				{{ t('maintenance_tracker', 'Record tax as separate lines') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="draft.multiCurrency" type="switch" :disabled="!can.manageSettings">
				{{ t('maintenance_tracker', 'Allow costs in other currencies (totals are kept per currency)') }}
			</NcCheckboxRadioSwitch>
			<p v-if="settings.currenciesInUse && settings.currenciesInUse.length > 1" class="muted">
				{{ t('maintenance_tracker', 'Currencies in use: {list}', { list: settings.currenciesInUse.join(', ') }) }}
			</p>
			<NcButton
				v-if="can.manageSettings"
				type="submit"
				variant="primary"
				:disabled="busy">
				{{ t('maintenance_tracker', 'Save') }}
			</NcButton>
			<p v-else class="muted">
				{{ t('maintenance_tracker', 'Only the workspace owner can change these.') }}
			</p>
		</form>
	</div>
</template>
