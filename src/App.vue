<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type {
	Asset,
	AssetClass,
	Capabilities,
	Category,
	Component,
	CreateAsset,
	Specification,
} from './types.ts'

import { computed, onMounted, reactive, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import {
	createAsset,
	createCategory,
	createComponent,
	createSpecification,
	getAssets,
	getCapabilities,
	getCategories,
	getComponents,
	getSpecifications,
} from './services/api.ts'

const assets = ref<Asset[]>([])
const categories = ref<Category[]>([])
const capabilities = ref<Capabilities | null>(null)
const expandedAsset = ref<string | null>(null)
const components = ref<Record<string, Component[]>>({})
const specifications = ref<Record<string, Specification[]>>({})
const loading = ref(true)
const saving = ref(false)
const error = ref('')

const draft = reactive<CreateAsset>({ name: '', category: 'vehicle', manufacturer: null, model: null })
const categoryDraft = reactive({ key: '', name: '', defaultAssetClass: 'other' as AssetClass })
const componentDraft = reactive({ name: '', type: 'component', parentUuid: '' })
const specificationDraft = reactive({ key: '', label: '', value: '', unit: '', regime: '', componentUuid: '' })
const empty = computed(() => !loading.value && assets.value.length === 0)

function categoryLabel(key: string): string {
	return categories.value.find((category) => category.key === key)?.name ?? key
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
		const [serverCapabilities, firstPage, categoryList] = await Promise.all([
			getCapabilities(),
			getAssets(),
			getCategories(),
		])
		const allAssets = [...firstPage.items]
		let nextCursor = firstPage.nextCursor
		while (nextCursor !== null) {
			const nextPage = await getAssets(nextCursor)
			allAssets.push(...nextPage.items)
			nextCursor = nextPage.nextCursor
		}
		capabilities.value = serverCapabilities
		categories.value = categoryList.items
		assets.value = allAssets.sort((left, right) => left.name.localeCompare(right.name))
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		loading.value = false
	}
}

async function submitAsset(): Promise<void> {
	if (draft.name.trim() === '') {
		return
	}
	saving.value = true
	error.value = ''
	try {
		const created = await createAsset({ ...draft, name: draft.name.trim(), manufacturer: draft.manufacturer?.trim() || null, model: draft.model?.trim() || null })
		assets.value = [...assets.value, created].sort((left, right) => left.name.localeCompare(right.name))
		draft.name = ''
		draft.manufacturer = null
		draft.model = null
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function submitCategory(): Promise<void> {
	if (categoryDraft.key.trim() === '' || categoryDraft.name.trim() === '') {
		return
	}
	saving.value = true
	error.value = ''
	try {
		const created = await createCategory({ key: categoryDraft.key.trim(), name: categoryDraft.name.trim(), defaultAssetClass: categoryDraft.defaultAssetClass })
		categories.value = [...categories.value, created]
		categoryDraft.key = ''
		categoryDraft.name = ''
		categoryDraft.defaultAssetClass = 'other'
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function toggleAsset(asset: Asset): Promise<void> {
	if (expandedAsset.value === asset.uuid) {
		expandedAsset.value = null
		return
	}
	expandedAsset.value = asset.uuid
	try {
		const [componentList, specificationList] = await Promise.all([getComponents(asset.uuid), getSpecifications(asset.uuid)])
		components.value[asset.uuid] = componentList.items
		specifications.value[asset.uuid] = specificationList.items
	} catch (reason) {
		error.value = readableError(reason)
	}
}

async function submitComponent(asset: Asset): Promise<void> {
	if (componentDraft.name.trim() === '') {
		return
	}
	saving.value = true
	try {
		const created = await createComponent(asset.uuid, { name: componentDraft.name.trim(), type: componentDraft.type.trim() || 'component', parentUuid: componentDraft.parentUuid || null })
		components.value[asset.uuid] = [...(components.value[asset.uuid] ?? []), created]
		componentDraft.name = ''
		componentDraft.type = 'component'
		componentDraft.parentUuid = ''
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function submitSpecification(asset: Asset): Promise<void> {
	if (specificationDraft.key.trim() === '' || specificationDraft.label.trim() === '') {
		return
	}
	saving.value = true
	try {
		const created = await createSpecification(asset.uuid, {
			key: specificationDraft.key.trim(),
			label: specificationDraft.label.trim(),
			value: specificationDraft.value,
			unit: specificationDraft.unit.trim() || null,
			regime: specificationDraft.regime.trim() || null,
			componentUuid: specificationDraft.componentUuid || null,
		})
		specifications.value[asset.uuid] = [...(specifications.value[asset.uuid] ?? []), created]
		specificationDraft.key = ''
		specificationDraft.label = ''
		specificationDraft.value = ''
		specificationDraft.unit = ''
		specificationDraft.regime = ''
		specificationDraft.componentUuid = ''
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
							Inventory and configuration
						</p>
						<h1>Maintenance Tracker</h1>
						<p class="intro">
							Define maintained items, their component instances, and structured specifications. Maintenance rules and work records build on this inventory.
						</p>
					</div>
					<span v-if="capabilities" class="pill">{{ capabilities.appVersion }}</span>
				</header>

				<p v-if="error" class="notice notice--error" role="alert">
					{{ error }}
				</p>

				<section class="panel">
					<h2>Add maintained item</h2>
					<form class="form-grid" @submit.prevent="submitAsset">
						<label><span>Name</span><input
							v-model="draft.name"
							required
							maxlength="255"
							placeholder="2020 Ford F-350"></label>
						<label><span>Category</span><select v-model="draft.category"><option v-for="category in categories" :key="category.key" :value="category.key">{{ category.name }}</option></select></label>
						<label><span>Manufacturer</span><input v-model="draft.manufacturer" maxlength="255" placeholder="Optional"></label>
						<label><span>Model</span><input v-model="draft.model" maxlength="255" placeholder="Optional"></label>
						<button type="submit" :disabled="saving">
							Add item
						</button>
					</form>
				</section>

				<section class="panel">
					<h2>Custom categories</h2>
					<form class="form-grid form-grid--category" @submit.prevent="submitCategory">
						<label><span>Key</span><input v-model="categoryDraft.key" pattern="[a-z0-9][a-z0-9_-]*" placeholder="marine"></label>
						<label><span>Name</span><input v-model="categoryDraft.name" placeholder="Marine"></label>
						<label><span>Default asset class</span><select v-model="categoryDraft.defaultAssetClass"><option v-for="value in ['vehicle', 'trailer', 'building', 'equipment', 'appliance', 'system', 'tool', 'medical_device', 'location', 'other']" :key="value" :value="value">{{ value }}</option></select></label>
						<button type="submit" :disabled="saving">
							Add category
						</button>
					</form>
				</section>

				<section class="panel">
					<div class="section-heading">
						<div>
							<h2>Your items</h2><p v-if="!loading">
								{{ assets.length }} tracked
							</p>
						</div><button
							class="button--secondary"
							type="button"
							:disabled="loading"
							@click="load">
							Refresh
						</button>
					</div>
					<p v-if="loading" class="empty-state">
						Loading maintenance records…
					</p>
					<div v-else-if="empty" class="empty-state">
						<strong>No items yet.</strong><span>Add the first thing you want to maintain.</span>
					</div>
					<ul v-else class="asset-list">
						<li v-for="asset in assets" :key="asset.uuid" class="asset-card">
							<div class="asset-summary">
								<div><span class="category">{{ categoryLabel(asset.category) }} · {{ asset.assetClass }}</span><h3>{{ asset.name }}</h3><p>{{ [asset.manufacturer, asset.model].filter(Boolean).join(' · ') || 'Details not added yet' }}</p></div>
								<button class="button--secondary" type="button" @click="toggleAsset(asset)">
									{{ expandedAsset === asset.uuid ? 'Close' : 'Components & specs' }}
								</button>
							</div>
							<div v-if="expandedAsset === asset.uuid" class="asset-details">
								<section>
									<h4>Components</h4><ul class="compact-list">
										<li v-for="component in components[asset.uuid] ?? []" :key="component.uuid">
											<strong>{{ component.name }}</strong><span>{{ component.type }}<template v-if="component.parentUuid"> · nested</template></span>
										</li>
									</ul>
									<form class="inline-form" @submit.prevent="submitComponent(asset)">
										<input v-model="componentDraft.name" placeholder="Component name" required><input v-model="componentDraft.type" placeholder="type key"><select v-model="componentDraft.parentUuid">
											<option value="">
												No parent
											</option><option v-for="component in components[asset.uuid] ?? []" :key="component.uuid" :value="component.uuid">
												{{ component.name }}
											</option>
										</select><button type="submit">
											Add component
										</button>
									</form>
								</section>
								<section>
									<h4>Specifications</h4><ul class="compact-list">
										<li v-for="spec in specifications[asset.uuid] ?? []" :key="spec.uuid">
											<strong>{{ spec.label }}</strong><span>{{ String(spec.value) }}{{ spec.unit ? ` ${spec.unit}` : '' }}{{ spec.regime ? ` · ${spec.regime}` : '' }}</span>
										</li>
									</ul>
									<form class="spec-form" @submit.prevent="submitSpecification(asset)">
										<input v-model="specificationDraft.key" placeholder="engine.oil.capacity" required><input v-model="specificationDraft.label" placeholder="Engine oil capacity" required><input v-model="specificationDraft.value" placeholder="13"><input v-model="specificationDraft.unit" placeholder="qt"><input v-model="specificationDraft.regime" placeholder="regime (optional)"><select v-model="specificationDraft.componentUuid">
											<option value="">
												Whole asset
											</option><option v-for="component in components[asset.uuid] ?? []" :key="component.uuid" :value="component.uuid">
												{{ component.name }}
											</option>
										</select><button type="submit">
											Add specification
										</button>
									</form>
								</section>
							</div>
						</li>
					</ul>
				</section>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.maintenance-content { overflow: auto; background: var(--color-main-background); }

.page-shell { width: min(1180px, calc(100% - 32px)); margin: 0 auto; padding: 36px 0 64px; }

.page-header, .section-heading, .asset-summary { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; }

.page-header { margin-bottom: 28px; }

h1 { margin: 2px 0 8px; font-size: clamp(2rem, 5vw, 3.25rem); line-height: 1.05; }

h2, h3, h4 { margin-top: 0; }

.eyebrow, .category { color: var(--color-primary-element); font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }

.intro { max-width: 760px; color: var(--color-text-maxcontrast); line-height: 1.55; }

.pill { padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--border-radius-pill); }

.panel { margin-top: 20px; padding: 24px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }

.form-grid { display: grid; grid-template-columns: minmax(180px, 2fr) repeat(3, minmax(140px, 1fr)) auto; align-items: end; gap: 14px; }

.form-grid--category { grid-template-columns: 1fr 1.5fr 1.5fr auto; }

label { display: grid; gap: 6px; font-weight: 600; }

input, select { min-height: 42px; padding: 8px 10px; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius); background: var(--color-main-background); color: var(--color-main-text); }

button { min-height: 42px; padding: 8px 16px; border: 0; border-radius: var(--border-radius-pill); background: var(--color-primary-element); color: var(--color-primary-element-text); font-weight: 700; cursor: pointer; }

button:disabled { opacity: .55; }

.button--secondary { border: 1px solid var(--color-border-maxcontrast); background: var(--color-main-background); color: var(--color-main-text); }

.notice { padding: 12px 16px; border-radius: var(--border-radius); }

.notice--error { border: 1px solid var(--color-error); background: var(--color-error-hover); }

.asset-list, .compact-list { margin: 0; padding: 0; list-style: none; }

.asset-list { display: grid; gap: 14px; }

.asset-card { padding: 18px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-background-hover); }

.asset-summary p, .section-heading p { margin: 5px 0 0; color: var(--color-text-maxcontrast); }

.asset-details { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--color-border); }

.compact-list { display: grid; gap: 6px; margin-bottom: 12px; }

.compact-list li { display: flex; justify-content: space-between; gap: 12px; }

.compact-list span { color: var(--color-text-maxcontrast); }

.inline-form, .spec-form { display: grid; gap: 8px; }

.inline-form { grid-template-columns: 1.4fr 1fr 1.2fr auto; }

.spec-form { grid-template-columns: repeat(3, minmax(0, 1fr)); }

.spec-form button { grid-column: 3; }

.empty-state { display: grid; place-items: center; min-height: 130px; color: var(--color-text-maxcontrast); }
@media (max-width: 900px) { .form-grid, .form-grid--category, .asset-details { grid-template-columns: 1fr 1fr; }.inline-form, .spec-form { grid-template-columns: 1fr 1fr; }.spec-form button { grid-column: auto; } }
@media (max-width: 600px) { .page-shell { width: min(100% - 20px, 1180px); padding-top: 22px; }.page-header, .asset-summary, .form-grid, .form-grid--category, .asset-details, .inline-form, .spec-form { display: grid; grid-template-columns: 1fr; } }
</style>
