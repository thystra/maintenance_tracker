<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// The workspace part catalog: what you buy, from whom, what it fits.
import type { Part, Vendor } from '../catalogTypes.ts'

import { mdiCogOutline, mdiDeleteOutline, mdiPencilOutline, mdiPlus } from '@mdi/js'
import { computed, onMounted, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import OutsideLinks from '../components/OutsideLinks.vue'
import PageHeader from '../components/PageHeader.vue'
import PartFormDialog from '../components/PartFormDialog.vue'
import SelectField from '../components/SelectField.vue'
import { errorMessage } from '../format.ts'
import { t } from '../l10n.ts'
import { addPartFit, archivePart, getCostSettings, getParts, getVendors, removePartFit } from '../services/catalogApi.ts'
import { can, state } from '../store.ts'

const parts = ref<Part[]>([])
const vendors = ref<Vendor[]>([])
const defaultCurrency = ref('USD')
const loading = ref(true)
const query = ref('')
const error = ref('')
const status = ref('')
const formOpen = ref(false)
const editing = ref<Part | null>(null)
const expanded = ref<string | null>(null)
const deleting = ref<Part | null>(null)
const fitTarget = ref<string | null>(null)
const busy = ref(false)

const visible = computed(() => {
	const needle = query.value.trim().toLowerCase()
	return parts.value.filter((part) => needle === '' || [part.description, part.manufacturer, part.partNumber, part.vendorName, ...part.fits.map((fit) => fit.assetName)]
		.some((value) => value?.toLowerCase().includes(needle)))
})
const expandedPart = computed(() => parts.value.find((part) => part.uuid === expanded.value) ?? null)
const fitOptions = computed(() => state.assets
	.filter((asset) => !expandedPart.value?.fits.some((fit) => fit.assetUuid === asset.uuid))
	.map((asset) => ({ value: asset.uuid, label: asset.name })))

function subname(part: Part): string {
	return [`${part.manufacturer} ${part.partNumber}`, part.vendorName].filter(Boolean).join(' · ')
}

function fitsText(part: Part): string {
	if (part.fits.length === 0) {
		return ''
	}
	const names = part.fits.slice(0, 3).map((fit) => fit.assetName).join(', ')
	return part.fits.length > 3 ? t('maintenance_tracker', 'Fits {names} and {count} more', { names, count: part.fits.length - 3 }) : t('maintenance_tracker', 'Fits {names}', { names })
}

async function load(): Promise<void> {
	loading.value = true
	try {
		const [partList, vendorList] = await Promise.all([getParts(), getVendors()])
		parts.value = partList.items.sort((left, right) => left.description.localeCompare(right.description))
		vendors.value = vendorList.items
		try {
			defaultCurrency.value = (await getCostSettings()).defaultCurrency
		} catch {
			// Keep the fallback; viewers may not read cost settings.
		}
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		loading.value = false
	}
}

function replace(part: Part): void {
	const index = parts.value.findIndex((candidate) => candidate.uuid === part.uuid)
	if (index === -1) {
		parts.value = [...parts.value, part].sort((left, right) => left.description.localeCompare(right.description))
	} else {
		parts.value.splice(index, 1, part)
	}
}

function openForm(part: Part | null): void {
	editing.value = part
	formOpen.value = true
}

function saved(part: Part): void {
	replace(part)
	status.value = t('maintenance_tracker', 'Saved {name}.', { name: part.description })
}

async function mutate(operation: () => Promise<Part>): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		replace(await operation())
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

async function addFit(): Promise<void> {
	const part = expandedPart.value
	const asset = fitTarget.value
	if (part === null || asset === null) {
		return
	}
	await mutate(() => addPartFit(part.uuid, part.revision, asset))
	fitTarget.value = null
}

async function remove(): Promise<void> {
	const part = deleting.value
	if (part === null) {
		return
	}
	busy.value = true
	try {
		await archivePart(part.uuid, part.revision)
		parts.value = parts.value.filter((candidate) => candidate.uuid !== part.uuid)
		status.value = t('maintenance_tracker', 'Deleted {name}.', { name: part.description })
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		deleting.value = null
		busy.value = false
	}
}

onMounted(load)
</script>

<template>
	<div>
		<PageHeader :title="t('maintenance_tracker', 'Parts')" :subtitle="t('maintenance_tracker', 'Filters, fluids and spares you buy, and what they fit')">
			<template v-if="can.manageCatalog" #actions>
				<NcButton variant="primary" @click="openForm(null)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('maintenance_tracker', 'Add part') }}
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
		<NcLoadingIcon v-if="loading" :size="44" :name="t('maintenance_tracker', 'Loading')" />
		<NcEmptyContent
			v-else-if="parts.length === 0"
			:name="t('maintenance_tracker', 'No parts yet')"
			:description="t('maintenance_tracker', 'Record the parts you use so you can reorder the right one and log what went in.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiCogOutline" :size="64" />
			</template>
			<template v-if="can.manageCatalog" #action>
				<NcButton variant="primary" @click="openForm(null)">
					{{ t('maintenance_tracker', 'Add part') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<template v-else>
			<div class="filters">
				<NcTextField v-model="query" type="search" :label="t('maintenance_tracker', 'Search parts')" />
			</div>
			<ul class="list" :aria-label="t('maintenance_tracker', 'Parts')">
				<template v-for="part in visible" :key="part.uuid">
					<NcListItem
						:name="part.description"
						:active="expanded === part.uuid"
						:forceDisplayActions="true"
						@click="expanded = expanded === part.uuid ? null : part.uuid">
						<template #subname>
							{{ subname(part) }}
						</template>
						<template #details>
							<span v-if="part.unitPrice">{{ part.unitPrice }} {{ part.currency }}</span>
						</template>
						<template #indicator>
							<span class="fits">{{ fitsText(part) }}</span>
						</template>
						<template v-if="can.manageCatalog" #actions>
							<NcActionButton closeAfterClick @click="openForm(part)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiPencilOutline" />
								</template>
								{{ t('maintenance_tracker', 'Edit') }}
							</NcActionButton>
							<NcActionButton closeAfterClick @click="deleting = part">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDeleteOutline" />
								</template>
								{{ t('maintenance_tracker', 'Delete') }}
							</NcActionButton>
						</template>
					</NcListItem>
					<li v-if="expanded === part.uuid" class="detail">
						<p v-if="part.notes" class="notes">
							{{ part.notes }}
						</p>
						<h3>{{ t('maintenance_tracker', 'Fits') }}</h3>
						<p v-if="part.fits.length === 0" class="muted">
							{{ t('maintenance_tracker', 'Not marked as fitting any item.') }}
						</p>
						<ul class="plain">
							<li v-for="fit in part.fits" :key="fit.assetUuid">
								<RouterLink :to="{ name: 'item', params: { uuid: fit.assetUuid } }">
									{{ fit.assetName }}
								</RouterLink>
								<span v-if="fit.source === 'profile'" class="muted"> · {{ t('maintenance_tracker', 'from profile') }}</span>
								<NcButton
									v-else-if="can.manageCatalog"
									variant="tertiary"
									size="small"
									:disabled="busy"
									@click="mutate(() => removePartFit(part.uuid, part.revision, fit.assetUuid))">
									{{ t('maintenance_tracker', 'Remove') }}
								</NcButton>
							</li>
						</ul>
						<div v-if="can.manageCatalog && fitOptions.length > 0" class="fit-add">
							<SelectField v-model="fitTarget" :options="fitOptions" :label="t('maintenance_tracker', 'Mark as fitting')" />
							<NcButton :disabled="busy || fitTarget === null" @click="addFit">
								{{ t('maintenance_tracker', 'Add') }}
							</NcButton>
						</div>
						<h3>{{ t('maintenance_tracker', 'Where to buy') }}</h3>
						<OutsideLinks subjectType="part" :subjectUuid="part.uuid" />
					</li>
				</template>
			</ul>
		</template>

		<PartFormDialog
			v-model:open="formOpen"
			:part="editing"
			:vendors="vendors"
			:defaultCurrency="defaultCurrency"
			@saved="saved" />
		<ConfirmDialog
			:open="deleting !== null"
			:name="t('maintenance_tracker', 'Delete part?')"
			:message="t('maintenance_tracker', 'Delete “{name}” from the catalog? Records that used it keep their copy.', { name: deleting?.description ?? '' })"
			:busy="busy"
			@update:open="deleting = $event ? deleting : null"
			@confirm="remove" />
	</div>
</template>

<style scoped>
.filters {
	max-width: 420px;
	margin-bottom: 12px;
}

.list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.fits {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.detail {
	margin: 0 8px 12px 52px;
	padding: 8px 12px;
	border-inline-start: 3px solid var(--color-primary-element);
}

.detail h3 {
	margin: 8px 0 4px;
	font-size: 15px;
}

.plain {
	margin: 0;
	padding: 0;
	list-style: none;
}

.plain li {
	display: flex;
	align-items: center;
	gap: 6px;
}

.plain a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.fit-add {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: end;
	max-width: 480px;
	margin-top: 8px;
}

.fit-add > :first-child {
	flex: 1;
}

.notes {
	white-space: pre-wrap;
}

.muted {
	color: var(--color-text-maxcontrast);
}

@media (max-width: 600px) {
	.detail {
		margin-inline-start: 8px;
	}
}
</style>
