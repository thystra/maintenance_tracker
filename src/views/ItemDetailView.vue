<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// One item: an overview of what is due, then tabs for tasks, history,
// readings, costs, files and the less common setup (components, specs,
// profiles, relationships).
import type { Activity, Asset, Component, MaintenanceForecastItem, Meter, WorkDefinition, WorkGroup } from '../types.ts'

import { mdiArrowLeft, mdiDeleteOutline, mdiPencilOutline, mdiWrenchOutline } from '@mdi/js'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import CostsPanel from '../components/CostsPanel.vue'
import EvidencePanel from '../components/EvidencePanel.vue'
import GroupBreadcrumbs from '../components/GroupBreadcrumbs.vue'
import HistoryPanel from '../components/HistoryPanel.vue'
import ItemFormDialog from '../components/ItemFormDialog.vue'
import LogMaintenanceDialog from '../components/LogMaintenanceDialog.vue'
import PageHeader from '../components/PageHeader.vue'
import ReadingsPanel from '../components/ReadingsPanel.vue'
import SetupPanel from '../components/SetupPanel.vue'
import StateBadge from '../components/StateBadge.vue'
import TasksPanel from '../components/TasksPanel.vue'
import { dueText, errorMessage, forecastLabel, formatDate, scheduleLabel } from '../format.ts'
import { t } from '../l10n.ts'
import { deleteAsset, getActivities, getAsset, getComponents, getMaintenanceForecast, getMeters, getWorkDefinitions, getWorkGroups } from '../services/api.ts'
import { can, categoryName, loadAssets, loadGroups, state } from '../store.ts'

const props = defineProps<{ uuid: string, tab?: string }>()
const router = useRouter()

const asset = ref<Asset | null>(null)
const forecast = ref<MaintenanceForecastItem[]>([])
const definitions = ref<WorkDefinition[]>([])
const meters = ref<Meter[]>([])
const components = ref<Component[]>([])
const groups = ref<WorkGroup[]>([])
const activities = ref<Activity[]>([])
const loading = ref(true)
const error = ref('')
const notFound = ref(false)
const editOpen = ref(false)
const logOpen = ref(false)
const logDefinition = ref<string | null>(null)
const confirmDelete = ref(false)
const busy = ref(false)

const tabs = computed(() => [
	{ id: 'overview', label: t('maintenance_tracker', 'Overview') },
	{ id: 'tasks', label: t('maintenance_tracker', 'Tasks') },
	{ id: 'history', label: t('maintenance_tracker', 'History') },
	{ id: 'readings', label: t('maintenance_tracker', 'Readings') },
	{ id: 'costs', label: t('maintenance_tracker', 'Costs') },
	{ id: 'files', label: t('maintenance_tracker', 'Files') },
	{ id: 'setup', label: t('maintenance_tracker', 'Setup') },
])
const activeTab = computed(() => tabs.value.some((tab) => tab.id === props.tab) ? props.tab as string : 'overview')
const scheduled = computed(() => forecast.value.filter((item) => item.forecast.state !== 'inactive' && item.forecast.state !== 'unscheduled'))
const facts = computed(() => {
	const value = asset.value
	if (value === null) {
		return []
	}
	return [
		{ label: t('maintenance_tracker', 'Category'), value: categoryName(value.category) },
		{ label: t('maintenance_tracker', 'Make or manufacturer'), value: value.manufacturer },
		{ label: t('maintenance_tracker', 'Model'), value: value.model },
		{ label: t('maintenance_tracker', 'Year'), value: value.modelYear === null ? null : String(value.modelYear) },
		{ label: t('maintenance_tracker', 'Serial number or VIN'), value: value.serialNumber },
		{ label: t('maintenance_tracker', 'Profile'), value: value.profile ? `${value.profile.key} ${value.profile.version}` : null },
	].filter((fact) => fact.value !== null && fact.value !== '')
})

function trigger(item: MaintenanceForecastItem): string {
	const rule = item.rules.find((candidate) => candidate.position === item.triggerRulePosition) ?? item.rules[0]
	if (item.forecast.state === 'setup_required' || item.state === 'baseline_required') {
		return t('maintenance_tracker', 'Log it once to start the schedule ({schedule}).', { schedule: scheduleLabel(item.definition.schedule) })
	}
	if (rule?.dueOn) {
		return dueText(rule.dueOn, rule.remainingDays)
	}
	if (rule?.meter && rule.current) {
		return t('maintenance_tracker', '{meter}: {value} {unit} now · {schedule}', { meter: rule.meter.name, value: rule.current.originalValue, unit: rule.current.originalUnit, schedule: scheduleLabel(item.definition.schedule) })
	}
	return scheduleLabel(item.definition.schedule)
}

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	notFound.value = false
	try {
		asset.value = await getAsset(props.uuid)
		state.pageTitle = asset.value.name
		const [forecastList, definitionList, meterList, componentList, groupList, activityList] = await Promise.all([
			getMaintenanceForecast(props.uuid),
			getWorkDefinitions(props.uuid),
			getMeters(props.uuid),
			getComponents(props.uuid),
			getWorkGroups(props.uuid),
			getActivities(props.uuid),
		])
		forecast.value = forecastList.items
		definitions.value = definitionList.items
		meters.value = meterList.items
		components.value = componentList.items
		groups.value = groupList.items
		activities.value = activityList.items
	} catch (reason) {
		if ((reason as { response?: { status?: number } })?.response?.status === 404) {
			notFound.value = true
		} else {
			error.value = errorMessage(reason)
		}
	} finally {
		loading.value = false
	}
}

function selectTab(id: string): void {
	void router.replace({ name: 'item', params: { uuid: props.uuid, tab: id === 'overview' ? undefined : id } })
}

/**
 * Arrow-key navigation between tabs (WAI-ARIA tabs pattern).
 *
 * @param event Keyboard event on the tab list.
 */
function onTabKey(event: KeyboardEvent): void {
	const index = tabs.value.findIndex((tab) => tab.id === activeTab.value)
	const next = event.key === 'ArrowRight' ? index + 1 : event.key === 'ArrowLeft' ? index - 1 : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.value.length - 1 : null
	if (next === null) {
		return
	}
	event.preventDefault()
	const tab = tabs.value[(next + tabs.value.length) % tabs.value.length]
	if (tab) {
		selectTab(tab.id)
		window.setTimeout(() => document.getElementById(`item-tab-${tab.id}`)?.focus(), 30)
	}
}

function log(definitionUuid: string | null = null): void {
	logDefinition.value = definitionUuid
	logOpen.value = true
}

async function saved(): Promise<void> {
	await Promise.all([load(), loadAssets(), loadGroups()])
}

async function remove(): Promise<void> {
	if (asset.value === null) {
		return
	}
	busy.value = true
	try {
		await deleteAsset(asset.value.uuid, asset.value.revision)
		confirmDelete.value = false
		await loadAssets()
		await router.push({ name: 'items' })
	} catch (reason) {
		confirmDelete.value = false
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

watch(() => props.uuid, load)
onMounted(load)
</script>

<template>
	<div class="item-detail">
		<GroupBreadcrumbs v-if="asset && state.groupOf[asset.uuid]" :groupUuid="state.groupOf[asset.uuid]" :current="asset.name" />
		<NcButton
			v-else
			variant="tertiary"
			:to="{ name: 'items' }"
			class="back">
			<template #icon>
				<NcIconSvgWrapper :path="mdiArrowLeft" />
			</template>
			{{ t('maintenance_tracker', 'All items') }}
		</NcButton>

		<NcEmptyContent v-if="notFound" :name="t('maintenance_tracker', 'Item not found')" :description="t('maintenance_tracker', 'It may have been deleted, or it belongs to another workspace.')">
			<template #action>
				<NcButton :to="{ name: 'items' }">
					{{ t('maintenance_tracker', 'Back to items') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<NcLoadingIcon v-else-if="loading && asset === null" :size="44" :name="t('maintenance_tracker', 'Loading')" />

		<template v-else-if="asset">
			<PageHeader :title="asset.name" :subtitle="[categoryName(asset.category), asset.manufacturer, asset.model].filter(Boolean).join(' · ')">
				<template #actions>
					<NcButton v-if="can.log" variant="primary" @click="log()">
						<template #icon>
							<NcIconSvgWrapper :path="mdiWrenchOutline" />
						</template>
						{{ t('maintenance_tracker', 'Log maintenance') }}
					</NcButton>
					<NcActions v-if="can.manageInventory" :aria-label="t('maintenance_tracker', 'More actions for {name}', { name: asset.name })">
						<NcActionButton closeAfterClick @click="editOpen = true">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPencilOutline" />
							</template>
							{{ t('maintenance_tracker', 'Edit item') }}
						</NcActionButton>
						<NcActionButton closeAfterClick @click="confirmDelete = true">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDeleteOutline" />
							</template>
							{{ t('maintenance_tracker', 'Delete item') }}
						</NcActionButton>
					</NcActions>
				</template>
			</PageHeader>

			<NcNoteCard v-if="error" type="error">
				<p role="alert">
					{{ error }}
				</p>
			</NcNoteCard>

			<div
				class="tabs"
				role="tablist"
				:aria-label="t('maintenance_tracker', 'Item sections')"
				@keydown="onTabKey">
				<button
					v-for="entry in tabs"
					:id="`item-tab-${entry.id}`"
					:key="entry.id"
					type="button"
					role="tab"
					class="tabs__tab"
					:class="{ 'tabs__tab--active': entry.id === activeTab }"
					:aria-selected="entry.id === activeTab ? 'true' : 'false'"
					:aria-controls="`item-panel-${entry.id}`"
					:tabindex="entry.id === activeTab ? 0 : -1"
					@click="selectTab(entry.id)">
					{{ entry.label }}
				</button>
			</div>

			<section
				:id="`item-panel-${activeTab}`"
				class="tab-panel"
				role="tabpanel"
				:aria-labelledby="`item-tab-${activeTab}`">
				<template v-if="activeTab === 'overview'">
					<div class="overview">
						<section class="card" aria-labelledby="due-heading">
							<h3 id="due-heading">
								{{ t('maintenance_tracker', 'Maintenance status') }}
							</h3>
							<p v-if="scheduled.length === 0" class="muted">
								{{ t('maintenance_tracker', 'No scheduled tasks yet.') }}
								<NcButton v-if="can.manageInventory" variant="tertiary" @click="selectTab('tasks')">
									{{ t('maintenance_tracker', 'Add a task') }}
								</NcButton>
							</p>
							<ul v-else class="status-list">
								<li v-for="item in scheduled" :key="item.definition.uuid">
									<div class="status-list__text">
										<strong>{{ item.definition.title }}</strong>
										<span class="muted">{{ trigger(item) }}</span>
										<span v-if="item.lastPerformedAt" class="muted">{{ t('maintenance_tracker', 'Last done {date}', { date: formatDate(item.lastPerformedAt) }) }}</span>
									</div>
									<StateBadge :state="item.forecast.state" :label="forecastLabel(item.forecast.state)" />
									<NcButton
										v-if="can.log"
										variant="tertiary"
										:aria-label="t('maintenance_tracker', 'Log {task}', { task: item.definition.title })"
										@click="log(item.definition.uuid)">
										{{ t('maintenance_tracker', 'Log') }}
									</NcButton>
								</li>
							</ul>
						</section>
						<section class="card" aria-labelledby="facts-heading">
							<h3 id="facts-heading">
								{{ t('maintenance_tracker', 'Details') }}
							</h3>
							<dl class="facts">
								<template v-for="fact in facts" :key="fact.label">
									<dt>{{ fact.label }}</dt>
									<dd>{{ fact.value }}</dd>
								</template>
							</dl>
							<p v-if="asset.notes" class="notes">
								{{ asset.notes }}
							</p>
						</section>
						<section class="card" aria-labelledby="latest-heading">
							<h3 id="latest-heading">
								{{ t('maintenance_tracker', 'Latest work') }}
							</h3>
							<p v-if="activities.length === 0" class="muted">
								{{ t('maintenance_tracker', 'Nothing logged yet.') }}
							</p>
							<ul v-else class="latest">
								<li v-for="activity in activities.slice(0, 5)" :key="activity.uuid">
									<span>{{ activity.items.map((item) => item.title).join(', ') }}</span>
									<span class="muted">{{ formatDate(activity.performedAt) }}</span>
								</li>
							</ul>
							<NcButton v-if="activities.length > 0" variant="tertiary" @click="selectTab('history')">
								{{ t('maintenance_tracker', 'Show full history') }}
							</NcButton>
						</section>
					</div>
				</template>
				<TasksPanel
					v-else-if="activeTab === 'tasks'"
					:assetUuid="asset.uuid"
					:definitions="definitions"
					:forecast="forecast"
					:meters="meters"
					:components="components"
					:groups="groups"
					@log="log"
					@changed="load" />
				<HistoryPanel
					v-else-if="activeTab === 'history'"
					:activities="activities"
					@changed="load" />
				<ReadingsPanel
					v-else-if="activeTab === 'readings'"
					:assetUuid="asset.uuid"
					:meters="meters"
					:components="components"
					@changed="load" />
				<CostsPanel v-else-if="activeTab === 'costs'" :assetUuid="asset.uuid" />
				<EvidencePanel v-else-if="activeTab === 'files'" targetType="asset" :targetUuid="asset.uuid" />
				<SetupPanel
					v-else-if="activeTab === 'setup'"
					:asset="asset"
					:components="components"
					:groups="groups"
					@changed="load" />
			</section>

			<ItemFormDialog v-model:open="editOpen" :asset="asset" @saved="saved" />
			<LogMaintenanceDialog
				v-model:open="logOpen"
				:assetUuid="asset.uuid"
				:definitionUuid="logDefinition"
				@saved="load" />
			<ConfirmDialog
				v-model:open="confirmDelete"
				:name="t('maintenance_tracker', 'Delete item?')"
				:message="t('maintenance_tracker', 'Delete “{name}”? It disappears from lists, reminders and the dashboard. Its history stays in exports made earlier and in the audit trail; attached files stay in Files.', { name: asset.name })"
				:busy="busy"
				@confirm="remove" />
		</template>
	</div>
</template>

<style scoped>
.back {
	margin: 0 0 8px -8px;
}

.tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.tabs__tab {
	margin: 0 0 -1px;
	padding: 8px 14px;
	border: 0;
	border-bottom: 3px solid transparent;
	border-radius: var(--border-radius) var(--border-radius) 0 0;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	cursor: pointer;
}

.tabs__tab:hover {
	background: var(--color-background-hover);
}

.tabs__tab--active {
	border-bottom-color: var(--color-primary-element);
	color: var(--color-main-text);
	font-weight: bold;
}

.overview {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 16px;
}

.card {
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.card h3 {
	margin: 0 0 8px;
	font-size: 17px;
}

.status-list,
.latest {
	display: grid;
	gap: 10px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.status-list li {
	display: flex;
	align-items: center;
	gap: 8px;
}

.status-list__text {
	display: grid;
	flex: 1;
	min-width: 0;
}

.latest li {
	display: flex;
	justify-content: space-between;
	gap: 8px;
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

.notes {
	white-space: pre-wrap;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
