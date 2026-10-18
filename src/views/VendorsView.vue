<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Shops, suppliers and service providers.
import type { Vendor } from '../catalogTypes.ts'

import { mdiDeleteOutline, mdiPencilOutline, mdiPlus, mdiStorefrontOutline } from '@mdi/js'
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
import VendorFormDialog from '../components/VendorFormDialog.vue'
import { errorMessage } from '../format.ts'
import { t } from '../l10n.ts'
import { archiveVendor, getVendors } from '../services/catalogApi.ts'
import { can } from '../store.ts'

const vendors = ref<Vendor[]>([])
const websitesEnabled = ref(true)
const loading = ref(true)
const query = ref('')
const error = ref('')
const status = ref('')
const formOpen = ref(false)
const editing = ref<Vendor | null>(null)
const expanded = ref<string | null>(null)
const deleting = ref<Vendor | null>(null)
const busy = ref(false)

const visible = computed(() => {
	const needle = query.value.trim().toLowerCase()
	return vendors.value.filter((vendor) => needle === '' || [vendor.name, vendor.contactName, vendor.address, vendor.email].some((value) => value?.toLowerCase().includes(needle)))
})

/**
 * Only https URLs are ever bound to href.
 *
 * @param url Stored website.
 */
function safeHref(url: string | null): string | undefined {
	return url !== null && url.startsWith('https://') ? url : undefined
}

function host(url: string): string {
	try {
		return new URL(url).host
	} catch {
		return url
	}
}

async function load(): Promise<void> {
	loading.value = true
	try {
		const list = await getVendors()
		vendors.value = list.items.sort((left, right) => left.name.localeCompare(right.name))
		websitesEnabled.value = list.websitesEnabled ?? true
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		loading.value = false
	}
}

function openForm(vendor: Vendor | null): void {
	editing.value = vendor
	formOpen.value = true
}

function saved(vendor: Vendor): void {
	const index = vendors.value.findIndex((candidate) => candidate.uuid === vendor.uuid)
	if (index === -1) {
		vendors.value = [...vendors.value, vendor].sort((left, right) => left.name.localeCompare(right.name))
	} else {
		vendors.value.splice(index, 1, vendor)
	}
	status.value = t('maintenance_tracker', 'Saved {name}.', { name: vendor.name })
}

async function remove(): Promise<void> {
	const vendor = deleting.value
	if (vendor === null) {
		return
	}
	busy.value = true
	try {
		await archiveVendor(vendor.uuid, vendor.revision)
		vendors.value = vendors.value.filter((candidate) => candidate.uuid !== vendor.uuid)
		status.value = t('maintenance_tracker', 'Deleted {name}.', { name: vendor.name })
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
		<PageHeader :title="t('maintenance_tracker', 'Vendors')" :subtitle="t('maintenance_tracker', 'Where you buy parts and who does the work')">
			<template v-if="can.manageCatalog" #actions>
				<NcButton variant="primary" @click="openForm(null)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('maintenance_tracker', 'Add vendor') }}
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
			v-else-if="vendors.length === 0"
			:name="t('maintenance_tracker', 'No vendors yet')"
			:description="t('maintenance_tracker', 'Add the shops and service providers you use, then pick them when you add parts or costs.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiStorefrontOutline" :size="64" />
			</template>
			<template v-if="can.manageCatalog" #action>
				<NcButton variant="primary" @click="openForm(null)">
					{{ t('maintenance_tracker', 'Add vendor') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<template v-else>
			<div class="filters">
				<NcTextField v-model="query" type="search" :label="t('maintenance_tracker', 'Search vendors')" />
			</div>
			<ul class="list" :aria-label="t('maintenance_tracker', 'Vendors')">
				<template v-for="vendor in visible" :key="vendor.uuid">
					<NcListItem
						:name="vendor.name"
						:active="expanded === vendor.uuid"
						:forceDisplayActions="true"
						@click="expanded = expanded === vendor.uuid ? null : vendor.uuid">
						<template #subname>
							{{ [vendor.contactName, vendor.phone, vendor.website ? host(vendor.website) : null].filter(Boolean).join(' · ') }}
						</template>
						<template v-if="can.manageCatalog" #actions>
							<NcActionButton closeAfterClick @click="openForm(vendor)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiPencilOutline" />
								</template>
								{{ t('maintenance_tracker', 'Edit') }}
							</NcActionButton>
							<NcActionButton closeAfterClick @click="deleting = vendor">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDeleteOutline" />
								</template>
								{{ t('maintenance_tracker', 'Delete') }}
							</NcActionButton>
						</template>
					</NcListItem>
					<li v-if="expanded === vendor.uuid" class="detail">
						<dl class="facts">
							<template v-if="vendor.website && safeHref(vendor.website)">
								<dt>{{ t('maintenance_tracker', 'Website') }}</dt>
								<dd>
									<a :href="safeHref(vendor.website)" target="_blank" rel="noopener noreferrer">{{ vendor.website }}</a>
								</dd>
							</template>
							<template v-if="vendor.address">
								<dt>{{ t('maintenance_tracker', 'Address') }}</dt>
								<dd class="pre">
									{{ vendor.address }}
								</dd>
							</template>
							<template v-if="vendor.phone">
								<dt>{{ t('maintenance_tracker', 'Phone') }}</dt>
								<dd><a :href="`tel:${vendor.phone.replace(/[^+\d]/g, '')}`">{{ vendor.phone }}</a></dd>
							</template>
							<template v-if="vendor.email">
								<dt>{{ t('maintenance_tracker', 'Email') }}</dt>
								<dd><a :href="`mailto:${vendor.email}`">{{ vendor.email }}</a></dd>
							</template>
							<template v-if="vendor.contactName">
								<dt>{{ t('maintenance_tracker', 'Contact person') }}</dt>
								<dd>{{ vendor.contactName }}</dd>
							</template>
						</dl>
						<p v-if="vendor.notes" class="pre">
							{{ vendor.notes }}
						</p>
						<h3 v-if="websitesEnabled">
							{{ t('maintenance_tracker', 'More links') }}
						</h3>
						<OutsideLinks subjectType="vendor" :subjectUuid="vendor.uuid" />
					</li>
				</template>
			</ul>
		</template>

		<VendorFormDialog
			v-model:open="formOpen"
			:vendor="editing"
			:websitesEnabled="websitesEnabled"
			@saved="saved" />
		<ConfirmDialog
			:open="deleting !== null"
			:name="t('maintenance_tracker', 'Delete vendor?')"
			:message="t('maintenance_tracker', 'Delete “{name}”? Parts and costs that refer to it keep the name.', { name: deleting?.name ?? '' })"
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

.detail {
	margin: 0 8px 12px 52px;
	padding: 8px 12px;
	border-inline-start: 3px solid var(--color-primary-element);
}

.detail h3 {
	margin: 8px 0 4px;
	font-size: 15px;
}

.facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 0;
}

.facts dt {
	color: var(--color-text-maxcontrast);
}

.facts dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.facts a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.pre {
	white-space: pre-wrap;
}

@media (max-width: 600px) {
	.detail {
		margin-inline-start: 8px;
	}
}
</style>
