<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Manage the operating regimes of an item ("Empty", "Towing camper", ...).
// Each change is saved immediately; the default regime is preselected for new
// fill-ups.
import type { FuelRegime } from '../fuelTypes.ts'

import { mdiDeleteOutline, mdiStar, mdiStarOutline } from '@mdi/js'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage } from '../format.ts'
import { t } from '../l10n.ts'
import { createFuelRegime, deleteFuelRegime, updateFuelRegime } from '../services/fuelApi.ts'

const props = defineProps<{ open: boolean, assetUuid: string, regimes: FuelRegime[] }>()
const emit = defineEmits<{ (event: 'update:open', value: boolean): void, (event: 'changed', message: string): void }>()

const name = ref('')
const names = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)

watch(() => [props.open, props.regimes] as const, () => {
	names.value = Object.fromEntries(props.regimes.map((regime) => [regime.uuid, regime.name]))
	error.value = ''
}, { immediate: true })

/**
 * Run one regime change and report it.
 *
 * @param action The API call.
 * @param message Announcement after success.
 */
async function run(action: () => Promise<unknown>, message: string): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		await action()
		emit('changed', message)
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

async function add(): Promise<void> {
	const value = name.value.trim()
	if (value === '') {
		error.value = t('maintenance_tracker', 'Enter a name.')
		return
	}
	await run(() => createFuelRegime(props.assetUuid, { uuid: crypto.randomUUID(), name: value }), t('maintenance_tracker', 'Regime {name} added.', { name: value }))
	if (error.value === '') {
		name.value = ''
	}
}

async function rename(regime: FuelRegime): Promise<void> {
	const value = (names.value[regime.uuid] ?? '').trim()
	if (value === '' || value === regime.name) {
		names.value[regime.uuid] = regime.name
		return
	}
	await run(() => updateFuelRegime(regime.uuid, regime.revision, { name: value }), t('maintenance_tracker', 'Regime renamed to {name}.', { name: value }))
}

const buttons = computed(() => [
	{ label: t('maintenance_tracker', 'Close'), variant: 'primary' as const, callback: () => {
		emit('update:open', false)
		return false
	} },
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('maintenance_tracker', 'Operating regimes')"
		:buttons="buttons"
		size="normal"
		@update:open="emit('update:open', $event)">
		<div class="form-stack">
			<p class="muted">
				{{ t('maintenance_tracker', 'Regimes describe how the item was used between fill-ups, for example empty, loaded or towing. Fuel economy is reported per regime; a stretch that mixes regimes only counts in the overall figures.') }}
			</p>
			<NcNoteCard v-if="error" type="error">
				<p role="alert">
					{{ error }}
				</p>
			</NcNoteCard>
			<ul class="regimes" :aria-label="t('maintenance_tracker', 'Regimes')">
				<li v-for="regime in regimes" :key="regime.uuid" class="regime">
					<NcTextField
						v-model="names[regime.uuid]"
						:label="t('maintenance_tracker', 'Name of regime {name}', { name: regime.name })"
						:labelOutside="false"
						:disabled="busy"
						@blur="rename(regime)"
						@keydown.enter.prevent="rename(regime)" />
					<NcButton
						variant="tertiary"
						:pressed="regime.isDefault"
						:disabled="busy || regime.isDefault"
						:aria-label="regime.isDefault ? t('maintenance_tracker', '{name} is the default regime', { name: regime.name }) : t('maintenance_tracker', 'Make {name} the default regime', { name: regime.name })"
						@click="run(() => updateFuelRegime(regime.uuid, regime.revision, { isDefault: true }), t('maintenance_tracker', '{name} is now the default regime.', { name: regime.name }))">
						<template #icon>
							<NcIconSvgWrapper :path="regime.isDefault ? mdiStar : mdiStarOutline" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('maintenance_tracker', 'Delete regime {name}', { name: regime.name })"
						@click="run(() => deleteFuelRegime(regime.uuid, regime.revision), t('maintenance_tracker', 'Regime {name} deleted. Fill-ups keep it in their history.', { name: regime.name }))">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDeleteOutline" />
						</template>
					</NcButton>
				</li>
			</ul>
			<form class="add" @submit.prevent="add">
				<NcTextField v-model="name" :label="t('maintenance_tracker', 'New regime')" maxlength="120" />
				<NcButton type="submit" :disabled="busy">
					{{ t('maintenance_tracker', 'Add regime') }}
				</NcButton>
			</form>
		</div>
	</NcDialog>
</template>

<style scoped>
.form-stack {
	display: grid;
	gap: 12px;
	padding-bottom: 8px;
}

.regimes {
	display: grid;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.regime,
.add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.regime > :first-child,
.add > :first-child {
	flex: 1 1 auto;
}

.regime > :not(:first-child),
.add > :not(:first-child) {
	flex-shrink: 0;
}

.muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
