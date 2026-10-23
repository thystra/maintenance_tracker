<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Add or correct a fuel ticket (a fill-up). The price becomes a cost entry
// and the odometer a meter reading; both are handled by the server. With
// `vehicles` (the quick "Log fuel" dialog) it also shows an item picker.
import type { DistanceUnit, FuelRegime, FuelSettings, FuelTicket, FuelTicketInput, FuelType, FuelVehicle, VolumeUnit } from '../fuelTypes.ts'

import { computed, reactive, ref, watch } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SelectField from './SelectField.vue'
import { errorMessage, formatDate, localNow, toIso } from '../format.ts'
import { distanceUnitLabel, FUEL_TYPES, fuelTypeLabel, volumeUnitLabel } from '../fuelFormat.ts'
import { t } from '../l10n.ts'
import { createFuelTicket, updateFuelTicket } from '../services/fuelApi.ts'

const props = defineProps<{
	open: boolean
	assetUuid: string
	ticket: FuelTicket | null
	settings: FuelSettings
	regimes: FuelRegime[]
	multiCurrency: boolean
	defaultCurrency: string
	vehicles?: FuelVehicle[]
}>()
const emit = defineEmits<{ (event: 'update:open', value: boolean): void, (event: 'update:assetUuid', value: string): void, (event: 'saved', ticket: FuelTicket, created: boolean): void }>()

const DECIMAL = /^\d+(\.\d+)?$/
const busy = ref(false)
const error = ref('')
const draft = reactive({
	filledAt: '',
	odometer: '',
	odometerUnit: 'mi' as string | null,
	volume: '',
	volumeUnit: 'gal' as string | null,
	fullFill: true,
	missedPrevious: false,
	fuelType: null as string | null,
	regimeUuid: null as string | null,
	station: '',
	unitPrice: '',
	totalCost: '',
	currency: '',
	notes: '',
})

const distanceOptions = (['mi', 'km'] as DistanceUnit[]).map((value) => ({ value, label: distanceUnitLabel(value) }))
const volumeOptions = (['gal', 'l'] as VolumeUnit[]).map((value) => ({ value, label: volumeUnitLabel(value) }))
const fuelTypeOptions = FUEL_TYPES.map((value) => ({ value, label: fuelTypeLabel(value) }))
const regimeOptions = computed(() => props.regimes.map((regime) => ({ value: regime.uuid, label: regime.isDefault ? t('maintenance_tracker', '{name} (default)', { name: regime.name }) : regime.name })))
const vehicleOptions = computed(() => (props.vehicles ?? []).map((vehicle) => ({ value: vehicle.assetUuid, label: vehicle.name })))
const lastFillUp = computed(() => {
	const vehicle = props.vehicles?.find((candidate) => candidate.assetUuid === props.assetUuid)
	if (!vehicle || vehicle.lastFilledAt === null || vehicle.lastOdometer === null) {
		return ''
	}
	return t('maintenance_tracker', 'Last fill-up {date} at {odometer} {unit}.', { date: formatDate(vehicle.lastFilledAt), odometer: vehicle.lastOdometer, unit: distanceUnitLabel(vehicle.lastOdometerUnit ?? props.settings.distanceUnit) })
})
const selectedVehicle = computed<string | null>({
	get: () => props.assetUuid,
	set: (value) => {
		if (value !== null) {
			emit('update:assetUuid', value)
		}
	},
})
// Where the odometer value goes, so the reading on the meter is no surprise.
const meterHint = computed(() => {
	if (props.ticket) {
		return ''
	}
	if (props.settings.odometerMeter) {
		return t('maintenance_tracker', 'Also records an odometer reading on “{meter}”.', { meter: props.settings.odometerMeter.name })
	}
	if (props.settings.createsOdometer) {
		return t('maintenance_tracker', 'This item has no odometer meter yet: saving creates an “Odometer” meter ({unit}) and records this reading on it.', { unit: distanceUnitLabel(props.settings.distanceUnit) })
	}
	return t('maintenance_tracker', 'This item has no odometer meter, so the value is kept on the fill-up only. An owner or manager can add the meter.')
})
const computedTotal = computed(() => {
	if (!DECIMAL.test(draft.unitPrice.trim()) || !DECIMAL.test(draft.volume.trim())) {
		return ''
	}
	return (Number(draft.unitPrice) * Number(draft.volume)).toFixed(2)
})

watch(() => props.open, (open) => {
	if (!open) {
		return
	}
	reset()
}, { immediate: true })

// Another item picked in the quick dialog: take over its units and defaults.
watch(() => props.assetUuid, () => {
	if (props.open && props.ticket === null) {
		Object.assign(draft, {
			odometerUnit: props.settings.distanceUnit,
			volumeUnit: props.settings.volumeUnit,
			fuelType: props.settings.fuelType,
			regimeUuid: props.regimes.find((regime) => regime.isDefault)?.uuid ?? null,
		})
	}
})

/**
 * Fill the form from the ticket being edited, or with the item's defaults.
 */
function reset(): void {
	error.value = ''
	const ticket = props.ticket
	const local = ticket ? new Date(new Date(ticket.filledAt).getTime() - new Date(ticket.filledAt).getTimezoneOffset() * 60000).toISOString().slice(0, 16) : localNow()
	Object.assign(draft, {
		filledAt: local,
		odometer: ticket?.odometer ?? '',
		odometerUnit: ticket?.odometerUnit ?? props.settings.distanceUnit,
		volume: ticket?.volume ?? '',
		volumeUnit: ticket?.volumeUnit ?? props.settings.volumeUnit,
		fullFill: ticket?.fullFill ?? true,
		missedPrevious: ticket?.missedPrevious ?? false,
		fuelType: ticket ? ticket.fuelType : props.settings.fuelType,
		regimeUuid: ticket ? ticket.regimeUuid : (props.regimes.find((regime) => regime.isDefault)?.uuid ?? null),
		station: ticket?.station ?? '',
		unitPrice: '',
		totalCost: ticket?.totalCost ?? '',
		currency: ticket?.currency ?? props.defaultCurrency,
		notes: ticket?.notes ?? '',
	})
}

/**
 * Validate the form and save the ticket; the dialog stays open with the
 * error when something is wrong.
 */
async function save(): Promise<void> {
	const total = draft.totalCost.trim() || computedTotal.value
	if (draft.filledAt === '' || !DECIMAL.test(draft.odometer.trim()) || !DECIMAL.test(draft.volume.trim())) {
		error.value = t('maintenance_tracker', 'Enter the date, the odometer and the amount of fuel as numbers, for example 45210 and 12.4.')
		return
	}
	if (total !== '' && !DECIMAL.test(total)) {
		error.value = t('maintenance_tracker', 'Enter the total price as a number such as 48.20.')
		return
	}
	const input: FuelTicketInput = {
		filledAt: toIso(draft.filledAt),
		odometer: draft.odometer.trim(),
		odometerUnit: (draft.odometerUnit ?? props.settings.distanceUnit) as DistanceUnit,
		volume: draft.volume.trim(),
		volumeUnit: (draft.volumeUnit ?? props.settings.volumeUnit) as VolumeUnit,
		fullFill: draft.fullFill,
		missedPrevious: draft.missedPrevious,
		fuelType: draft.fuelType as FuelType | null,
		regimeUuid: draft.regimeUuid,
		station: draft.station.trim() || null,
		totalCost: total === '' ? null : total,
		currency: props.multiCurrency && draft.currency.trim() !== '' ? draft.currency.trim().toUpperCase() : null,
		notes: draft.notes.trim() || null,
	}
	busy.value = true
	error.value = ''
	try {
		const ticket = props.ticket
		const saved = ticket
			? await updateFuelTicket(ticket.uuid, ticket.revision, input)
			: await createFuelTicket(props.assetUuid, { ...input, uuid: crypto.randomUUID() })
		emit('saved', saved, ticket === null)
		emit('update:open', false)
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

// Buttons return false: the dialog closes itself only after a successful save.
const buttons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => {
		emit('update:open', false)
		return false
	} },
	{ label: props.ticket ? t('maintenance_tracker', 'Save') : t('maintenance_tracker', 'Add fill-up'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value, callback: () => false },
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="ticket ? t('maintenance_tracker', 'Edit fill-up') : t('maintenance_tracker', 'Add fill-up')"
		:buttons="buttons"
		isForm
		size="normal"
		@submit="save"
		@update:open="emit('update:open', $event)">
		<div class="form-stack">
			<NcNoteCard v-if="error" type="error">
				<p role="alert">
					{{ error }}
				</p>
			</NcNoteCard>
			<SelectField
				v-if="vehicles && vehicles.length > 1 && !ticket"
				v-model="selectedVehicle"
				:options="vehicleOptions"
				:label="t('maintenance_tracker', 'Vehicle')"
				required />
			<p v-else-if="vehicles && vehicles.length === 1 && !ticket" class="muted">
				{{ t('maintenance_tracker', 'Fill-up for {name}', { name: vehicles[0].name }) }}
			</p>
			<p v-if="lastFillUp" class="muted hint">
				{{ lastFillUp }}
			</p>
			<label class="native-field">
				<span>{{ t('maintenance_tracker', 'Date and time') }}</span>
				<input v-model="draft.filledAt" type="datetime-local" required>
			</label>
			<div class="form-row">
				<NcTextField
					v-model="draft.odometer"
					:label="t('maintenance_tracker', 'Odometer')"
					inputmode="decimal"
					required />
				<SelectField v-model="draft.odometerUnit" :options="distanceOptions" :label="t('maintenance_tracker', 'Distance unit')" />
			</div>
			<p v-if="meterHint" class="muted hint" data-test="fuel-meter-hint">
				{{ meterHint }}
			</p>
			<div class="form-row">
				<NcTextField
					v-model="draft.volume"
					:label="t('maintenance_tracker', 'Fuel added')"
					inputmode="decimal"
					required />
				<SelectField v-model="draft.volumeUnit" :options="volumeOptions" :label="t('maintenance_tracker', 'Volume unit')" />
				<SelectField
					v-model="draft.fuelType"
					:options="fuelTypeOptions"
					:label="t('maintenance_tracker', 'Fuel type')"
					:placeholder="t('maintenance_tracker', 'Not set')"
					clearable />
			</div>
			<div class="switches">
				<NcCheckboxRadioSwitch v-model="draft.fullFill" type="switch">
					{{ t('maintenance_tracker', 'Filled the tank completely') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="draft.missedPrevious" type="switch">
					{{ t('maintenance_tracker', 'A previous fill-up was not recorded') }}
				</NcCheckboxRadioSwitch>
			</div>
			<p class="muted hint">
				{{ t('maintenance_tracker', 'Fuel economy is measured from one full tank to the next. A missed fill-up leaves that stretch out of the figures.') }}
			</p>
			<SelectField
				v-if="regimes.length > 0"
				v-model="draft.regimeUuid"
				:options="regimeOptions"
				:label="t('maintenance_tracker', 'Driving since the last fill-up')"
				:placeholder="t('maintenance_tracker', 'No regime')"
				clearable />
			<div class="form-row">
				<NcTextField
					v-if="!ticket"
					v-model="draft.unitPrice"
					:label="t('maintenance_tracker', 'Price per unit (optional)')"
					inputmode="decimal" />
				<NcTextField
					v-model="draft.totalCost"
					:label="t('maintenance_tracker', 'Total price (optional)')"
					:placeholder="computedTotal"
					inputmode="decimal" />
				<NcTextField
					v-if="multiCurrency"
					v-model="draft.currency"
					:label="t('maintenance_tracker', 'Currency')"
					maxlength="3" />
			</div>
			<NcTextField v-model="draft.station" :label="t('maintenance_tracker', 'Station (optional)')" maxlength="255" />
			<NcTextField v-model="draft.notes" :label="t('maintenance_tracker', 'Notes (optional)')" />
		</div>
	</NcDialog>
</template>

<style scoped>
.form-stack {
	display: grid;
	gap: 12px;
	padding-bottom: 8px;
}

.form-row {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 12px;
	align-items: end;
}

.native-field {
	display: grid;
	gap: 4px;
	max-width: 260px;
}

.switches {
	display: grid;
	gap: 4px;
}

.hint {
	margin: -4px 0 0;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
