<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { CostSettings } from '../catalogTypes.ts'
// Quick "Log fuel": the add fill-up dialog with a picker of the items that
// show the Fuel tab. The last vehicle used is preselected; with a single
// vehicle (or a fixed `assetUuid`) there is no picker.
import type { FuelTicket, FuelVehicle } from '../fuelTypes.ts'

import { computed, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import FuelTicketDialog from './FuelTicketDialog.vue'
import { errorMessage } from '../format.ts'
import { t } from '../l10n.ts'
import { getCostSettings } from '../services/catalogApi.ts'
import { getFuelVehicles } from '../services/fuelApi.ts'

const props = defineProps<{
	open: boolean
	/** Log for this item only. */
	assetUuid?: string | null
}>()
const emit = defineEmits<{ (event: 'update:open', value: boolean): void, (event: 'saved', ticket: FuelTicket, assetUuid: string): void }>()

const LAST_KEY = 'maintenance_tracker.lastFuelAsset'
const vehicles = ref<FuelVehicle[]>([])
const selected = ref<string | null>(null)
const costSettings = ref<CostSettings | null>(null)
const ready = ref(false)
const failure = ref('')

const vehicle = computed(() => vehicles.value.find((candidate) => candidate.assetUuid === selected.value) ?? null)
const dialogOpen = computed({
	get: () => props.open && ready.value && vehicle.value !== null,
	set: (value: boolean) => emit('update:open', value),
})

/**
 * Last vehicle used on this device, else the one filled up most recently.
 *
 * @param list vehicles to pick from
 */
function preselect(list: FuelVehicle[]): string | null {
	let remembered: string | null = null
	try {
		remembered = window.localStorage.getItem(LAST_KEY)
	} catch {
		remembered = null
	}
	if (list.some((candidate) => candidate.assetUuid === remembered)) {
		return remembered
	}
	const recent = [...list].filter((candidate) => candidate.lastFilledAt !== null).sort((a, b) => (b.lastFilledAt ?? '').localeCompare(a.lastFilledAt ?? ''))
	return recent[0]?.assetUuid ?? list[0]?.assetUuid ?? null
}

/**
 * Load the vehicles each time the dialog opens, so the meter hint is current.
 */
async function load(): Promise<void> {
	ready.value = false
	failure.value = ''
	try {
		const [list, costs] = await Promise.all([getFuelVehicles(), getCostSettings().catch(() => null)])
		vehicles.value = props.assetUuid ? list.items.filter((candidate) => candidate.assetUuid === props.assetUuid) : list.items
		costSettings.value = costs
		selected.value = props.assetUuid && vehicles.value.length > 0 ? props.assetUuid : preselect(vehicles.value)
		if (vehicles.value.length === 0) {
			failure.value = t('maintenance_tracker', 'No item has the Fuel tab. Turn it on in an item’s Fuel settings or set the item class to Vehicle.')
		}
	} catch (reason) {
		failure.value = errorMessage(reason)
	} finally {
		ready.value = true
	}
}

watch(() => props.open, (open) => {
	if (open) {
		load()
	}
}, { immediate: true })

/**
 * Remember the vehicle and pass the new ticket on.
 *
 * @param ticket the saved fill-up
 */
function saved(ticket: FuelTicket): void {
	try {
		window.localStorage.setItem(LAST_KEY, ticket.assetUuid)
	} catch {
		// Storage unavailable: the next dialog preselects by latest fill-up.
	}
	emit('saved', ticket, ticket.assetUuid)
}
</script>

<template>
	<FuelTicketDialog
		v-if="vehicle"
		v-model:open="dialogOpen"
		:assetUuid="vehicle.assetUuid"
		:ticket="null"
		:vehicles="vehicles"
		:settings="vehicle.settings"
		:regimes="vehicle.regimes"
		:multiCurrency="costSettings?.multiCurrency ?? false"
		:defaultCurrency="costSettings?.defaultCurrency ?? ''"
		@update:assetUuid="selected = $event"
		@saved="saved" />
	<NcDialog
		v-else-if="open && ready && failure"
		:open="true"
		:name="t('maintenance_tracker', 'Log fuel')"
		:buttons="[{ label: t('maintenance_tracker', 'Close'), callback: () => emit('update:open', false) }]"
		size="small"
		@update:open="emit('update:open', $event)">
		<NcNoteCard type="warning">
			<p role="alert">
				{{ failure }}
			</p>
		</NcNoteCard>
	</NcDialog>
</template>
