<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Per-item fuel settings: when to show the Fuel tab and the default units.
import type { DistanceUnit, EconomyUnit, FuelSettings, FuelTabMode, FuelType, VolumeUnit } from '../fuelTypes.ts'

import { computed, reactive, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import SelectField from './SelectField.vue'
import { errorMessage } from '../format.ts'
import { distanceUnitLabel, ECONOMY_UNITS, economyUnitLabel, FUEL_TYPES, fuelTypeLabel, volumeUnitLabel } from '../fuelFormat.ts'
import { t } from '../l10n.ts'
import { updateFuelSettings } from '../services/fuelApi.ts'

const props = defineProps<{ open: boolean, settings: FuelSettings }>()
const emit = defineEmits<{ (event: 'update:open', value: boolean): void, (event: 'saved', settings: FuelSettings): void }>()

const draft = reactive({ fuelTab: 'auto' as string | null, distanceUnit: 'mi' as string | null, volumeUnit: 'gal' as string | null, economyUnit: 'mpg' as string | null, fuelType: null as string | null })
const error = ref('')
const busy = ref(false)

const tabOptions = [
	{ value: 'auto', label: t('maintenance_tracker', 'Automatic (vehicles and items with an odometer)') },
	{ value: 'on', label: t('maintenance_tracker', 'Always show') },
	{ value: 'off', label: t('maintenance_tracker', 'Hide') },
]
const distanceOptions = (['mi', 'km'] as DistanceUnit[]).map((value) => ({ value, label: distanceUnitLabel(value) }))
const volumeOptions = (['gal', 'l'] as VolumeUnit[]).map((value) => ({ value, label: volumeUnitLabel(value) }))
const economyOptions = ECONOMY_UNITS.map((value) => ({ value, label: economyUnitLabel(value) }))
const fuelTypeOptions = FUEL_TYPES.map((value) => ({ value, label: fuelTypeLabel(value) }))

watch(() => props.open, (open) => {
	if (open) {
		Object.assign(draft, { fuelTab: props.settings.fuelTab, distanceUnit: props.settings.distanceUnit, volumeUnit: props.settings.volumeUnit, economyUnit: props.settings.economyUnit, fuelType: props.settings.fuelType })
		error.value = ''
	}
}, { immediate: true })

async function save(): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		const saved = await updateFuelSettings(props.settings.assetUuid, props.settings.revision, {
			fuelTab: (draft.fuelTab ?? 'auto') as FuelTabMode,
			distanceUnit: (draft.distanceUnit ?? 'mi') as DistanceUnit,
			volumeUnit: (draft.volumeUnit ?? 'gal') as VolumeUnit,
			economyUnit: (draft.economyUnit ?? 'mpg') as EconomyUnit,
			fuelType: draft.fuelType as FuelType | null,
		})
		emit('saved', saved)
		emit('update:open', false)
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

const buttons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => {
		emit('update:open', false)
		return false
	} },
	{ label: t('maintenance_tracker', 'Save'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value, callback: () => false },
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('maintenance_tracker', 'Fuel settings')"
		:buttons="buttons"
		isForm
		size="small"
		@submit="save"
		@update:open="emit('update:open', $event)">
		<div class="form-stack">
			<NcNoteCard v-if="error" type="error">
				<p role="alert">
					{{ error }}
				</p>
			</NcNoteCard>
			<SelectField v-model="draft.fuelTab" :options="tabOptions" :label="t('maintenance_tracker', 'Fuel tab')" />
			<SelectField v-model="draft.economyUnit" :options="economyOptions" :label="t('maintenance_tracker', 'Fuel economy unit')" />
			<SelectField v-model="draft.distanceUnit" :options="distanceOptions" :label="t('maintenance_tracker', 'Distance unit')" />
			<SelectField v-model="draft.volumeUnit" :options="volumeOptions" :label="t('maintenance_tracker', 'Volume unit')" />
			<SelectField
				v-model="draft.fuelType"
				:options="fuelTypeOptions"
				:label="t('maintenance_tracker', 'Usual fuel type')"
				:placeholder="t('maintenance_tracker', 'Not set')"
				clearable />
		</div>
	</NcDialog>
</template>

<style scoped>
.form-stack {
	display: grid;
	gap: 12px;
	padding-bottom: 8px;
}
</style>
