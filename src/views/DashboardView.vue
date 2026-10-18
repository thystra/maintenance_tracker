<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Landing page: what needs attention now, what is coming up, and the quick
// actions people use most (log maintenance, add an item).
import type { FuelSummaryItem } from '../fuelTypes.ts'
import type { AuditEntry, WorkspaceForecast, WorkspaceForecastItem } from '../types.ts'

import { mdiCalendarClock, mdiFolderOutline, mdiGasStationOutline, mdiPlus, mdiWrenchOutline } from '@mdi/js'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ItemFormDialog from '../components/ItemFormDialog.vue'
import LogMaintenanceDialog from '../components/LogMaintenanceDialog.vue'
import OwnershipBanner from '../components/OwnershipBanner.vue'
import PageHeader from '../components/PageHeader.vue'
import StateBadge from '../components/StateBadge.vue'
import { assetClassLabel, dueText, errorMessage, forecastLabel, formatDate } from '../format.ts'
import { formatEconomy } from '../fuelFormat.ts'
import { n, t } from '../l10n.ts'
import { getAudit, getWorkspaceForecast } from '../services/api.ts'
import { getFuelSummary } from '../services/fuelApi.ts'
import { activeWorkspace, assetName, can, childGroups, groupSubtree, loadAssets, loadGroups, loadWorkspaces, state } from '../store.ts'

const router = useRouter()
const forecast = ref<WorkspaceForecast | null>(null)
const audit = ref<AuditEntry[]>([])
const fuel = ref<FuelSummaryItem[]>([])
const loading = ref(true)
const error = ref('')
const addOpen = ref(false)
const logOpen = ref(false)
const logAsset = ref<string | null>(null)
const logDefinition = ref<string | null>(null)

const attention = computed(() => (forecast.value?.items ?? []).filter((item) => ['overdue', 'due', 'due_soon'].includes(item.state)))
const upcoming = computed(() => (forecast.value?.items ?? []).filter((item) => item.state === 'not_due' && item.dueOn !== null && (item.remainingDays ?? 999) <= 60).slice(0, 8))
const setup = computed(() => (forecast.value?.items ?? []).filter((item) => item.state === 'setup_required' || item.state === 'blocked'))
const counts = computed(() => ({
	overdue: attention.value.filter((item) => item.state === 'overdue').length,
	due: attention.value.filter((item) => item.state !== 'overdue').length,
}))
const byClass = computed(() => {
	const classes = new Map<string, number>()
	for (const asset of state.assets) {
		classes.set(asset.assetClass, (classes.get(asset.assetClass) ?? 0) + 1)
	}
	return [...classes.entries()].sort((left, right) => right[1] - left[1]).slice(0, 4)
		.map(([assetClass, count]) => `${assetClassLabel(assetClass as never)} ${count}`).join(' · ')
})

/** Per top-level group: items in it (with subgroups) and their overdue / due tasks. */
const byGroup = computed(() => {
	if (state.groups.length === 0) {
		return []
	}
	const summarize = (inGroup: (assetUuid: string) => boolean) => {
		const items = state.assets.filter((asset) => inGroup(asset.uuid)).length
		const tasks = attention.value.filter((item) => inGroup(item.asset.uuid))
		return { items, overdue: tasks.filter((item) => item.state === 'overdue').length, due: tasks.filter((item) => item.state !== 'overdue').length }
	}
	const rows = childGroups(null).map((group) => {
		const subtree = groupSubtree(group.uuid)
		return { key: group.uuid, name: group.name, query: group.uuid, ...summarize((uuid) => subtree.has(state.groupOf[uuid] ?? '')) }
	})
	const ungrouped = summarize((uuid) => state.groupOf[uuid] === undefined)
	if (ungrouped.items > 0) {
		rows.push({ key: 'none', name: t('maintenance_tracker', 'Ungrouped'), query: 'none', ...ungrouped })
	}
	return rows
})

function groupDetail(row: { items: number, overdue: number, due: number }): string {
	const parts = [n('maintenance_tracker', '%n item', '%n items', row.items)]
	if (row.overdue > 0) {
		parts.push(n('maintenance_tracker', '%n overdue', '%n overdue', row.overdue))
	}
	if (row.due > 0) {
		parts.push(n('maintenance_tracker', '%n due soon', '%n due soon', row.due))
	}
	return parts.join(' · ')
}

function detail(item: WorkspaceForecastItem): string {
	if (item.dueOn) {
		return dueText(item.dueOn, item.remainingDays)
	}
	if (item.meter) {
		return t('maintenance_tracker', 'Due by {meter} reading', { meter: item.meter.name })
	}
	return item.reason ?? ''
}

const auditLabels: Record<string, () => string> = {
	'asset.created': () => t('maintenance_tracker', 'Item added'),
	'asset.updated': () => t('maintenance_tracker', 'Item changed'),
	'asset.archived': () => t('maintenance_tracker', 'Item deleted'),
	'activity.created': () => t('maintenance_tracker', 'Maintenance logged'),
	'activity.updated': () => t('maintenance_tracker', 'Maintenance record changed'),
	'work_definition.created': () => t('maintenance_tracker', 'Task added'),
	'cost.created': () => t('maintenance_tracker', 'Cost added'),
	'evidence.created': () => t('maintenance_tracker', 'File attached'),
	'part.created': () => t('maintenance_tracker', 'Part added'),
	'vendor.created': () => t('maintenance_tracker', 'Vendor added'),
	'workspace.member.added': () => t('maintenance_tracker', 'Member added'),
	'workspace.created': () => t('maintenance_tracker', 'Workspace created'),
	'workspace.renamed': () => t('maintenance_tracker', 'Workspace renamed'),
	'item_group.created': () => t('maintenance_tracker', 'Group created'),
	'asset.group_changed': () => t('maintenance_tracker', 'Item group changed'),
	'fuel.created': () => t('maintenance_tracker', 'Fill-up added'),
}
const recent = computed(() => audit.value.filter((entry) => auditLabels[entry.eventType] !== undefined).slice(0, 8))

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		forecast.value = await getWorkspaceForecast()
	} catch (reason) {
		error.value = errorMessage(reason)
	}
	try {
		fuel.value = (await getFuelSummary()).items
	} catch {
		fuel.value = []
	}
	if (can.readAudit) {
		try {
			audit.value = await getAudit(40)
		} catch {
			audit.value = []
		}
	}
	loading.value = false
}

function log(assetUuid: string | null = null, definitionUuid: string | null = null): void {
	logAsset.value = assetUuid
	logDefinition.value = definitionUuid
	logOpen.value = true
}

async function added(asset: { uuid: string }): Promise<void> {
	await Promise.all([loadAssets(), loadGroups()])
	await router.push({ name: 'item', params: { uuid: asset.uuid } })
}

async function ownershipChanged(): Promise<void> {
	await loadWorkspaces()
}

onMounted(load)
</script>

<template>
	<div class="dashboard">
		<OwnershipBanner @changed="ownershipChanged" />
		<PageHeader :title="t('maintenance_tracker', 'Dashboard')" :subtitle="activeWorkspace?.name">
			<template v-if="state.assets.length > 0" #actions>
				<NcButton v-if="can.log" variant="primary" @click="log()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiWrenchOutline" />
					</template>
					{{ t('maintenance_tracker', 'Log maintenance') }}
				</NcButton>
				<NcButton v-if="can.manageInventory" @click="addOpen = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('maintenance_tracker', 'Add item') }}
				</NcButton>
			</template>
		</PageHeader>

		<NcEmptyContent
			v-if="state.assetsLoaded && state.assets.length === 0"
			class="dashboard__empty"
			:name="t('maintenance_tracker', 'Nothing tracked yet')"
			:description="can.manageInventory
				? t('maintenance_tracker', 'Add the first thing you maintain: a car, the furnace, a lawn mower, a medical device. Then add its maintenance tasks, or apply a ready-made profile.')
				: t('maintenance_tracker', 'There are no items in this workspace yet. A workspace owner or manager can add them.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiWrenchOutline" :size="64" />
			</template>
			<template v-if="can.manageInventory" #action>
				<NcButton variant="primary" @click="addOpen = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('maintenance_tracker', 'Add your first item') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<NcNoteCard v-if="error" type="error" role="alert">
				{{ error }}
			</NcNoteCard>
			<section class="summary" :aria-label="t('maintenance_tracker', 'Summary')">
				<RouterLink class="summary__card" :to="{ name: 'items' }">
					<span class="summary__value">{{ state.assets.length }}</span>
					<span class="summary__label">{{ n('maintenance_tracker', 'item tracked', 'items tracked', state.assets.length) }}</span>
					<span class="summary__hint">{{ byClass }}</span>
				</RouterLink>
				<div class="summary__card" :class="{ 'summary__card--error': counts.overdue > 0 }">
					<span class="summary__value">{{ counts.overdue }}</span>
					<span class="summary__label">{{ n('maintenance_tracker', 'task overdue', 'tasks overdue', counts.overdue) }}</span>
				</div>
				<div class="summary__card" :class="{ 'summary__card--warning': counts.due > 0 }">
					<span class="summary__value">{{ counts.due }}</span>
					<span class="summary__label">{{ n('maintenance_tracker', 'task due or due soon', 'tasks due or due soon', counts.due) }}</span>
				</div>
			</section>

			<NcLoadingIcon v-if="loading" :size="32" :name="t('maintenance_tracker', 'Loading')" />
			<div v-else class="dashboard__columns">
				<section class="card" aria-labelledby="attention-heading">
					<h3 id="attention-heading">
						{{ t('maintenance_tracker', 'Needs attention') }}
					</h3>
					<p v-if="attention.length === 0" class="muted">
						{{ t('maintenance_tracker', 'Nothing is due. Nice.') }}
					</p>
					<ul v-else class="list">
						<NcListItem
							v-for="item in attention"
							:key="`${item.asset.uuid}-${item.definition.uuid}`"
							:name="item.definition.title"
							:to="{ name: 'item', params: { uuid: item.asset.uuid } }"
							compact>
							<template #subname>
								{{ item.asset.name }} · {{ detail(item) }}
							</template>
							<template #details>
								<StateBadge :state="item.state" :label="forecastLabel(item.state)" />
							</template>
							<template v-if="can.log" #extra-actions>
								<NcButton variant="tertiary" :aria-label="t('maintenance_tracker', 'Log {task} for {item}', { task: item.definition.title, item: item.asset.name })" @click.stop.prevent="log(item.asset.uuid, item.definition.uuid)">
									{{ t('maintenance_tracker', 'Log') }}
								</NcButton>
							</template>
						</NcListItem>
					</ul>
					<p v-if="setup.length > 0" class="muted">
						{{ n('maintenance_tracker', '%n task needs a first record or a meter reading before it can be scheduled.', '%n tasks need a first record or a meter reading before they can be scheduled.', setup.length) }}
					</p>
					<p v-if="forecast?.truncated" class="muted">
						{{ n('maintenance_tracker', 'Only the first %n item is checked here.', 'Only the first %n items are checked here.', forecast.scannedAssets) }}
					</p>
				</section>

				<section class="card" aria-labelledby="upcoming-heading">
					<h3 id="upcoming-heading">
						<NcIconSvgWrapper :path="mdiCalendarClock" :size="20" inline />
						{{ t('maintenance_tracker', 'Coming up') }}
					</h3>
					<p v-if="upcoming.length === 0" class="muted">
						{{ t('maintenance_tracker', 'No dated tasks in the next 60 days.') }}
					</p>
					<ul v-else class="list">
						<NcListItem
							v-for="item in upcoming"
							:key="`${item.asset.uuid}-${item.definition.uuid}`"
							:name="item.definition.title"
							:to="{ name: 'item', params: { uuid: item.asset.uuid } }"
							compact>
							<template #subname>
								{{ item.asset.name }} · {{ detail(item) }}
							</template>
						</NcListItem>
					</ul>
				</section>

				<section v-if="byGroup.length > 0" class="card" aria-labelledby="groups-heading">
					<h3 id="groups-heading">
						<NcIconSvgWrapper :path="mdiFolderOutline" :size="20" inline />
						{{ t('maintenance_tracker', 'By group') }}
					</h3>
					<ul class="list">
						<NcListItem
							v-for="row in byGroup"
							:key="row.key"
							:name="row.name"
							:to="{ name: 'items', query: { group: row.query } }"
							compact>
							<template #subname>
								{{ groupDetail(row) }}
							</template>
							<template v-if="row.overdue > 0 || row.due > 0" #details>
								<StateBadge :state="row.overdue > 0 ? 'overdue' : 'due_soon'" :label="row.overdue > 0 ? forecastLabel('overdue') : forecastLabel('due_soon')" />
							</template>
						</NcListItem>
					</ul>
				</section>

				<section v-if="fuel.length > 0" class="card" aria-labelledby="fuel-heading">
					<h3 id="fuel-heading">
						<NcIconSvgWrapper :path="mdiGasStationOutline" :size="20" inline />
						{{ t('maintenance_tracker', 'Fuel economy') }}
					</h3>
					<ul class="list">
						<NcListItem
							v-for="row in fuel"
							:key="row.assetUuid"
							:name="row.name"
							:to="{ name: 'item', params: { uuid: row.assetUuid, tab: 'fuel' } }"
							compact>
							<template #subname>
								{{ row.economy90d === null
									? t('maintenance_tracker', 'Last fill-up {date}', { date: formatDate(row.lastFilledAt) })
									: t('maintenance_tracker', '{economy} over 90 days · last fill-up {date}', { economy: formatEconomy(row.economy90d, row.economyUnit), date: formatDate(row.lastFilledAt) }) }}
							</template>
							<template v-if="row.lastEconomy !== null" #details>
								<span :title="t('maintenance_tracker', 'Last measured tank')">{{ formatEconomy(row.lastEconomy, row.economyUnit) }}</span>
							</template>
						</NcListItem>
					</ul>
				</section>

				<section v-if="can.readAudit" class="card" aria-labelledby="recent-heading">
					<h3 id="recent-heading">
						{{ t('maintenance_tracker', 'Recent activity') }}
					</h3>
					<p v-if="recent.length === 0" class="muted">
						{{ t('maintenance_tracker', 'No recent changes.') }}
					</p>
					<ul v-else class="recent">
						<li v-for="entry in recent" :key="entry.id">
							<span>{{ auditLabels[entry.eventType]?.() }}<template v-if="entry.subjectType === 'asset' && assetName(entry.subjectId)">: {{ assetName(entry.subjectId) }}</template></span>
							<span class="muted">{{ t('maintenance_tracker', '{user}, {date}', { user: entry.actorUid, date: formatDate(entry.createdAt) }) }}</span>
						</li>
					</ul>
				</section>

				<section class="card" aria-labelledby="links-heading">
					<h3 id="links-heading">
						{{ t('maintenance_tracker', 'Go to') }}
					</h3>
					<div class="quick-links">
						<NcButton :to="{ name: 'items' }">
							{{ t('maintenance_tracker', 'All items') }}
						</NcButton>
						<NcButton :to="{ name: 'parts' }">
							{{ t('maintenance_tracker', 'Parts') }}
						</NcButton>
						<NcButton :to="{ name: 'vendors' }">
							{{ t('maintenance_tracker', 'Vendors') }}
						</NcButton>
						<NcButton :to="{ name: 'profiles' }">
							{{ t('maintenance_tracker', 'Profiles') }}
						</NcButton>
						<NcButton :to="{ name: 'settings', params: { section: 'reminders' } }">
							{{ t('maintenance_tracker', 'Reminders and calendar') }}
						</NcButton>
					</div>
				</section>
			</div>
		</template>

		<ItemFormDialog v-model:open="addOpen" @saved="added" />
		<LogMaintenanceDialog
			v-model:open="logOpen"
			:assetUuid="logAsset"
			:definitionUuid="logDefinition"
			@saved="load" />
	</div>
</template>

<style scoped>
.summary {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
	margin-bottom: 16px;
}

.summary__card {
	display: grid;
	gap: 2px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

a.summary__card:hover,
a.summary__card:focus-visible {
	background: var(--color-background-hover);
}

.summary__card--error {
	border-color: var(--color-error);
}

.summary__card--warning {
	border-color: var(--color-warning);
}

.summary__value {
	font-size: 28px;
	font-weight: bold;
	line-height: 1.2;
}

.summary__hint,
.muted {
	color: var(--color-text-maxcontrast);
}

.dashboard__columns {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 16px;
}

.card {
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.card h3 {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0 0 8px;
	font-size: 17px;
}

.list {
	margin: 0 -8px;
	padding: 0;
	list-style: none;
}

.recent {
	display: grid;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.recent li {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: 8px;
}

.quick-links {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.dashboard__empty {
	margin-top: 10vh;
}
</style>
