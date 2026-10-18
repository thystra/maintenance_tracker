<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Asset } from '../types.ts'

import { mdiDeleteOutline, mdiPencilOutline, mdiPlus, mdiWrenchOutline } from '@mdi/js'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import ItemFormDialog from '../components/ItemFormDialog.vue'
import LogMaintenanceDialog from '../components/LogMaintenanceDialog.vue'
import PageHeader from '../components/PageHeader.vue'
import SelectField from '../components/SelectField.vue'
import { assetClassIcons, errorMessage } from '../format.ts'
import { t } from '../l10n.ts'
import { deleteAsset } from '../services/api.ts'
import { can, categoryName, loadAssets, state } from '../store.ts'

const router = useRouter()
const query = ref('')
const category = ref<string | null>(null)
const formOpen = ref(false)
const editing = ref<Asset | null>(null)
const logOpen = ref(false)
const logAsset = ref<string | null>(null)
const deleting = ref<Asset | null>(null)
const busy = ref(false)
const error = ref('')
const status = ref('')

const categoryOptions = computed(() => [...new Set(state.assets.map((asset) => asset.category))]
	.map((key) => ({ value: key, label: categoryName(key) }))
	.sort((left, right) => left.label.localeCompare(right.label)))

const visible = computed(() => {
	const needle = query.value.trim().toLowerCase()
	return state.assets.filter((asset) => (category.value === null || asset.category === category.value)
		&& (needle === '' || [asset.name, asset.manufacturer, asset.model, asset.serialNumber].some((value) => value?.toLowerCase().includes(needle))))
})

function describe(asset: Asset): string {
	return [categoryName(asset.category), [asset.modelYear, asset.manufacturer, asset.model].filter((part) => part !== null && part !== '').join(' ')].filter((part) => part !== '').join(' · ')
}

function openAdd(): void {
	editing.value = null
	formOpen.value = true
}

function openEdit(asset: Asset): void {
	editing.value = asset
	formOpen.value = true
}

function openLog(asset: Asset): void {
	logAsset.value = asset.uuid
	logOpen.value = true
}

async function saved(asset: Asset): Promise<void> {
	const wasNew = editing.value === null
	await loadAssets()
	if (wasNew) {
		await router.push({ name: 'item', params: { uuid: asset.uuid } })
	}
}

async function confirmDelete(): Promise<void> {
	const asset = deleting.value
	if (asset === null) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		await deleteAsset(asset.uuid, asset.revision)
		deleting.value = null
		await loadAssets()
		status.value = t('maintenance_tracker', 'Deleted {name}.', { name: asset.name })
	} catch (reason) {
		deleting.value = null
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div>
		<PageHeader :title="t('maintenance_tracker', 'Items')" :subtitle="t('maintenance_tracker', 'Everything you maintain in this workspace')">
			<template v-if="can.manageInventory" #actions>
				<NcButton variant="primary" @click="openAdd">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('maintenance_tracker', 'Add item') }}
				</NcButton>
			</template>
		</PageHeader>
		<p class="hidden-visually" role="status">
			{{ status }}
		</p>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>

		<NcEmptyContent
			v-if="state.assetsLoaded && state.assets.length === 0"
			:name="t('maintenance_tracker', 'No items yet')"
			:description="t('maintenance_tracker', 'Add a vehicle, appliance, building system, tool or anything else you maintain.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiWrenchOutline" :size="64" />
			</template>
			<template v-if="can.manageInventory" #action>
				<NcButton variant="primary" @click="openAdd">
					{{ t('maintenance_tracker', 'Add item') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<div class="filters">
				<NcTextField
					v-model="query"
					type="search"
					:label="t('maintenance_tracker', 'Search items')"
					trailingButtonIcon="close"
					:showTrailingButton="query !== ''"
					@trailingButtonClick="query = ''" />
				<SelectField
					v-model="category"
					:options="categoryOptions"
					:label="t('maintenance_tracker', 'Category')"
					:placeholder="t('maintenance_tracker', 'All categories')"
					clearable />
			</div>
			<p v-if="visible.length === 0" class="muted">
				{{ t('maintenance_tracker', 'No items match the filter.') }}
			</p>
			<ul class="item-list" :aria-label="t('maintenance_tracker', 'Items')">
				<NcListItem
					v-for="asset in visible"
					:key="asset.uuid"
					:name="asset.name"
					:to="{ name: 'item', params: { uuid: asset.uuid } }"
					:forceDisplayActions="true">
					<template #icon>
						<NcIconSvgWrapper :path="assetClassIcons[asset.assetClass] ?? assetClassIcons.other" />
					</template>
					<template #subname>
						{{ describe(asset) }}
					</template>
					<template #actions>
						<NcActionButton v-if="can.log" closeAfterClick @click="openLog(asset)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiWrenchOutline" />
							</template>
							{{ t('maintenance_tracker', 'Log maintenance') }}
						</NcActionButton>
						<NcActionButton v-if="can.manageInventory" closeAfterClick @click="openEdit(asset)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPencilOutline" />
							</template>
							{{ t('maintenance_tracker', 'Edit') }}
						</NcActionButton>
						<NcActionButton v-if="can.manageInventory" closeAfterClick @click="deleting = asset">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDeleteOutline" />
							</template>
							{{ t('maintenance_tracker', 'Delete') }}
						</NcActionButton>
					</template>
				</NcListItem>
			</ul>
		</template>

		<ItemFormDialog v-model:open="formOpen" :asset="editing" @saved="saved" />
		<LogMaintenanceDialog v-model:open="logOpen" :assetUuid="logAsset" />
		<ConfirmDialog
			:open="deleting !== null"
			:name="t('maintenance_tracker', 'Delete item?')"
			:message="t('maintenance_tracker', 'Delete “{name}”? It disappears from lists, reminders and the dashboard. Its history stays in exports made earlier and in the audit trail; attached files stay in Files.', { name: deleting?.name ?? '' })"
			:busy="busy"
			@update:open="deleting = $event ? deleting : null"
			@confirm="confirmDelete" />
	</div>
</template>

<style scoped>
.filters {
	display: grid;
	grid-template-columns: minmax(200px, 2fr) minmax(180px, 1fr);
	gap: 12px;
	align-items: end;
	margin-bottom: 12px;
}

.item-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.muted {
	color: var(--color-text-maxcontrast);
}

@media (max-width: 600px) {
	.filters {
		grid-template-columns: 1fr;
	}
}
</style>
