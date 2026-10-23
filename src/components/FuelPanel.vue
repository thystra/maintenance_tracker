<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// The Fuel tab: economy for a period (overall and per operating regime),
// spend, a small chart and the fill-ups with their receipts.
import type { CostSettings } from '../catalogTypes.ts'
import type { FuelPeriod, FuelSettings, FuelStats, FuelTicket, FuelTicketList } from '../fuelTypes.ts'

import { mdiCogOutline, mdiCounter, mdiDeleteOutline, mdiGasStationOutline, mdiPaperclip, mdiPencilOutline, mdiPlus, mdiTagMultipleOutline } from '@mdi/js'
import { computed, onMounted, ref, watch } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ConfirmDialog from './ConfirmDialog.vue'
import EvidencePanel from './EvidencePanel.vue'
import FuelChart from './FuelChart.vue'
import FuelRegimesDialog from './FuelRegimesDialog.vue'
import FuelSettingsDialog from './FuelSettingsDialog.vue'
import FuelTicketDialog from './FuelTicketDialog.vue'
import SelectField from './SelectField.vue'
import { errorMessage, formatDate, today } from '../format.ts'
import { distanceUnitLabel, ECONOMY_UNITS, economyUnitLabel, formatEconomy, formatNumber, fuelTypeLabel, periodLabel, PERIODS, volumeUnitLabel } from '../fuelFormat.ts'
import { n, t } from '../l10n.ts'
import { getCostSettings } from '../services/catalogApi.ts'
import { deleteFuelTicket, getFuelStats, getFuelTickets, linkFuelOdometer } from '../services/fuelApi.ts'
import { can } from '../store.ts'

const props = defineProps<{ assetUuid: string }>()
// `changed`: a fill-up was added, corrected or deleted, so meter readings
// (and with them the forecast) may have changed too.
const emit = defineEmits<{ (event: 'settings', settings: FuelSettings): void, (event: 'changed'): void }>()

const list = ref<FuelTicketList | null>(null)
const stats = ref<FuelStats | null>(null)
const costSettings = ref<CostSettings | null>(null)
const period = ref<string | null>('90d')
const unit = ref<string | null>(null)
const from = ref(today().slice(0, 4) + '-01-01')
const to = ref(today())
const error = ref('')
const status = ref('')
const busy = ref(false)
const ticketOpen = ref(false)
const editing = ref<FuelTicket | null>(null)
const regimesOpen = ref(false)
const settingsOpen = ref(false)
const removing = ref<FuelTicket | null>(null)
const filesFor = ref<string | null>(null)

const periodOptions = PERIODS.map((value) => ({ value, label: periodLabel(value) }))
const unitOptions = ECONOMY_UNITS.map((value) => ({ value, label: economyUnitLabel(value) }))
// Fill-ups whose odometer is not a meter reading (logged before the item had
// a distance meter). Owners and managers can record them in one go.
const unlinked = computed(() => (list.value?.items ?? []).filter((ticket) => ticket.readingUuid === null).length)
const regimeNames = computed(() => Object.fromEntries((list.value?.regimes ?? []).map((regime) => [regime.uuid, regime.name])))

async function loadStats(): Promise<void> {
	const chosen = (period.value ?? '90d') as FuelPeriod
	if (chosen === 'custom' && (from.value === '' || to.value === '' || to.value < from.value)) {
		return
	}
	try {
		stats.value = await getFuelStats(props.assetUuid, chosen, unit.value, chosen === 'custom' ? from.value : undefined, chosen === 'custom' ? to.value : undefined)
	} catch (reason) {
		error.value = errorMessage(reason)
	}
}

async function load(): Promise<void> {
	error.value = ''
	try {
		list.value = await getFuelTickets(props.assetUuid)
		emit('settings', list.value.settings)
		await loadStats()
	} catch (reason) {
		error.value = errorMessage(reason)
	}
}

watch([period, unit, from, to], () => {
	void loadStats()
})

function openAdd(): void {
	editing.value = null
	ticketOpen.value = true
}

function openEdit(ticket: FuelTicket): void {
	editing.value = ticket
	ticketOpen.value = true
}

async function saved(ticket: FuelTicket, created: boolean): Promise<void> {
	status.value = created ? t('maintenance_tracker', 'Fill-up added.') : t('maintenance_tracker', 'Fill-up saved.')
	await load()
	emit('changed')
	if (created) {
		// Offer the receipt right away.
		filesFor.value = ticket.uuid
	}
}

async function changed(message: string): Promise<void> {
	status.value = message
	await load()
	emit('changed')
}

/**
 * Record the odometer of earlier fill-ups as readings (creating the
 * "Odometer" meter when needed); values that would go backwards are skipped.
 */
async function linkOdometer(): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		const result = await linkFuelOdometer(props.assetUuid)
		const parts = [n('maintenance_tracker', '%n fill-up recorded on “{meter}”.', '%n fill-ups recorded on “{meter}”.', result.linked, { meter: result.meterName })]
		if (result.skipped.length > 0) {
			parts.push(n('maintenance_tracker', '%n skipped because the odometer would go backwards.', '%n skipped because the odometer would go backwards.', result.skipped.length))
		}
		status.value = parts.join(' ')
		await load()
		emit('changed')
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

function settingsSaved(settings: FuelSettings): void {
	status.value = t('maintenance_tracker', 'Fuel settings saved.')
	unit.value = null
	emit('settings', settings)
	void load()
}

async function remove(): Promise<void> {
	const ticket = removing.value
	if (ticket === null) {
		return
	}
	busy.value = true
	try {
		await deleteFuelTicket(ticket.uuid, ticket.revision)
		removing.value = null
		status.value = t('maintenance_tracker', 'Fill-up deleted.')
		await load()
		emit('changed')
	} catch (reason) {
		removing.value = null
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

/**
 * What a fill-up says about economy: the segment it closes, or why there is none.
 *
 * @param ticket The fill-up.
 */
function economyText(ticket: FuelTicket): string {
	const segment = ticket.segment
	if (!ticket.fullFill) {
		return t('maintenance_tracker', 'Partial fill')
	}
	if (!segment) {
		return t('maintenance_tracker', 'First full tank')
	}
	if (!segment.valid) {
		return segment.invalidReason === 'missed_fill' ? t('maintenance_tracker', 'Not measured: missed fill-up') : t('maintenance_tracker', 'Not measured: odometer did not increase')
	}
	const value = formatEconomy(segment.economy, list.value?.settings.economyUnit ?? 'mpg')
	return segment.mixed ? t('maintenance_tracker', '{economy} (mixed regimes)', { economy: value }) : value
}

/**
 * Secondary line of a fill-up.
 *
 * @param ticket The fill-up.
 */
function detailText(ticket: FuelTicket): string {
	return [
		formatDate(ticket.filledAt),
		ticket.regimeName,
		ticket.fuelType ? fuelTypeLabel(ticket.fuelType) : null,
		ticket.station,
		ticket.missedPrevious ? t('maintenance_tracker', 'after a missed fill-up') : null,
	].filter(Boolean).join(' · ')
}

onMounted(async () => {
	await load()
	try {
		costSettings.value = await getCostSettings()
	} catch {
		costSettings.value = null
	}
})
</script>

<template>
	<div>
		<p class="hidden-visually" role="status">
			{{ status }}
		</p>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<div class="panel-bar">
			<div class="filters">
				<SelectField v-model="period" :options="periodOptions" :label="t('maintenance_tracker', 'Period')" />
				<template v-if="period === 'custom'">
					<label class="native-field">
						<span>{{ t('maintenance_tracker', 'From') }}</span>
						<input v-model="from" type="date">
					</label>
					<label class="native-field">
						<span>{{ t('maintenance_tracker', 'To') }}</span>
						<input v-model="to" type="date">
					</label>
				</template>
				<SelectField
					v-model="unit"
					:options="unitOptions"
					:label="t('maintenance_tracker', 'Show economy in')"
					:placeholder="list ? economyUnitLabel(list.settings.economyUnit) : ''"
					clearable />
			</div>
			<div class="actions">
				<NcButton v-if="can.log" variant="primary" @click="openAdd">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('maintenance_tracker', 'Add fill-up') }}
				</NcButton>
				<NcActions v-if="can.manageInventory" :aria-label="t('maintenance_tracker', 'Fuel options')">
					<NcActionButton closeAfterClick @click="regimesOpen = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiTagMultipleOutline" />
						</template>
						{{ t('maintenance_tracker', 'Operating regimes') }}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="settingsOpen = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCogOutline" />
						</template>
						{{ t('maintenance_tracker', 'Fuel settings') }}
					</NcActionButton>
					<NcActionButton
						v-if="unlinked > 0"
						closeAfterClick
						:disabled="busy"
						@click="linkOdometer">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCounter" />
						</template>
						{{ t('maintenance_tracker', 'Record odometer readings') }}
					</NcActionButton>
				</NcActions>
			</div>
		</div>

		<NcNoteCard v-if="unlinked > 0 && can.manageInventory" type="info" data-test="fuel-unlinked">
			<p>
				{{ n('maintenance_tracker', '%n fill-up has an odometer value that is not a meter reading yet, so maintenance schedules do not see it.', '%n fill-ups have odometer values that are not meter readings yet, so maintenance schedules do not see them.', unlinked) }}
			</p>
			<NcButton :disabled="busy" @click="linkOdometer">
				{{ t('maintenance_tracker', 'Record odometer readings') }}
			</NcButton>
		</NcNoteCard>

		<NcEmptyContent
			v-if="list && list.items.length === 0"
			:name="t('maintenance_tracker', 'No fill-ups yet')"
			:description="t('maintenance_tracker', 'Record each fill-up with the odometer and the amount of fuel. Economy appears after two full tanks.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiGasStationOutline" :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else-if="list && stats">
			<div class="cards">
				<section class="card" aria-labelledby="fuel-overall-heading">
					<h3 id="fuel-overall-heading">
						{{ t('maintenance_tracker', 'Fuel economy') }}
					</h3>
					<p class="big">
						{{ formatEconomy(stats.overall.economy, stats.economyUnit) }}
					</p>
					<p class="muted">
						{{ stats.overall.segments === 0
							? t('maintenance_tracker', 'Not enough full tanks in this period.')
							: n('maintenance_tracker', '%n measured stretch, {distance} {unit}', '%n measured stretches, {distance} {unit}', stats.overall.segments, { distance: formatNumber(stats.overall.distance, 0), unit: distanceUnitLabel(stats.distanceUnit) }) }}
					</p>
					<p v-if="stats.overall.segments > 1" class="muted">
						{{ t('maintenance_tracker', 'Best {best} · worst {worst}', { best: formatEconomy(stats.overall.best, stats.economyUnit), worst: formatEconomy(stats.overall.worst, stats.economyUnit) }) }}
					</p>
				</section>
				<section class="card" aria-labelledby="fuel-spend-heading">
					<h3 id="fuel-spend-heading">
						{{ t('maintenance_tracker', 'Fuel spend') }}
					</h3>
					<p v-for="entry in stats.spend" :key="entry.currency" class="big">
						{{ entry.amount }} {{ entry.currency }}
					</p>
					<p v-if="stats.spend.length === 0" class="big">
						–
					</p>
					<p class="muted">
						{{ n('maintenance_tracker', '%n fill-up, {volume} {unit}', '%n fill-ups, {volume} {unit}', stats.fills, { volume: formatNumber(stats.volume, 1), unit: volumeUnitLabel(stats.volumeUnit) }) }}
					</p>
					<p v-for="entry in stats.costPerDistance" :key="`cpd-${entry.currency}`" class="muted">
						{{ t('maintenance_tracker', '{amount} {currency} per {unit}', { amount: entry.amount, currency: entry.currency, unit: distanceUnitLabel(stats.distanceUnit) }) }}
					</p>
				</section>
			</div>

			<section v-if="stats.regimes.length > 0" class="section" aria-labelledby="fuel-regimes-heading">
				<h3 id="fuel-regimes-heading">
					{{ t('maintenance_tracker', 'By operating regime') }}
				</h3>
				<table class="regime-table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('maintenance_tracker', 'Regime') }}
							</th>
							<th scope="col">
								{{ t('maintenance_tracker', 'Average') }}
							</th>
							<th scope="col">
								{{ t('maintenance_tracker', 'Best') }}
							</th>
							<th scope="col">
								{{ t('maintenance_tracker', 'Worst') }}
							</th>
							<th scope="col">
								{{ t('maintenance_tracker', 'Stretches') }}
							</th>
							<th scope="col">
								{{ t('maintenance_tracker', 'Distance') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="regime in stats.regimes" :key="regime.regimeUuid ?? 'none'">
							<th scope="row">
								{{ regime.name ?? t('maintenance_tracker', 'No regime') }}
							</th>
							<td>{{ formatEconomy(regime.economy, stats.economyUnit) }}</td>
							<td>{{ formatEconomy(regime.best, stats.economyUnit) }}</td>
							<td>{{ formatEconomy(regime.worst, stats.economyUnit) }}</td>
							<td>{{ regime.segments }}</td>
							<td>{{ formatNumber(regime.distance, 0) }} {{ distanceUnitLabel(stats.distanceUnit) }}</td>
						</tr>
					</tbody>
				</table>
				<p v-if="stats.mixedSegments > 0" class="muted">
					{{ n('maintenance_tracker', '%n stretch mixed regimes; it counts in the overall figure only.', '%n stretches mixed regimes; they count in the overall figure only.', stats.mixedSegments) }}
				</p>
			</section>

			<section v-if="stats.chart.length > 1" class="section" aria-labelledby="fuel-chart-heading">
				<h3 id="fuel-chart-heading">
					{{ t('maintenance_tracker', 'Economy over time') }}
				</h3>
				<FuelChart :points="stats.chart" :unit="stats.economyUnit" :regimeNames="regimeNames" />
			</section>

			<section class="section" aria-labelledby="fuel-list-heading">
				<h3 id="fuel-list-heading">
					{{ t('maintenance_tracker', 'Fill-ups') }}
				</h3>
				<p v-if="stats.invalidSegments > 0 || list.openFills > 0" class="muted">
					{{ [
						stats.invalidSegments > 0 ? n('maintenance_tracker', '%n stretch is not measured (missed fill-up or odometer problem).', '%n stretches are not measured (missed fill-up or odometer problem).', stats.invalidSegments) : '',
						list.openFills > 0 ? n('maintenance_tracker', '%n partial fill waits for the next full tank.', '%n partial fills wait for the next full tank.', list.openFills) : '',
					].filter(Boolean).join(' ') }}
				</p>
				<ul class="list">
					<template v-for="ticket in list.items" :key="ticket.uuid">
						<NcListItem
							:name="t('maintenance_tracker', '{volume} {volumeUnit} at {odometer} {distanceUnit}', { volume: ticket.volume, volumeUnit: volumeUnitLabel(ticket.volumeUnit), odometer: ticket.odometer, distanceUnit: distanceUnitLabel(ticket.odometerUnit) })"
							:forceDisplayActions="true">
							<template #subname>
								{{ detailText(ticket) }}
							</template>
							<template #details>
								<span class="economy">{{ economyText(ticket) }}</span>
								<strong v-if="ticket.totalCost">{{ ticket.totalCost }} {{ ticket.currency }}</strong>
							</template>
							<template #actions>
								<NcActionButton closeAfterClick @click="filesFor = filesFor === ticket.uuid ? null : ticket.uuid">
									<template #icon>
										<NcIconSvgWrapper :path="mdiPaperclip" />
									</template>
									{{ t('maintenance_tracker', 'Receipt and files') }}
								</NcActionButton>
								<NcActionButton v-if="can.manageInventory" closeAfterClick @click="openEdit(ticket)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiPencilOutline" />
									</template>
									{{ t('maintenance_tracker', 'Edit') }}
								</NcActionButton>
								<NcActionButton v-if="can.manageInventory" closeAfterClick @click="removing = ticket">
									<template #icon>
										<NcIconSvgWrapper :path="mdiDeleteOutline" />
									</template>
									{{ t('maintenance_tracker', 'Delete') }}
								</NcActionButton>
							</template>
						</NcListItem>
						<li v-if="filesFor === ticket.uuid" class="files">
							<EvidencePanel targetType="fuel" :targetUuid="ticket.uuid" compact />
						</li>
					</template>
				</ul>
			</section>
		</template>

		<FuelTicketDialog
			v-if="list"
			v-model:open="ticketOpen"
			:assetUuid="assetUuid"
			:ticket="editing"
			:settings="list.settings"
			:regimes="list.regimes"
			:multiCurrency="costSettings?.multiCurrency ?? false"
			:defaultCurrency="costSettings?.defaultCurrency ?? ''"
			@saved="saved" />
		<FuelRegimesDialog
			v-if="list"
			v-model:open="regimesOpen"
			:assetUuid="assetUuid"
			:regimes="list.regimes"
			@changed="changed" />
		<FuelSettingsDialog
			v-if="list"
			v-model:open="settingsOpen"
			:settings="list.settings"
			@saved="settingsSaved" />
		<ConfirmDialog
			:open="removing !== null"
			:name="t('maintenance_tracker', 'Delete fill-up?')"
			:message="t('maintenance_tracker', 'Delete this fill-up and its cost entry? The odometer reading stays in the readings history.')"
			:busy="busy"
			@update:open="removing = $event ? removing : null"
			@confirm="remove" />
	</div>
</template>

<style scoped>
.panel-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
}

.filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
}

.filters > * {
	width: 220px;
}

.actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.native-field {
	display: grid;
	gap: 4px;
}

.cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 12px;
	margin-bottom: 16px;
}

.card {
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.card h3,
.section h3 {
	margin: 0 0 8px;
	font-size: 16px;
	font-weight: bold;
}

.big {
	margin: 0;
	font-size: 24px;
	font-weight: bold;
}

.section {
	margin-bottom: 16px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.regime-table {
	width: 100%;
	border-collapse: collapse;
}

.regime-table th,
.regime-table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.economy {
	margin-inline-end: 12px;
}

.files {
	padding: 8px 16px 16px 52px;
}

.muted {
	margin: 4px 0;
	color: var(--color-text-maxcontrast);
}
</style>
