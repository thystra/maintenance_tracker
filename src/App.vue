<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type {
	Asset,
	Capabilities,
	CreateAsset,
} from './types.ts'

import { computed, onMounted, reactive, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import {
	createAsset,
	getAssets,
	getCapabilities,
} from './services/api.ts'

const categories = [
	{ key: 'vehicle', label: 'Vehicle' },
	{ key: 'home', label: 'Home' },
	{ key: 'tool', label: 'Tool' },
	{ key: 'health', label: 'Health equipment' },
	{ key: 'outdoor', label: 'Outdoor equipment' },
	{ key: 'other', label: 'Other' },
]

const assets = ref<Asset[]>([])
const capabilities = ref<Capabilities | null>(null)
const loading = ref(true)
const saving = ref(false)
const error = ref('')

const draft = reactive<CreateAsset>({
	name: '',
	category: 'vehicle',
	manufacturer: null,
	model: null,
})

const empty = computed(() => !loading.value && assets.value.length === 0)

function categoryLabel(key: string): string {
	return categories.find((category) => category.key === key)?.label ?? key
}

function readableError(reason: unknown): string {
	if (reason instanceof Error && reason.message !== '') {
		return reason.message
	}
	return 'The request could not be completed.'
}

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		const [serverCapabilities, firstPage] = await Promise.all([
			getCapabilities(),
			getAssets(),
		])
		const allAssets = [...firstPage.items]
		let nextCursor = firstPage.nextCursor
		while (nextCursor !== null) {
			const nextPage = await getAssets(nextCursor)
			allAssets.push(...nextPage.items)
			nextCursor = nextPage.nextCursor
		}

		capabilities.value = serverCapabilities
		assets.value = allAssets.sort((left, right) => left.name.localeCompare(right.name))
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		loading.value = false
	}
}

async function submit(): Promise<void> {
	if (draft.name.trim() === '') {
		error.value = 'Enter a name for the item.'
		return
	}

	saving.value = true
	error.value = ''
	try {
		const created = await createAsset({
			name: draft.name.trim(),
			category: draft.category,
			manufacturer: draft.manufacturer?.trim() || null,
			model: draft.model?.trim() || null,
		})
		assets.value = [...assets.value, created]
			.sort((left, right) => left.name.localeCompare(right.name))
		draft.name = ''
		draft.manufacturer = null
		draft.model = null
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

onMounted(load)
</script>

<template>
	<NcContent appName="maintenance_tracker">
		<NcAppContent class="maintenance-content">
			<div class="page-shell">
				<header class="page-header">
					<div>
						<p class="eyebrow">
							Self-hosted maintenance records
						</p>
						<h1>Maintenance Tracker</h1>
						<p class="intro">
							Build an inventory first. Schedules, meter readings,
							service logs, costs, and reminders will attach to these items.
						</p>
					</div>
					<span v-if="capabilities" class="version">
						Foundation {{ capabilities.appVersion }}
					</span>
				</header>

				<p v-if="error" class="notice notice--error" role="alert">
					{{ error }}
				</p>

				<section class="panel" aria-labelledby="new-item-heading">
					<div class="section-heading">
						<div>
							<h2 id="new-item-heading">
								Add maintained item
							</h2>
							<p>Vehicles, HVAC equipment, tools, CPAP gear, and more.</p>
						</div>
					</div>

					<form class="asset-form" @submit.prevent="submit">
						<label>
							<span>Name</span>
							<input
								v-model="draft.name"
								name="name"
								maxlength="255"
								placeholder="e.g. 2020 Ford F-350"
								required>
						</label>

						<label>
							<span>Category</span>
							<select v-model="draft.category" name="category">
								<option
									v-for="category in categories"
									:key="category.key"
									:value="category.key">
									{{ category.label }}
								</option>
							</select>
						</label>

						<label>
							<span>Manufacturer</span>
							<input
								v-model="draft.manufacturer"
								name="manufacturer"
								maxlength="255"
								placeholder="Optional">
						</label>

						<label>
							<span>Model</span>
							<input
								v-model="draft.model"
								name="model"
								maxlength="255"
								placeholder="Optional">
						</label>

						<button type="submit" :disabled="saving">
							{{ saving ? 'Adding…' : 'Add item' }}
						</button>
					</form>
				</section>

				<section class="panel" aria-labelledby="items-heading">
					<div class="section-heading">
						<div>
							<h2 id="items-heading">
								Your items
							</h2>
							<p v-if="!loading">
								{{ assets.length }} tracked
							</p>
						</div>
						<button
							class="button--secondary"
							type="button"
							:disabled="loading"
							@click="load">
							Refresh
						</button>
					</div>

					<p v-if="loading" class="empty-state" aria-live="polite">
						Loading maintenance records…
					</p>

					<div v-else-if="empty" class="empty-state">
						<strong>No items yet.</strong>
						<span>Add the first thing you want to maintain.</span>
					</div>

					<ul v-else class="asset-grid">
						<li v-for="asset in assets" :key="asset.uuid" class="asset-card">
							<div>
								<span class="category">
									{{ categoryLabel(asset.category) }}
								</span>
								<h3>{{ asset.name }}</h3>
								<p v-if="asset.manufacturer || asset.model">
									{{ [asset.manufacturer, asset.model].filter(Boolean).join(' · ') }}
								</p>
								<p v-else class="muted">
									Details not added yet
								</p>
							</div>
							<span class="status">{{ asset.status }}</span>
						</li>
					</ul>
				</section>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.maintenance-content {
	overflow: auto;
	background:
		radial-gradient(circle at top right, rgba(0, 130, 201, 0.09), transparent 32rem),
		var(--color-main-background);
}

.page-shell {
	width: min(1120px, calc(100% - 32px));
	margin: 0 auto;
	padding: 36px 0 64px;
}

.page-header,
.section-heading,
.asset-card {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 20px;
}

.page-header {
	margin-bottom: 28px;
}

.page-header h1 {
	margin: 2px 0 8px;
	font-size: clamp(2rem, 5vw, 3.25rem);
	line-height: 1.05;
}

.eyebrow,
.category {
	color: var(--color-primary-element);
	font-size: 0.78rem;
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
}

.intro {
	max-width: 720px;
	color: var(--color-text-maxcontrast);
	font-size: 1.05rem;
	line-height: 1.55;
}

.version,
.status {
	flex: 0 0 auto;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: 999px;
	background: var(--color-background-hover);
	font-size: 0.8rem;
}

.panel {
	margin-top: 20px;
	padding: 24px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	box-shadow: 0 12px 35px rgba(0, 0, 0, 0.04);
}

.section-heading {
	margin-bottom: 20px;
}

.section-heading h2,
.asset-card h3 {
	margin: 0;
}

.section-heading p,
.asset-card p {
	margin: 5px 0 0;
	color: var(--color-text-maxcontrast);
}

.asset-form {
	display: grid;
	grid-template-columns: minmax(180px, 2fr) repeat(3, minmax(140px, 1fr)) auto;
	align-items: end;
	gap: 14px;
}

.asset-form label {
	display: grid;
	gap: 6px;
	font-weight: 600;
}

.asset-form input,
.asset-form select {
	min-height: 42px;
	padding: 8px 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

button {
	min-height: 42px;
	padding: 8px 16px;
	border: 0;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 700;
	cursor: pointer;
}

button:disabled {
	opacity: 0.55;
	cursor: progress;
}

.button--secondary {
	border: 1px solid var(--color-border-maxcontrast);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.notice {
	padding: 12px 16px;
	border-radius: var(--border-radius);
}

.notice--error {
	border: 1px solid var(--color-error);
	background: var(--color-error-hover);
}

.asset-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 14px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.asset-card {
	min-height: 120px;
	padding: 18px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.asset-card h3 {
	margin-top: 8px;
	font-size: 1.1rem;
}

.status {
	text-transform: capitalize;
}

.empty-state {
	display: grid;
	place-items: center;
	gap: 6px;
	min-height: 150px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.muted {
	font-style: italic;
}

@media (max-width: 900px) {
	.asset-form {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}

@media (max-width: 600px) {
	.page-shell {
		width: min(100% - 20px, 1120px);
		padding-top: 22px;
	}

	.page-header {
		display: grid;
	}

	.asset-form {
		grid-template-columns: 1fr;
	}

	.panel {
		padding: 18px;
	}
}
</style>
