<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { CostCategory, CostEntry, CostLineType, CostSettings, CostTotal } from '../catalogTypes.ts'

import { onMounted, reactive, ref } from 'vue'
import { archiveCost, createCost, getAssetCosts, updateCostSettings } from '../services/catalogApi.ts'

const props = defineProps<{ assetUuid: string }>()

const categories: CostCategory[] = ['parts', 'labor', 'fluids', 'service', 'fees', 'other']
const items = ref<CostEntry[]>([])
const totals = ref<CostTotal[]>([])
const settings = ref<CostSettings | null>(null)
const error = ref('')
const saving = ref(false)
const showSettings = ref(false)
const draft = reactive({ incurredOn: new Date().toISOString().slice(0, 10), description: '', amount: '', currency: '', lineType: 'charge' as CostLineType, category: 'other' as CostCategory, notes: '' })
const settingsDraft = reactive({ defaultCurrency: 'USD', taxEnabled: false, multiCurrency: false })

function readableError(reason: unknown): string {
	return reason instanceof Error && reason.message !== '' ? reason.message : 'The request could not be completed.'
}

async function load(): Promise<void> {
	try {
		const list = await getAssetCosts(props.assetUuid)
		items.value = list.items
		totals.value = list.totals
		settings.value = list.settings
		Object.assign(settingsDraft, { defaultCurrency: list.settings.defaultCurrency, taxEnabled: list.settings.taxEnabled, multiCurrency: list.settings.multiCurrency })
	} catch (reason) {
		error.value = readableError(reason)
	}
}

async function mutate(operation: () => Promise<unknown>): Promise<void> {
	saving.value = true
	error.value = ''
	try {
		await operation()
		await load()
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function submitCost(): Promise<void> {
	await mutate(async () => {
		await createCost(props.assetUuid, {
			uuid: crypto.randomUUID(),
			incurredOn: draft.incurredOn,
			description: draft.description,
			amount: draft.amount.trim(),
			currency: settings.value?.multiCurrency && draft.currency.trim() !== '' ? draft.currency.trim().toUpperCase() : undefined,
			lineType: draft.lineType,
			category: draft.lineType === 'tax' ? 'tax' : draft.category,
			notes: draft.notes || null,
		})
		Object.assign(draft, { description: '', amount: '', notes: '', lineType: 'charge' })
	})
}

async function submitSettings(): Promise<void> {
	await mutate(async () => {
		await updateCostSettings(settings.value?.revision ?? 0, { ...settingsDraft, defaultCurrency: settingsDraft.defaultCurrency.trim().toUpperCase() })
		showSettings.value = false
	})
}

onMounted(load)
</script>

<template>
	<section class="cost-panel">
		<h4>Costs</h4>
		<p v-if="error" class="cost-error" role="alert">
			{{ error }}
		</p>
		<ul v-if="totals.length > 0" class="cost-totals">
			<li v-for="total in totals" :key="total.currency">
				<strong>{{ total.total }} {{ total.currency }}</strong>
				<span v-if="total.taxMinor > 0"> ({{ total.charges }} + {{ total.tax }} tax)</span>
				<span> · {{ total.count }} {{ total.count === 1 ? 'entry' : 'entries' }}</span>
			</li>
		</ul>
		<p v-else class="cost-copy">
			No costs recorded.
		</p>
		<ul class="cost-list">
			<li v-for="entry in items" :key="entry.uuid">
				<span>{{ entry.incurredOn }} · {{ entry.description }} · {{ entry.lineType === 'tax' ? 'tax' : entry.category }}</span>
				<span>
					<strong>{{ entry.amount }} {{ entry.currency }}</strong>
					<button
						class="cost-secondary"
						type="button"
						:disabled="saving"
						@click="mutate(() => archiveCost(entry.uuid, entry.revision))">Archive</button>
				</span>
			</li>
		</ul>
		<form class="cost-form" @submit.prevent="submitCost">
			<label>Date <input v-model="draft.incurredOn" type="date" required></label>
			<label>Description <input v-model="draft.description" required maxlength="255"></label>
			<label>Amount <input
				v-model="draft.amount"
				inputmode="decimal"
				required
				pattern="[0-9]+(\.[0-9]+)?"
				:placeholder="settings?.defaultCurrency === 'JPY' ? '1200' : '12.50'"></label>
			<label v-if="settings?.multiCurrency">Currency <input v-model="draft.currency" maxlength="3" :placeholder="settings.defaultCurrency"></label>
			<label v-if="settings?.taxEnabled">Line
				<select v-model="draft.lineType">
					<option value="charge">Charge</option>
					<option value="tax">Tax</option>
				</select>
			</label>
			<label v-if="draft.lineType === 'charge'">Category
				<select v-model="draft.category">
					<option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
				</select>
			</label>
			<button type="submit" :disabled="saving">
				Add cost
			</button>
		</form>
		<button class="cost-secondary" type="button" @click="showSettings = !showSettings">
			Cost settings
		</button>
		<form v-if="showSettings" class="cost-form" @submit.prevent="submitSettings">
			<p class="cost-copy">
				Workspace owners choose the default currency and whether tax lines or multiple currencies are allowed. Currencies are never converted; totals are shown per currency.
			</p>
			<label>Default currency <input v-model="settingsDraft.defaultCurrency" maxlength="3" required></label>
			<label class="cost-check"><input v-model="settingsDraft.taxEnabled" type="checkbox"> Enable tax lines</label>
			<label class="cost-check"><input v-model="settingsDraft.multiCurrency" type="checkbox"> Enable multiple currencies</label>
			<button type="submit" :disabled="saving">
				Save settings
			</button>
		</form>
	</section>
</template>

<style scoped>
.cost-panel { display: grid; gap: 10px; }

.cost-copy { color: var(--color-text-maxcontrast); }

.cost-error { padding: 12px 16px; border: 1px solid var(--color-error); border-radius: var(--border-radius); background: var(--color-error-hover); }

.cost-totals, .cost-list { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; }

.cost-list li { display: flex; align-items: center; justify-content: space-between; gap: 12px; }

.cost-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 8px; align-items: end; }

.cost-form label { display: grid; gap: 6px; font-weight: 600; }

.cost-form .cost-check { display: flex; align-items: center; }

.cost-form input:not([type="checkbox"]), .cost-form select { min-height: 42px; padding: 8px 10px; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius); background: var(--color-main-background); color: var(--color-main-text); }

.cost-panel button { min-height: 42px; padding: 8px 16px; border: 0; border-radius: var(--border-radius-pill); background: var(--color-primary-element); color: var(--color-primary-element-text); font-weight: 700; cursor: pointer; }

.cost-panel .cost-secondary { min-height: 34px; border: 1px solid var(--color-border-maxcontrast); background: var(--color-main-background); color: var(--color-main-text); justify-self: start; }

.cost-panel button:disabled { opacity: .55; }
</style>
