<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { AssetClass } from '../../types.ts'

import { reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SelectField from '../SelectField.vue'
import { assetClasses, assetClassLabel, errorMessage, keyFrom } from '../../format.ts'
import { t } from '../../l10n.ts'
import { createCategory } from '../../services/api.ts'
import { can, loadCategories, state } from '../../store.ts'

const draft = reactive({ name: '', assetClass: 'equipment' as string | null, description: '' })
const busy = ref(false)
const error = ref('')
const status = ref('')
const classOptions = assetClasses.map((value) => ({ value, label: assetClassLabel(value) }))

async function add(): Promise<void> {
	if (draft.name.trim() === '') {
		return
	}
	busy.value = true
	error.value = ''
	status.value = ''
	try {
		const created = await createCategory({ uuid: crypto.randomUUID(), key: keyFrom(draft.name), name: draft.name.trim(), defaultAssetClass: (draft.assetClass ?? 'other') as AssetClass, description: draft.description.trim() || null })
		await loadCategories()
		status.value = t('maintenance_tracker', 'Added {name}.', { name: created.name })
		Object.assign(draft, { name: '', description: '' })
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}
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
			{{ t('maintenance_tracker', 'Categories group items in lists and filters. Built-in categories are always available.') }}
		</p>
		<ul class="chips" :aria-label="t('maintenance_tracker', 'Categories')">
			<li v-for="category in state.categories" :key="category.key">
				{{ category.name }}<span v-if="!category.builtIn" class="muted"> · {{ t('maintenance_tracker', 'custom') }}</span>
			</li>
		</ul>
		<form v-if="can.manageInventory" class="inline-form" @submit.prevent="add">
			<h3>{{ t('maintenance_tracker', 'Add category') }}</h3>
			<div class="row">
				<NcTextField
					v-model="draft.name"
					:label="t('maintenance_tracker', 'Name')"
					maxlength="120"
					required />
				<SelectField v-model="draft.assetClass" :options="classOptions" :label="t('maintenance_tracker', 'Kind of item')" />
			</div>
			<NcTextField v-model="draft.description" :label="t('maintenance_tracker', 'Description (optional)')" />
			<NcButton type="submit" :disabled="busy || draft.name.trim() === ''">
				{{ t('maintenance_tracker', 'Add category') }}
			</NcButton>
		</form>
	</div>
</template>

<style scoped>
.chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin: 0 0 12px;
	padding: 0;
	list-style: none;
}

.chips li {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
}
</style>
