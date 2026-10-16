<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Part, Vendor } from '../catalogTypes.ts'

import { onMounted, reactive, ref } from 'vue'
import OutsideLinks from './OutsideLinks.vue'
import { archivePart, archiveVendor, createPart, createVendor, getParts, getVendors } from '../services/catalogApi.ts'

const emit = defineEmits<{ (event: 'changed'): void }>()

const parts = ref<Part[]>([])
const vendors = ref<Vendor[]>([])
const error = ref('')
const saving = ref(false)
const partDraft = reactive({ manufacturer: '', partNumber: '', description: '' })
const vendorDraft = reactive({ name: '', notes: '' })

function readableError(reason: unknown): string {
	return reason instanceof Error && reason.message !== '' ? reason.message : 'The request could not be completed.'
}

async function load(): Promise<void> {
	try {
		const [partList, vendorList] = await Promise.all([getParts(), getVendors()])
		parts.value = partList.items
		vendors.value = vendorList.items
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
		emit('changed')
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function submitPart(): Promise<void> {
	await mutate(async () => {
		await createPart({ uuid: crypto.randomUUID(), ...partDraft })
		Object.assign(partDraft, { manufacturer: '', partNumber: '', description: '' })
	})
}

async function submitVendor(): Promise<void> {
	await mutate(async () => {
		await createVendor({ uuid: crypto.randomUUID(), name: vendorDraft.name, notes: vendorDraft.notes || null })
		Object.assign(vendorDraft, { name: '', notes: '' })
	})
}

defineExpose({ parts, vendors, reload: load })

onMounted(load)
</script>

<template>
	<section class="catalog-panel">
		<h2>Parts and vendors</h2>
		<p class="catalog-copy">
			Canonical parts are shared across fitment packs, profiles, and recorded work. Manufacturer and part number form a part's identity and cannot be edited after creation.
		</p>
		<p v-if="error" class="catalog-error" role="alert">
			{{ error }}
		</p>
		<div class="catalog-columns">
			<div>
				<h3>Parts</h3>
				<ul class="catalog-list">
					<li v-for="part in parts" :key="part.uuid">
						<span><strong>{{ part.manufacturer }} {{ part.partNumber }}</strong> · {{ part.description }}</span>
						<button
							type="button"
							class="catalog-secondary"
							:disabled="saving"
							@click="mutate(() => archivePart(part.uuid, part.revision))">
							Archive
						</button>
						<OutsideLinks subjectType="part" :subjectUuid="part.uuid" />
					</li>
					<li v-if="parts.length === 0">
						<span>No parts yet.</span>
					</li>
				</ul>
				<form class="catalog-form" @submit.prevent="submitPart">
					<label><span>Manufacturer</span><input v-model="partDraft.manufacturer" required maxlength="255"></label>
					<label><span>Part number</span><input v-model="partDraft.partNumber" required maxlength="128"></label>
					<label><span>Description</span><input v-model="partDraft.description" required maxlength="1000"></label>
					<button type="submit" :disabled="saving">
						Add part
					</button>
				</form>
			</div>
			<div>
				<h3>Vendors</h3>
				<ul class="catalog-list">
					<li v-for="vendor in vendors" :key="vendor.uuid">
						<span><strong>{{ vendor.name }}</strong><template v-if="vendor.notes"> · {{ vendor.notes }}</template></span>
						<button
							type="button"
							class="catalog-secondary"
							:disabled="saving"
							@click="mutate(() => archiveVendor(vendor.uuid, vendor.revision))">
							Archive
						</button>
						<OutsideLinks subjectType="vendor" :subjectUuid="vendor.uuid" />
					</li>
					<li v-if="vendors.length === 0">
						<span>No vendors yet.</span>
					</li>
				</ul>
				<form class="catalog-form" @submit.prevent="submitVendor">
					<label><span>Name</span><input v-model="vendorDraft.name" required maxlength="255"></label>
					<label><span>Notes</span><input v-model="vendorDraft.notes" maxlength="2000" placeholder="Optional"></label>
					<button type="submit" :disabled="saving">
						Add vendor
					</button>
				</form>
			</div>
		</div>
	</section>
</template>

<style scoped>
.catalog-panel { margin-top: 20px; padding: 24px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }

.catalog-copy { color: var(--color-text-maxcontrast); }

.catalog-error { padding: 12px 16px; border: 1px solid var(--color-error); border-radius: var(--border-radius); background: var(--color-error-hover); }

.catalog-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }

.catalog-list { display: grid; gap: 6px; margin: 0 0 12px; padding: 0; list-style: none; }

.catalog-list li { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }

.catalog-form { display: grid; gap: 8px; }

.catalog-form label { display: grid; gap: 6px; font-weight: 600; }

.catalog-form input { min-height: 42px; padding: 8px 10px; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius); background: var(--color-main-background); color: var(--color-main-text); }

.catalog-panel button { min-height: 42px; padding: 8px 16px; border: 0; border-radius: var(--border-radius-pill); background: var(--color-primary-element); color: var(--color-primary-element-text); font-weight: 700; cursor: pointer; }

.catalog-panel .catalog-secondary { min-height: 34px; border: 1px solid var(--color-border-maxcontrast); background: var(--color-main-background); color: var(--color-main-text); }

.catalog-panel button:disabled { opacity: .55; }

@media (max-width: 800px) {
	.catalog-columns { grid-template-columns: 1fr; }
}
</style>
