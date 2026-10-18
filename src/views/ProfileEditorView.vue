<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Profile builder: edits one workspace profile (or a new one) section by
// section. The server validates every save with the same rules as bundled
// profiles; its message is shown as is.
import type { RowField } from '../components/profiles/ProfileRows.vue'
import type { SelectOption } from '../components/SelectField.vue'
import type { ProfileComponent, ProfileDocumentV2, ProfileFuelRegime, ProfileMeter, ProfilePart, ProfileSpecification, ProfileWorkDefinition, ProfileWorkGroup } from '../profileTypes.ts'

import { mdiArrowLeft, mdiDownload } from '@mdi/js'
import { computed, nextTick, onMounted, ref, toRaw, useTemplateRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import PageHeader from '../components/PageHeader.vue'
import ProfileRows from '../components/profiles/ProfileRows.vue'
import ScheduleEditor from '../components/profiles/ScheduleEditor.vue'
import SelectField from '../components/SelectField.vue'
import { errorMessage, meterUnits } from '../format.ts'
import { distanceUnitLabel, ECONOMY_UNITS, economyUnitLabel, FUEL_TYPES, fuelTypeLabel, volumeUnitLabel } from '../fuelFormat.ts'
import { t } from '../l10n.ts'
import { blankProfile, downloadProfile, editable, forSave, renameReferences, slugKey, uniqueSlug } from '../profileEditor.ts'
import { getProfiles } from '../services/api.ts'
import { createWorkspaceProfile, getWorkspaceProfile, updateWorkspaceProfile } from '../services/profileApi.ts'
import { activeWorkspace, can, state } from '../store.ts'

const props = defineProps<{ uuid: string }>()
const route = useRoute()
const router = useRouter()

const draft = ref<ProfileDocumentV2 | null>(null)
const revision = ref(0)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const status = ref('')
const errorCard = useTemplateRef<HTMLElement>('errorCard')
// One UUID per new profile, so a retried save after a lost response is idempotent.
const newUuid = crypto.randomUUID()
const isNew = computed(() => props.uuid === 'new')

// Keys of rows added here follow their name until the key is edited by hand.
const autoKeyed = new WeakSet<object>()

const sections = computed(() => [
	{ id: 'profile-details', label: t('maintenance_tracker', 'Details') },
	{ id: 'profile-meters', label: t('maintenance_tracker', 'Meters ({count})', { count: draft.value?.meters.length ?? 0 }) },
	{ id: 'profile-components', label: t('maintenance_tracker', 'Components ({count})', { count: draft.value?.components.length ?? 0 }) },
	{ id: 'profile-specs', label: t('maintenance_tracker', 'Specs ({count})', { count: draft.value?.specifications?.length ?? 0 }) },
	{ id: 'profile-parts', label: t('maintenance_tracker', 'Parts ({count})', { count: draft.value?.parts.length ?? 0 }) },
	{ id: 'profile-groups', label: t('maintenance_tracker', 'Task groups ({count})', { count: draft.value?.workGroups.length ?? 0 }) },
	{ id: 'profile-tasks', label: t('maintenance_tracker', 'Tasks ({count})', { count: draft.value?.workDefinitions.length ?? 0 }) },
	{ id: 'profile-fuel', label: t('maintenance_tracker', 'Fuel ({count})', { count: draft.value?.fuel?.regimes.length ?? 0 }) },
])

function jump(id: string): void {
	const target = document.getElementById(id)
	target?.scrollIntoView({ behavior: 'smooth', block: 'start' })
	target?.querySelector<HTMLElement>('h3')?.focus()
}

const categoryOptions = computed<SelectOption[]>(() => {
	const options = state.categories.map((category) => ({ value: category.key, label: category.name }))
	const current = draft.value?.category
	return current && !options.some((option) => option.value === current) ? [...options, { value: current, label: current }] : options
})
const componentOptions = computed<SelectOption[]>(() => (draft.value?.components ?? []).filter((c) => c.key !== '').map((c) => ({ value: c.key, label: c.name || c.key })))
const singleComponentOptions = computed<SelectOption[]>(() => (draft.value?.components ?? []).filter((c) => c.key !== '' && Number(c.quantity) === 1).map((c) => ({ value: c.key, label: c.name || c.key })))
const partOptions = computed<SelectOption[]>(() => (draft.value?.parts ?? []).filter((p) => p.key !== '').map((p) => ({ value: p.key, label: [p.manufacturer, p.partNumber].filter(Boolean).join(' ') || p.key })))
const groupOptions = computed<SelectOption[]>(() => (draft.value?.workGroups ?? []).filter((g) => g.key !== '').map((g) => ({ value: g.key, label: g.name || g.key })))

const keyField = (): RowField => ({ key: 'key', label: t('maintenance_tracker', 'Key'), type: 'text', placeholder: t('maintenance_tracker', 'Made from the name') })
const dimensionOptions: SelectOption[] = [
	{ value: 'distance', label: t('maintenance_tracker', 'Distance') },
	{ value: 'runtime', label: t('maintenance_tracker', 'Running time') },
	{ value: 'usage_count', label: t('maintenance_tracker', 'Uses') },
]
const meterFields = computed<RowField[]>(() => [
	{ key: 'name', label: t('maintenance_tracker', 'Name'), type: 'text' },
	{ key: 'dimension', label: t('maintenance_tracker', 'Measures'), type: 'select', options: dimensionOptions },
	{ key: 'displayUnit', label: t('maintenance_tracker', 'Unit'), type: 'select', options: (row) => meterUnits((row.dimension as ProfileMeter['dimension']) ?? 'distance').map((value) => ({ value, label: value })) },
	keyField(),
	{ key: 'monotonic', label: t('maintenance_tracker', 'Only counts up'), type: 'checkbox' },
])
const componentFields = computed<RowField[]>(() => [
	{ key: 'name', label: t('maintenance_tracker', 'Name'), type: 'text' },
	{ key: 'parentKey', label: t('maintenance_tracker', 'Part of'), type: 'select', clearable: true, options: (row) => componentOptions.value.filter((option) => option.value !== row.key) },
	{ key: 'quantity', label: t('maintenance_tracker', 'Quantity'), type: 'number' },
	keyField(),
	{ key: 'enabledByDefault', label: t('maintenance_tracker', 'Active when set up'), type: 'checkbox' },
	{ key: 'compatiblePartKeys', label: t('maintenance_tracker', 'Compatible parts'), type: 'multiselect', options: partOptions.value },
	{ key: 'notes', label: t('maintenance_tracker', 'Notes'), type: 'textarea' },
])
const specFields = computed<RowField[]>(() => [
	{ key: 'label', label: t('maintenance_tracker', 'Name'), type: 'text' },
	{ key: 'value', label: t('maintenance_tracker', 'Value'), type: 'text' },
	{ key: 'unit', label: t('maintenance_tracker', 'Unit (optional)'), type: 'text' },
	{ key: 'componentKey', label: t('maintenance_tracker', 'Component (optional)'), type: 'select', clearable: true, options: componentOptions.value },
	keyField(),
])
const partFields = computed<RowField[]>(() => [
	{ key: 'manufacturer', label: t('maintenance_tracker', 'Manufacturer'), type: 'text' },
	{ key: 'partNumber', label: t('maintenance_tracker', 'Part number'), type: 'text' },
	keyField(),
	{ key: 'description', label: t('maintenance_tracker', 'Description'), type: 'text', wide: true },
])
const groupFields = computed<RowField[]>(() => [
	{ key: 'name', label: t('maintenance_tracker', 'Name'), type: 'text' },
	{ key: 'sortOrder', label: t('maintenance_tracker', 'Sort order'), type: 'number' },
	keyField(),
	{ key: 'description', label: t('maintenance_tracker', 'Description (optional)'), type: 'textarea' },
])
const kindOptions: SelectOption[] = [
	{ value: 'maintenance', label: t('maintenance_tracker', 'Maintenance') },
	{ value: 'inspection', label: t('maintenance_tracker', 'Inspection') },
	{ value: 'replacement', label: t('maintenance_tracker', 'Replacement') },
	{ value: 'repair', label: t('maintenance_tracker', 'Repair') },
	{ value: 'cleaning', label: t('maintenance_tracker', 'Cleaning') },
	{ value: 'calibration', label: t('maintenance_tracker', 'Calibration') },
	{ value: 'other', label: t('maintenance_tracker', 'Other') },
]
const taskFields = computed<RowField[]>(() => [
	{ key: 'title', label: t('maintenance_tracker', 'Title'), type: 'text' },
	{ key: 'kind', label: t('maintenance_tracker', 'Type'), type: 'select', options: (row) => kindOptions.some((o) => o.value === row.kind) || !row.kind ? kindOptions : [...kindOptions, { value: String(row.kind), label: String(row.kind) }] },
	{ key: 'groupKey', label: t('maintenance_tracker', 'Group'), type: 'select', clearable: true, options: groupOptions.value },
	{ key: 'componentKey', label: t('maintenance_tracker', 'Component'), type: 'select', clearable: true, options: singleComponentOptions.value },
	keyField(),
	{ key: 'enabledByDefault', label: t('maintenance_tracker', 'Active when set up'), type: 'checkbox' },
	{ key: 'compatiblePartKeys', label: t('maintenance_tracker', 'Parts used'), type: 'multiselect', options: partOptions.value },
	{ key: 'instructions', label: t('maintenance_tracker', 'Instructions (optional)'), type: 'textarea' },
])
const regimeFields = computed<RowField[]>(() => [
	{ key: 'name', label: t('maintenance_tracker', 'Name'), type: 'text' },
	keyField(),
])

function created<T extends object>(row: T): T {
	autoKeyed.add(row)
	return row
}
const newMeter = (): ProfileMeter => created({ key: '', name: '', dimension: 'distance', displayUnit: 'mi', monotonic: true })
const newComponent = (): ProfileComponent => created({ key: '', name: '', quantity: 1, enabledByDefault: true, compatiblePartKeys: [] })
const newSpec = (): ProfileSpecification => created({ key: '', label: '', value: '' })
const newPart = (): ProfilePart => created({ key: '', manufacturer: '', partNumber: '', description: '', offers: [] })
const newGroup = (): ProfileWorkGroup => created({ key: '', name: '', sortOrder: (draft.value?.workGroups.length ?? 0) * 10 })
const newTask = (): ProfileWorkDefinition => created({ key: '', title: '', kind: 'maintenance', enabledByDefault: true, schedule: { combination: 'any', rules: [{ type: 'calendar', interval: { value: 12, unit: 'month' } }] }, compatiblePartKeys: [] })
const newRegime = (): ProfileFuelRegime => created({ key: '', name: '', isDefault: (draft.value?.fuel?.regimes.length ?? 0) === 0 })

function stopAuto(row: object, key: string): void {
	if (key === 'key') {
		autoKeyed.delete(toRaw(row))
	}
}

// Follow names for auto-keyed rows, and keep references pointing at them.
function followNames<T extends { key: string }>(rows: T[], nameOf: (row: T) => string, fallback: string, kind: 'meter' | 'component' | 'part' | 'group' | null): void {
	for (const row of rows) {
		if (!autoKeyed.has(toRaw(row)) || nameOf(row).trim() === '') {
			continue
		}
		const key = uniqueSlug(nameOf(row), rows.filter((other) => other !== row).map((other) => other.key), fallback)
		if (key !== row.key) {
			if (kind !== null && draft.value !== null) {
				renameReferences(draft.value, kind, row.key, key)
			}
			row.key = key
		}
	}
}
watch(draft, (doc) => {
	if (doc === null) {
		return
	}
	followNames(doc.meters, (m) => m.name, 'meter', 'meter')
	followNames(doc.components, (c) => c.name, 'component', 'component')
	followNames(doc.parts, (p) => `${p.manufacturer} ${p.partNumber}`, 'part', 'part')
	followNames(doc.workGroups, (g) => g.name, 'group', 'group')
	followNames(doc.workDefinitions, (d) => d.title, 'task', null)
	followNames(doc.specifications ?? [], (s) => s.label, 'spec', null)
	followNames(doc.fuel?.regimes ?? [], (r) => r.name, 'regime', null)
	for (const meter of doc.meters) {
		if (!meterUnits(meter.dimension).includes(meter.displayUnit)) {
			meter.displayUnit = meterUnits(meter.dimension)[0]!
		}
	}
}, { deep: true })

const tagsText = computed({
	get: () => draft.value?.applicability.tags?.join(', ') ?? '',
	set: (value: string) => {
		if (draft.value !== null) {
			draft.value.applicability.tags = value.split(',').map((tag) => slugKey(tag.trim(), '')).filter((tag) => tag !== '')
		}
	},
})
function yearText(field: 'yearFrom' | 'yearTo') {
	return computed({
		get: () => String(draft.value?.applicability[field] ?? ''),
		set: (value: string) => {
			if (draft.value !== null) {
				draft.value.applicability[field] = value === '' ? undefined : Number(value)
			}
		},
	})
}
const yearFrom = yearText('yearFrom')
const yearTo = yearText('yearTo')

const fuelEnabled = computed({
	get: () => draft.value?.fuel !== undefined,
	set: (on: boolean) => {
		if (draft.value !== null) {
			draft.value.fuel = on ? { settings: {}, regimes: [] } : undefined
		}
	},
})
const distanceOptions = [{ value: 'mi', label: distanceUnitLabel('mi') }, { value: 'km', label: distanceUnitLabel('km') }]
const volumeOptions = [{ value: 'gal', label: volumeUnitLabel('gal') }, { value: 'l', label: volumeUnitLabel('l') }]
const economyOptions = ECONOMY_UNITS.map((value) => ({ value, label: economyUnitLabel(value) }))
const fuelTypeOptions = FUEL_TYPES.map((value) => ({ value, label: fuelTypeLabel(value) }))
function fuelSetting(field: 'distanceUnit' | 'volumeUnit' | 'economyUnit' | 'fuelType', value: string | null): void {
	if (draft.value?.fuel) {
		draft.value.fuel.settings = { ...draft.value.fuel.settings, [field]: value ?? undefined }
	}
}
function setDefaultRegime(regime: ProfileFuelRegime): void {
	for (const candidate of draft.value?.fuel?.regimes ?? []) {
		candidate.isDefault = candidate === regime
	}
}

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		if (!isNew.value) {
			const profile = await getWorkspaceProfile(props.uuid)
			revision.value = profile.revision ?? 0
			draft.value = editable(profile.profile as unknown as ProfileDocumentV2)
		} else if (typeof route.query.from === 'string') {
			const source = await getWorkspaceProfile(route.query.from)
			draft.value = copyOf(editable(source.profile as unknown as ProfileDocumentV2), `${source.id}-copy`)
		} else if (typeof route.query.bundled === 'string') {
			const source = (await getProfiles()).items.find((profile) => profile.contentHash === route.query.bundled)
			if (source === undefined) {
				throw new Error(t('maintenance_tracker', 'That bundled profile is no longer available.'))
			}
			draft.value = copyOf(editable(source.profile as unknown as ProfileDocumentV2), `workspace.${slugKey(source.id.split('.').pop() ?? source.id, 'profile')}`)
		} else {
			draft.value = blankProfile(activeWorkspace.value?.name ?? '')
		}
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		loading.value = false
	}
}

function copyOf(doc: ProfileDocumentV2, id: string): ProfileDocumentV2 {
	return { ...doc, id: id.slice(0, 160), name: t('maintenance_tracker', '{name} (copy)', { name: doc.name }).slice(0, 255) }
}

async function save(): Promise<void> {
	if (draft.value === null) {
		return
	}
	error.value = ''
	if (draft.value.name.trim() === '') {
		await showError(t('maintenance_tracker', 'Give the profile a name.'))
		return
	}
	saving.value = true
	try {
		const document = forSave(draft.value)
		if (isNew.value) {
			const created = await createWorkspaceProfile(newUuid, document)
			status.value = t('maintenance_tracker', 'Saved {name}.', { name: created.name })
			await router.replace({ name: 'profile', params: { uuid: created.uuid ?? newUuid } })
			return
		}
		const updated = await updateWorkspaceProfile(props.uuid, revision.value, document)
		revision.value = updated.revision ?? revision.value + 1
		draft.value = editable(updated.profile as unknown as ProfileDocumentV2)
		status.value = t('maintenance_tracker', 'Saved {name}.', { name: updated.name })
	} catch (reason) {
		await showError(t('maintenance_tracker', 'Could not save the profile: {reason}', { reason: errorMessage(reason) }))
	} finally {
		saving.value = false
	}
}

async function showError(message: string): Promise<void> {
	error.value = message
	await nextTick()
	errorCard.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

watch(() => props.uuid, load)
onMounted(load)
</script>

<template>
	<div class="profile-editor">
		<PageHeader
			:title="isNew ? t('maintenance_tracker', 'New profile') : (draft?.name || t('maintenance_tracker', 'Profile'))"
			:subtitle="t('maintenance_tracker', 'Everything an item gets when this profile is applied. Nothing is fetched from the internet.')">
			<template #actions>
				<NcButton variant="tertiary" :to="{ name: 'profiles' }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowLeft" />
					</template>
					{{ t('maintenance_tracker', 'All profiles') }}
				</NcButton>
				<NcButton v-if="draft" :disabled="saving" @click="downloadProfile(forSave(draft))">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDownload" />
					</template>
					{{ t('maintenance_tracker', 'Export JSON') }}
				</NcButton>
			</template>
		</PageHeader>
		<p class="hidden-visually" role="status">
			{{ status }}
		</p>
		<NcNoteCard v-if="!can.manageProfiles" type="info">
			{{ t('maintenance_tracker', 'Only owners and managers can change profiles.') }}
		</NcNoteCard>
		<NcLoadingIcon v-if="loading" :size="44" :name="t('maintenance_tracker', 'Loading')" />
		<form v-else-if="draft" class="profile-editor__form" @submit.prevent="save">
			<nav class="profile-editor__toc" :aria-label="t('maintenance_tracker', 'Profile sections')">
				<NcButton
					v-for="section in sections"
					:key="section.id"
					variant="tertiary"
					@click="jump(section.id)">
					{{ section.label }}
				</NcButton>
			</nav>

			<section id="profile-details" class="profile-editor__section">
				<h3 tabindex="-1">
					{{ t('maintenance_tracker', 'Details') }}
				</h3>
				<div class="grid">
					<NcTextField v-model="draft.name" required :label="t('maintenance_tracker', 'Name')" />
					<NcTextField v-model="draft.version" :label="t('maintenance_tracker', 'Version, like 1.0.0')" />
					<SelectField v-model="draft.category" :options="categoryOptions" :label="t('maintenance_tracker', 'Category')" />
					<NcTextField v-model="draft.id" :label="t('maintenance_tracker', 'Profile ID')" :placeholder="`workspace.${slugKey(draft.name, 'profile')}`" />
					<NcTextField v-model="draft.provenance.author" :label="t('maintenance_tracker', 'Author')" />
					<NcTextField v-model="draft.dataLicense" :label="t('maintenance_tracker', 'Data license (SPDX identifier)')" />
					<NcTextArea
						v-model="draft.description"
						class="wide"
						resize="vertical"
						:label="t('maintenance_tracker', 'Description')" />
				</div>
				<h4>{{ t('maintenance_tracker', 'Applies to (optional)') }}</h4>
				<div class="grid">
					<NcTextField v-model="draft.applicability.manufacturer" :label="t('maintenance_tracker', 'Make')" />
					<NcTextField v-model="draft.applicability.model" :label="t('maintenance_tracker', 'Model')" />
					<NcTextField v-model="yearFrom" type="number" :label="t('maintenance_tracker', 'From year')" />
					<NcTextField v-model="yearTo" type="number" :label="t('maintenance_tracker', 'To year')" />
					<NcTextField v-model="tagsText" class="wide" :label="t('maintenance_tracker', 'Tags, comma separated')" />
				</div>
			</section>

			<section id="profile-meters" class="profile-editor__section">
				<h3 tabindex="-1">
					{{ t('maintenance_tracker', 'Meters') }}
				</h3>
				<ProfileRows
					v-model="draft.meters"
					:fields="meterFields"
					:itemName="t('maintenance_tracker', 'Meter')"
					:addLabel="t('maintenance_tracker', 'Add meter')"
					:emptyText="t('maintenance_tracker', 'No meters. Add an odometer or hour meter for tasks due by use.')"
					:titleOf="(row) => row.name"
					:create="newMeter"
					@edited="stopAuto" />
			</section>

			<section id="profile-components" class="profile-editor__section">
				<h3 tabindex="-1">
					{{ t('maintenance_tracker', 'Components') }}
				</h3>
				<ProfileRows
					v-model="draft.components"
					:fields="componentFields"
					:itemName="t('maintenance_tracker', 'Component')"
					:addLabel="t('maintenance_tracker', 'Add component')"
					:emptyText="t('maintenance_tracker', 'No components.')"
					:titleOf="(row) => row.name"
					:create="newComponent"
					@edited="stopAuto" />
			</section>

			<section id="profile-specs" class="profile-editor__section">
				<h3 tabindex="-1">
					{{ t('maintenance_tracker', 'Specs') }}
				</h3>
				<ProfileRows
					v-model="draft.specifications!"
					:fields="specFields"
					:itemName="t('maintenance_tracker', 'Spec')"
					:addLabel="t('maintenance_tracker', 'Add spec')"
					:emptyText="t('maintenance_tracker', 'No specs, such as oil capacity or tyre pressure.')"
					:titleOf="(row) => row.label"
					:create="newSpec"
					@edited="stopAuto" />
			</section>

			<section id="profile-parts" class="profile-editor__section">
				<h3 tabindex="-1">
					{{ t('maintenance_tracker', 'Parts') }}
				</h3>
				<ProfileRows
					v-model="draft.parts"
					:fields="partFields"
					:itemName="t('maintenance_tracker', 'Part')"
					:addLabel="t('maintenance_tracker', 'Add part')"
					:emptyText="t('maintenance_tracker', 'No parts. Parts listed here can be linked to components and tasks.')"
					:titleOf="(row) => [row.manufacturer, row.partNumber].filter(Boolean).join(' ')"
					:create="newPart"
					@edited="stopAuto">
					<template #extra="{ row }">
						<p v-if="row.offers.length > 0" class="hint">
							{{ t('maintenance_tracker', 'Store links kept from the original profile: {count}', { count: row.offers.length }) }}
						</p>
					</template>
				</ProfileRows>
			</section>

			<section id="profile-groups" class="profile-editor__section">
				<h3 tabindex="-1">
					{{ t('maintenance_tracker', 'Task groups') }}
				</h3>
				<ProfileRows
					v-model="draft.workGroups"
					:fields="groupFields"
					:itemName="t('maintenance_tracker', 'Group')"
					:addLabel="t('maintenance_tracker', 'Add group')"
					:emptyText="t('maintenance_tracker', 'No task groups.')"
					:titleOf="(row) => row.name"
					:create="newGroup"
					@edited="stopAuto" />
			</section>

			<section id="profile-tasks" class="profile-editor__section">
				<h3 tabindex="-1">
					{{ t('maintenance_tracker', 'Tasks') }}
				</h3>
				<ProfileRows
					v-model="draft.workDefinitions"
					:fields="taskFields"
					:itemName="t('maintenance_tracker', 'Task')"
					:addLabel="t('maintenance_tracker', 'Add task')"
					:emptyText="t('maintenance_tracker', 'No tasks.')"
					:titleOf="(row) => row.title"
					:create="newTask"
					@edited="stopAuto">
					<template #extra="{ row }">
						<ScheduleEditor v-model="row.schedule" :meters="draft.meters" :taskName="row.title || t('maintenance_tracker', 'task')" />
					</template>
				</ProfileRows>
			</section>

			<section id="profile-fuel" class="profile-editor__section">
				<h3 tabindex="-1">
					{{ t('maintenance_tracker', 'Fuel') }}
				</h3>
				<NcCheckboxRadioSwitch v-model="fuelEnabled" type="switch">
					{{ t('maintenance_tracker', 'Include fuel settings and regimes') }}
				</NcCheckboxRadioSwitch>
				<template v-if="draft.fuel">
					<p class="hint">
						{{ t('maintenance_tracker', 'Units apply only to items that still use the default fuel settings. Regimes are added by name; the default is used only on items without regimes.') }}
					</p>
					<div class="grid">
						<SelectField
							:modelValue="draft.fuel.settings?.distanceUnit ?? null"
							clearable
							:options="distanceOptions"
							:label="t('maintenance_tracker', 'Distance unit')"
							@update:modelValue="fuelSetting('distanceUnit', $event)" />
						<SelectField
							:modelValue="draft.fuel.settings?.volumeUnit ?? null"
							clearable
							:options="volumeOptions"
							:label="t('maintenance_tracker', 'Volume unit')"
							@update:modelValue="fuelSetting('volumeUnit', $event)" />
						<SelectField
							:modelValue="draft.fuel.settings?.economyUnit ?? null"
							clearable
							:options="economyOptions"
							:label="t('maintenance_tracker', 'Fuel economy unit')"
							@update:modelValue="fuelSetting('economyUnit', $event)" />
						<SelectField
							:modelValue="draft.fuel.settings?.fuelType ?? null"
							clearable
							:options="fuelTypeOptions"
							:label="t('maintenance_tracker', 'Fuel type')"
							@update:modelValue="fuelSetting('fuelType', $event)" />
					</div>
					<h4>{{ t('maintenance_tracker', 'Driving regimes') }}</h4>
					<ProfileRows
						v-model="draft.fuel.regimes"
						:fields="regimeFields"
						:itemName="t('maintenance_tracker', 'Regime')"
						:addLabel="t('maintenance_tracker', 'Add regime')"
						:emptyText="t('maintenance_tracker', 'No regimes, such as Towing or Highway.')"
						:titleOf="(row) => row.name"
						:create="newRegime"
						@edited="stopAuto">
						<template #extra="{ row, index }">
							<NcCheckboxRadioSwitch
								type="radio"
								name="profile-default-regime"
								:value="`regime-${index}`"
								:modelValue="row.isDefault ? `regime-${index}` : ''"
								@update:modelValue="setDefaultRegime(row)">
								{{ t('maintenance_tracker', 'Default regime') }}
							</NcCheckboxRadioSwitch>
						</template>
					</ProfileRows>
				</template>
			</section>

			<div ref="errorCard">
				<NcNoteCard v-if="error" type="error">
					<p role="alert">
						{{ error }}
					</p>
				</NcNoteCard>
			</div>
			<div class="profile-editor__savebar">
				<NcButton variant="primary" type="submit" :disabled="saving || !can.manageProfiles">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ isNew ? t('maintenance_tracker', 'Create profile') : t('maintenance_tracker', 'Save profile') }}
				</NcButton>
				<NcButton variant="tertiary" :to="{ name: 'profiles' }">
					{{ t('maintenance_tracker', 'Cancel') }}
				</NcButton>
				<span class="profile-editor__saved" aria-hidden="true">{{ status }}</span>
			</div>
		</form>
		<NcNoteCard v-else-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
	</div>
</template>

<style scoped>
.profile-editor__toc {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 12px;
}

.profile-editor__section {
	margin-bottom: 20px;
	scroll-margin-top: 60px;
}

.profile-editor__section h3 {
	margin: 0 0 8px;
	font-size: 17px;
}

.profile-editor__section h4 {
	margin: 12px 0 6px;
	font-size: 15px;
}

.grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 8px 12px;
	align-items: end;
}

.grid .wide {
	grid-column: 1 / -1;
}

.hint {
	margin: 4px 0 8px;
	color: var(--color-text-maxcontrast);
}

.profile-editor__saved {
	color: var(--color-text-maxcontrast);
}

.profile-editor__savebar {
	position: sticky;
	bottom: 0;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 0;
	background: var(--color-main-background);
	border-top: 1px solid var(--color-border);
}
</style>
