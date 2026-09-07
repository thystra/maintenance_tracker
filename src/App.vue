<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type {
	Activity,
	Asset,
	AssetClass,
	Assignment,
	BundledProfile,
	BusinessWeekday,
	Capabilities,
	Category,
	Component,
	CreateAsset,
	MaintenanceOccurrence,
	MaintenanceStatusItem,
	Meter,
	MeterDimension,
	ProfileDocument,
	ProfileInstallation,
	ProfilePreview,
	Reading,
	Relationship,
	RelationshipType,
	ReminderPolicy,
	Specification,
	WorkDefinition,
	WorkGroup,
	WorkSchedule,
} from './types.ts'

import { computed, onMounted, reactive, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import {
	createActivity,
	createAsset,
	createAssignment,
	createCategory,
	createComponent,
	createMeter,
	createReading,
	createRelationship,
	createSpecification,
	createWorkDefinition,
	createWorkGroup,
	getActivities,
	getAssets,
	getAssignments,
	getCapabilities,
	getCategories,
	getComponents,
	getMaintenanceOccurrences,
	getMaintenanceStatus,
	getMeters,
	getProfileInstallation,
	getProfiles,
	getReadings,
	getRelationships,
	getRelationshipTypes,
	getReminderPolicy,
	getSpecifications,
	getWorkDefinitions,
	getWorkGroups,
	installProfile,
	previewProfile,
	reconcileMaintenanceOccurrences,
	updateReminderPolicy,
	validateProfile,
} from './services/api.ts'

const assets = ref<Asset[]>([])
const categories = ref<Category[]>([])
const capabilities = ref<Capabilities | null>(null)
const expandedAsset = ref<string | null>(null)
const components = ref<Record<string, Component[]>>({})
const specifications = ref<Record<string, Specification[]>>({})
const meters = ref<Record<string, Meter[]>>({})
const readings = ref<Record<string, Reading[]>>({})
const workGroups = ref<Record<string, WorkGroup[]>>({})
const workDefinitions = ref<Record<string, WorkDefinition[]>>({})
const activities = ref<Record<string, Activity[]>>({})
const maintenanceStatus = ref<Record<string, MaintenanceStatusItem[]>>({})
const maintenanceOccurrences = ref<Record<string, MaintenanceOccurrence[]>>({})
const reminderPolicy = ref<ReminderPolicy | null>(null)
const profileCatalog = ref<BundledProfile[]>([])
const profileInstallations = ref<Record<string, ProfileInstallation | null>>({})
const profilePreviews = ref<Record<string, ProfilePreview | null>>({})
const profileInstallAttempts = ref<Record<string, { hash: string, uuid: string }>>({})
const profileDraftText = ref('')
const selectedProfileHash = ref('')
const profileValidationMessage = ref('')
const relationshipTypes = ref<RelationshipType[]>([])
const relationships = ref<Relationship[]>([])
const assignments = ref<Assignment[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')

const draft = reactive<CreateAsset>({ name: '', category: 'vehicle', manufacturer: null, model: null })
const categoryDraft = reactive({ key: '', name: '', defaultAssetClass: 'other' as AssetClass })
const componentDraft = reactive({ name: '', type: 'component', parentUuid: '' })
const specificationDraft = reactive({ key: '', label: '', value: '', unit: '', regime: '', componentUuid: '' })
const meterDraft = reactive({ key: '', name: '', dimension: 'distance' as MeterDimension, displayUnit: 'mi', monotonic: true, componentUuid: '' })
const readingDraft = reactive({ meterUuid: '', value: '', observedAt: '', unit: 'mi' })
const workGroupDraft = reactive({ key: '', name: '', description: '', sortOrder: 100 })
const workDefinitionDraft = reactive({
	key: '',
	title: '',
	kind: 'maintenance',
	groupUuid: '',
	componentUuid: '',
	scheduleMode: 'none' as 'none' | 'calendar' | 'business_days' | 'meter' | 'meter_calendar',
	calendarValue: 12,
	calendarUnit: 'month' as 'day' | 'week' | 'month' | 'year',
	businessDaysValue: 10,
	businessWeekdays: ['mon', 'tue', 'wed', 'thu', 'fri'] as BusinessWeekday[],
	meterUuid: '',
	meterValue: '7500',
	meterUnit: 'mi',
})
const activityDraft = reactive({
	definitionUuid: '',
	title: '',
	kind: 'maintenance',
	performedAt: '',
	summary: '',
	notes: '',
	meterUuid: '',
	meterValue: '',
	meterUnit: 'mi',
})
const relationshipDraft = reactive({ sourceAssetUuid: '', targetAssetUuid: '', type: 'tows', context: 'general', isDefault: false })
const assignmentDraft = reactive({ sourceAssetUuid: '', targetAssetUuid: '', type: 'tows', context: 'trip', isPrimary: true, effectiveFrom: '', effectiveUntil: '' })
const reminderPolicyDraft = reactive({ calendarLeadDays: 14, meterLeadPercent: 10 })
const empty = computed(() => !loading.value && assets.value.length === 0)
const businessWeekdayOptions: Array<{ value: BusinessWeekday, label: string }> = [
	{ value: 'sun', label: 'Sun' },
	{ value: 'mon', label: 'Mon' },
	{ value: 'tue', label: 'Tue' },
	{ value: 'wed', label: 'Wed' },
	{ value: 'thu', label: 'Thu' },
	{ value: 'fri', label: 'Fri' },
	{ value: 'sat', label: 'Sat' },
]

function categoryLabel(key: string): string {
	return categories.value.find((category) => category.key === key)?.name ?? key
}

function meterUnits(dimension: MeterDimension): string[] {
	if (dimension === 'distance') {
		return ['mi', 'km', 'm', 'mm']
	}
	if (dimension === 'runtime') {
		return ['hour', 'min', 's']
	}
	return ['use']
}

function syncMeterUnit(): void {
	meterDraft.displayUnit = meterUnits(meterDraft.dimension)[0] ?? 'use'
}

function syncReadingUnit(): void {
	const meter = Object.values(meters.value).flat().find((item) => item.uuid === readingDraft.meterUuid)
	if (meter) {
		readingDraft.unit = meter.displayUnit
	}
}

function syncWorkMeterUnit(assetUuid: string): void {
	const meter = (meters.value[assetUuid] ?? []).find((item) => item.uuid === workDefinitionDraft.meterUuid)
	if (meter) {
		workDefinitionDraft.meterUnit = meter.displayUnit
	}
}

function dueStateLabel(state: MaintenanceStatusItem['state']): string {
	return ({
		inactive: 'Inactive',
		unscheduled: 'Unscheduled',
		baseline_required: 'Baseline needed',
		upcoming: 'Upcoming',
		due: 'Due',
		overdue: 'Overdue',
		unknown: 'Needs data',
	} satisfies Record<MaintenanceStatusItem['state'], string>)[state]
}

function maintenanceRuleLabel(item: MaintenanceStatusItem): string {
	if (item.state === 'baseline_required') {
		return 'Record the first completed activity to establish the schedule baseline.'
	}
	if (item.state === 'unknown') {
		return 'One or more schedule rules need meter history before status can be determined.'
	}
	const trigger = item.rules.find((rule) => rule.position === item.triggerRulePosition) ?? item.rules[0]
	if (!trigger) {
		return workScheduleLabel(item.definition.schedule)
	}
	if (trigger.dueOn) {
		const remaining = trigger.remainingDays ?? 0
		if (remaining === 0) {
			return `Due ${trigger.dueOn}`
		}
		return remaining > 0
			? `Due ${trigger.dueOn} · ${remaining} days remaining`
			: `Due ${trigger.dueOn} · ${Math.abs(remaining)} days overdue`
	}
	if (trigger.meter && trigger.current) {
		return `${trigger.meter.name}: ${trigger.current.originalValue} ${trigger.current.originalUnit} · ${workScheduleLabel(item.definition.schedule)}`
	}
	return workScheduleLabel(item.definition.schedule)
}

async function refreshMaintenanceStatus(assetUuid: string): Promise<void> {
	maintenanceStatus.value[assetUuid] = (await getMaintenanceStatus(assetUuid)).items
}

function workScheduleLabel(schedule: WorkSchedule): string {
	if (schedule === 'none') {
		return 'unscheduled'
	}
	return schedule.rules.map((rule) => {
		if (rule.type === 'calendar') {
			return `every ${rule.interval.value} ${rule.interval.unit}`
		}
		if (rule.type === 'business_days') {
			return `every ${rule.interval.value} business days (${rule.weekdays.map((day) => day.slice(0, 1).toUpperCase() + day.slice(1)).join(', ')})`
		}
		return `every ${rule.interval.value} ${rule.interval.unit}`
	}).join(' OR ')
}

function buildWorkSchedule(): WorkSchedule {
	if (workDefinitionDraft.scheduleMode === 'none') {
		return 'none'
	}
	const rules: Exclude<WorkSchedule, 'none'>['rules'] = []
	if (workDefinitionDraft.scheduleMode === 'calendar' || workDefinitionDraft.scheduleMode === 'meter_calendar') {
		rules.push({
			type: 'calendar',
			interval: { value: workDefinitionDraft.calendarValue, unit: workDefinitionDraft.calendarUnit },
		})
	}
	if (workDefinitionDraft.scheduleMode === 'business_days') {
		rules.push({
			type: 'business_days',
			interval: { value: workDefinitionDraft.businessDaysValue, unit: 'business_day' },
			weekdays: [...workDefinitionDraft.businessWeekdays],
		})
	}
	if (workDefinitionDraft.scheduleMode === 'meter' || workDefinitionDraft.scheduleMode === 'meter_calendar') {
		rules.push({
			type: 'meter',
			meterUuid: workDefinitionDraft.meterUuid,
			interval: { value: workDefinitionDraft.meterValue, unit: workDefinitionDraft.meterUnit },
		})
	}
	return { combination: 'any', rules }
}

function latestReading(meterUuid: string): Reading | null {
	let latest: Reading | null = null
	for (const reading of readings.value[meterUuid] ?? []) {
		if (!reading.effective) {
			continue
		}
		if (latest === null || reading.observedAt > latest.observedAt || (reading.observedAt === latest.observedAt && reading.createdAt > latest.createdAt)) {
			latest = reading
		}
	}
	return latest
}

function localNow(): string {
	const now = new Date()
	const offset = now.getTimezoneOffset() * 60000
	return new Date(now.getTime() - offset).toISOString().slice(0, 16)
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
		const [serverCapabilities, firstPage, categoryList, typeList, relationshipList, assignmentList, policy, profiles] = await Promise.all([
			getCapabilities(),
			getAssets(),
			getCategories(),
			getRelationshipTypes(),
			getRelationships(),
			getAssignments(),
			getReminderPolicy(),
			getProfiles(),
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
		relationshipTypes.value = typeList.items
		relationships.value = relationshipList.items
		assignments.value = assignmentList.items
		reminderPolicy.value = policy
		profileCatalog.value = profiles.items
		reminderPolicyDraft.calendarLeadDays = policy.calendarLeadDays
		reminderPolicyDraft.meterLeadPercent = policy.meterLeadPercent
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
		const [componentList, specificationList, meterList, workGroupList, workDefinitionList, activityList, statusList, occurrenceList, profileInstallation] = await Promise.all([getComponents(asset.uuid), getSpecifications(asset.uuid), getMeters(asset.uuid), getWorkGroups(asset.uuid), getWorkDefinitions(asset.uuid), getActivities(asset.uuid), getMaintenanceStatus(asset.uuid), getMaintenanceOccurrences(asset.uuid), getProfileInstallation(asset.uuid)])
		components.value[asset.uuid] = componentList.items
		specifications.value[asset.uuid] = specificationList.items
		meters.value[asset.uuid] = meterList.items
		workGroups.value[asset.uuid] = workGroupList.items
		workDefinitions.value[asset.uuid] = workDefinitionList.items
		activities.value[asset.uuid] = activityList.items
		maintenanceStatus.value[asset.uuid] = statusList.items
		maintenanceOccurrences.value[asset.uuid] = occurrenceList.items
		profileInstallations.value[asset.uuid] = profileInstallation.installation
		await Promise.all(meterList.items.map(async (meter) => {
			readings.value[meter.uuid] = (await getReadings(meter.uuid)).items
		}))
		if (profileDraftText.value === '' && profileCatalog.value[0]) {
			selectBundledProfile(profileCatalog.value[0].contentHash)
		}
		readingDraft.meterUuid = meterList.items[0]?.uuid ?? ''
		workDefinitionDraft.meterUuid = meterList.items[0]?.uuid ?? ''
		activityDraft.meterUuid = meterList.items[0]?.uuid ?? ''
		activityDraft.performedAt = localNow()
		readingDraft.observedAt = localNow()
		syncReadingUnit()
		syncWorkMeterUnit(asset.uuid)
	} catch (reason) {
		error.value = readableError(reason)
	}
}

function parseProfileDraft(): ProfileDocument {
	let parsed: unknown
	try {
		parsed = JSON.parse(profileDraftText.value)
	} catch {
		throw new Error('Profile JSON is not valid JSON.')
	}
	if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
		throw new Error('Profile JSON must contain an object at the root.')
	}
	return parsed as ProfileDocument
}

function selectBundledProfile(contentHash: string): void {
	selectedProfileHash.value = contentHash
	const selected = profileCatalog.value.find((profile) => profile.contentHash === contentHash)
	if (selected) {
		profileDraftText.value = JSON.stringify(selected.profile, null, 2)
		profileValidationMessage.value = ''
	}
}

async function validateProfileDraft(): Promise<void> {
	saving.value = true
	error.value = ''
	profileValidationMessage.value = ''
	try {
		const result = await validateProfile(parseProfileDraft())
		profileValidationMessage.value = `Valid ${result.profile.origin} profile ${result.profile.id} ${result.profile.version} · SHA-256 ${result.profile.contentHash}`
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function previewProfileForAsset(asset: Asset): Promise<void> {
	saving.value = true
	error.value = ''
	try {
		const preview = await previewProfile(asset.uuid, parseProfileDraft())
		profilePreviews.value[asset.uuid] = preview
		const currentAttempt = profileInstallAttempts.value[asset.uuid]
		if (!currentAttempt || currentAttempt.hash !== preview.profile.contentHash) {
			profileInstallAttempts.value[asset.uuid] = { hash: preview.profile.contentHash, uuid: crypto.randomUUID() }
		}
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function installProfileForAsset(asset: Asset): Promise<void> {
	let preview = profilePreviews.value[asset.uuid]
	if (!preview) {
		await previewProfileForAsset(asset)
		preview = profilePreviews.value[asset.uuid]
	}
	if (!preview?.installable) {
		return
	}
	const attempt = profileInstallAttempts.value[asset.uuid]
	if (!attempt || attempt.hash !== preview.profile.contentHash) {
		throw new Error('Profile installation attempt identity is unavailable; preview the profile again.')
	}
	saving.value = true
	error.value = ''
	try {
		const installed = await installProfile(asset.uuid, attempt.uuid, parseProfileDraft())
		profileInstallations.value[asset.uuid] = installed
		asset.profile = { key: installed.profile.id, version: installed.profile.version }
		const [componentList, meterList, groupList, definitionList, statusList, occurrenceList] = await Promise.all([
			getComponents(asset.uuid),
			getMeters(asset.uuid),
			getWorkGroups(asset.uuid),
			getWorkDefinitions(asset.uuid),
			getMaintenanceStatus(asset.uuid),
			getMaintenanceOccurrences(asset.uuid),
		])
		components.value[asset.uuid] = componentList.items
		meters.value[asset.uuid] = meterList.items
		workGroups.value[asset.uuid] = groupList.items
		workDefinitions.value[asset.uuid] = definitionList.items
		maintenanceStatus.value[asset.uuid] = statusList.items
		maintenanceOccurrences.value[asset.uuid] = occurrenceList.items
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
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

async function submitMeter(asset: Asset): Promise<void> {
	if (meterDraft.key.trim() === '' || meterDraft.name.trim() === '') {
		return
	}
	saving.value = true
	error.value = ''
	try {
		const created = await createMeter(asset.uuid, {
			key: meterDraft.key.trim(),
			name: meterDraft.name.trim(),
			dimension: meterDraft.dimension,
			displayUnit: meterDraft.displayUnit,
			monotonic: meterDraft.monotonic,
			componentUuid: meterDraft.componentUuid || null,
		})
		meters.value[asset.uuid] = [...(meters.value[asset.uuid] ?? []), created]
		readings.value[created.uuid] = []
		readingDraft.meterUuid = created.uuid
		readingDraft.unit = created.displayUnit
		readingDraft.observedAt = localNow()
		meterDraft.key = ''
		meterDraft.name = ''
		meterDraft.componentUuid = ''
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function submitReading(): Promise<void> {
	if (readingDraft.meterUuid === '' || readingDraft.value.trim() === '' || readingDraft.observedAt === '') {
		return
	}
	saving.value = true
	error.value = ''
	try {
		const created = await createReading(readingDraft.meterUuid, {
			value: readingDraft.value.trim(),
			unit: readingDraft.unit,
			observedAt: new Date(readingDraft.observedAt).toISOString().replace(/\.\d{3}Z$/, 'Z'),
		})
		readings.value[readingDraft.meterUuid] = [...(readings.value[readingDraft.meterUuid] ?? []), created]
		if (expandedAsset.value !== null) {
			await refreshMaintenanceStatus(expandedAsset.value)
		}
		readingDraft.value = ''
		readingDraft.observedAt = localNow()
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function submitWorkGroup(asset: Asset): Promise<void> {
	if (workGroupDraft.key.trim() === '' || workGroupDraft.name.trim() === '') {
		return
	}
	saving.value = true
	error.value = ''
	try {
		const created = await createWorkGroup(asset.uuid, {
			key: workGroupDraft.key.trim(),
			name: workGroupDraft.name.trim(),
			description: workGroupDraft.description.trim() || null,
			sortOrder: workGroupDraft.sortOrder,
		})
		workGroups.value[asset.uuid] = [...(workGroups.value[asset.uuid] ?? []), created]
		workGroupDraft.key = ''
		workGroupDraft.name = ''
		workGroupDraft.description = ''
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function submitWorkDefinition(asset: Asset): Promise<void> {
	if (workDefinitionDraft.key.trim() === '' || workDefinitionDraft.title.trim() === '') {
		return
	}
	if ((workDefinitionDraft.scheduleMode === 'meter' || workDefinitionDraft.scheduleMode === 'meter_calendar') && workDefinitionDraft.meterUuid === '') {
		error.value = 'Select a meter for the schedule.'
		return
	}
	if (workDefinitionDraft.scheduleMode === 'business_days' && workDefinitionDraft.businessWeekdays.length === 0) {
		error.value = 'Select at least one business day.'
		return
	}
	saving.value = true
	error.value = ''
	try {
		const created = await createWorkDefinition(asset.uuid, {
			key: workDefinitionDraft.key.trim(),
			title: workDefinitionDraft.title.trim(),
			kind: workDefinitionDraft.kind.trim() || 'maintenance',
			groupUuid: workDefinitionDraft.groupUuid || null,
			componentUuid: workDefinitionDraft.componentUuid || null,
			schedule: buildWorkSchedule(),
		})
		workDefinitions.value[asset.uuid] = [...(workDefinitions.value[asset.uuid] ?? []), created]
		await refreshMaintenanceStatus(asset.uuid)
		workDefinitionDraft.key = ''
		workDefinitionDraft.title = ''
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function submitActivity(asset: Asset): Promise<void> {
	if (activityDraft.performedAt === '') {
		return
	}
	if (activityDraft.definitionUuid === '' && activityDraft.title.trim() === '') {
		error.value = 'Choose a work definition or enter ad-hoc work.'
		return
	}
	saving.value = true
	error.value = ''
	try {
		const item = activityDraft.definitionUuid !== ''
			? { uuid: crypto.randomUUID(), definitionUuid: activityDraft.definitionUuid, notes: null }
			: { uuid: crypto.randomUUID(), title: activityDraft.title.trim(), kind: activityDraft.kind.trim() || 'maintenance', notes: null }
		const meterList = activityDraft.meterUuid !== '' && activityDraft.meterValue.trim() !== ''
			? [{ uuid: crypto.randomUUID(), meterUuid: activityDraft.meterUuid, reading: { uuid: crypto.randomUUID(), value: activityDraft.meterValue, unit: activityDraft.meterUnit } }]
			: []
		const created = await createActivity(asset.uuid, {
			uuid: crypto.randomUUID(),
			performedAt: new Date(activityDraft.performedAt).toISOString().replace('.000Z', 'Z'),
			summary: activityDraft.summary.trim() || null,
			notes: activityDraft.notes.trim() || null,
			items: [item],
			meters: meterList,
		})
		activities.value[asset.uuid] = [created, ...(activities.value[asset.uuid] ?? [])]
		await refreshMaintenanceStatus(asset.uuid)
		activityDraft.title = ''
		activityDraft.summary = ''
		activityDraft.notes = ''
		activityDraft.meterValue = ''
		activityDraft.performedAt = localNow()
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function reconcileWorkQueue(assetUuid: string): Promise<void> {
	saving.value = true
	error.value = ''
	try {
		maintenanceOccurrences.value[assetUuid] = (await reconcileMaintenanceOccurrences(assetUuid)).items
		await refreshMaintenanceStatus(assetUuid)
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function submitReminderPolicy(): Promise<void> {
	if (reminderPolicy.value === null) {
		return
	}
	saving.value = true
	error.value = ''
	try {
		const updated = await updateReminderPolicy(reminderPolicy.value.revision, {
			calendarLeadDays: reminderPolicyDraft.calendarLeadDays,
			meterLeadPercent: reminderPolicyDraft.meterLeadPercent,
		})
		reminderPolicy.value = updated
		reminderPolicyDraft.calendarLeadDays = updated.calendarLeadDays
		reminderPolicyDraft.meterLeadPercent = updated.meterLeadPercent
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

function forecastLabel(occurrence: MaintenanceOccurrence): string {
	const state = occurrence.current?.forecast.state
	return state === 'due_soon' ? 'Due soon' : state === 'due' ? 'Due' : state === 'overdue' ? 'Overdue' : state ?? 'Unavailable'
}

async function submitRelationship(): Promise<void> {
	if (relationshipDraft.sourceAssetUuid === '' || relationshipDraft.targetAssetUuid === '') {
		return
	}
	saving.value = true
	error.value = ''
	try {
		const created = await createRelationship({
			sourceAssetUuid: relationshipDraft.sourceAssetUuid,
			targetAssetUuid: relationshipDraft.targetAssetUuid,
			type: relationshipDraft.type,
			context: relationshipDraft.context.trim() || null,
			isDefault: relationshipDraft.isDefault,
		})
		relationships.value = [...relationships.value, created]
		relationshipDraft.targetAssetUuid = ''
		relationshipDraft.isDefault = false
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function submitAssignment(): Promise<void> {
	if (assignmentDraft.sourceAssetUuid === '' || assignmentDraft.targetAssetUuid === '' || assignmentDraft.effectiveFrom === '') {
		return
	}
	saving.value = true
	error.value = ''
	try {
		const created = await createAssignment({
			sourceAssetUuid: assignmentDraft.sourceAssetUuid,
			targetAssetUuid: assignmentDraft.targetAssetUuid,
			type: assignmentDraft.type,
			context: assignmentDraft.context.trim() || null,
			isPrimary: assignmentDraft.isPrimary,
			effectiveFrom: assignmentDraft.effectiveFrom,
			effectiveUntil: assignmentDraft.effectiveUntil || null,
		})
		assignments.value = [...assignments.value, created]
		assignmentDraft.targetAssetUuid = ''
		assignmentDraft.effectiveUntil = ''
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
							Define maintained items, their components, specifications, relationships, and current assignments. Maintenance rules and work records build on this inventory.
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
					<h2>Asset relationships</h2>
					<p class="panel-copy">
						Record compatibility and durable associations separately from what was actually used on a trip or work record.
					</p>
					<ul class="compact-list relationship-list">
						<li v-for="relationship in relationships" :key="relationship.uuid">
							<strong>{{ relationship.sourceAsset.name }} {{ relationship.label.toLowerCase() }} {{ relationship.targetAsset.name }}</strong>
							<span>{{ relationship.context || 'no context' }}<template v-if="relationship.isDefault"> · default</template></span>
						</li>
					</ul>
					<form class="relationship-form" @submit.prevent="submitRelationship">
						<select v-model="relationshipDraft.sourceAssetUuid" required>
							<option value="" disabled>
								Source asset
							</option><option v-for="asset in assets" :key="asset.uuid" :value="asset.uuid">
								{{ asset.name }}
							</option>
						</select>
						<select v-model="relationshipDraft.type">
							<option v-for="type in relationshipTypes" :key="type.key" :value="type.key">
								{{ type.label }}
							</option>
						</select>
						<select v-model="relationshipDraft.targetAssetUuid" required>
							<option value="" disabled>
								Target asset
							</option><option v-for="asset in assets" :key="asset.uuid" :value="asset.uuid">
								{{ asset.name }}
							</option>
						</select>
						<input v-model="relationshipDraft.context" placeholder="context (general, trip, fuel)">
						<label class="check-field"><input v-model="relationshipDraft.isDefault" type="checkbox"><span>Default for context</span></label>
						<button type="submit" :disabled="saving || assets.length < 2">
							Add relationship
						</button>
					</form>
				</section>

				<section class="panel">
					<h2>Operational assignments</h2>
					<p class="panel-copy">
						Assignments are effective-dated defaults. They do not rewrite historical activity and may be overridden by a later work or trip record.
					</p>
					<ul class="compact-list relationship-list">
						<li v-for="assignment in assignments" :key="assignment.uuid">
							<strong>{{ assignment.sourceAsset.name }} {{ assignment.label.toLowerCase() }} {{ assignment.targetAsset.name }}</strong>
							<span>{{ assignment.effectiveFrom }} → {{ assignment.effectiveUntil || 'indefinite' }}<template v-if="assignment.isPrimary"> · primary</template></span>
						</li>
					</ul>
					<form class="assignment-form" @submit.prevent="submitAssignment">
						<select v-model="assignmentDraft.sourceAssetUuid" required>
							<option value="" disabled>
								Source asset
							</option><option v-for="asset in assets" :key="asset.uuid" :value="asset.uuid">
								{{ asset.name }}
							</option>
						</select>
						<select v-model="assignmentDraft.type">
							<option v-for="type in relationshipTypes" :key="type.key" :value="type.key">
								{{ type.label }}
							</option>
						</select>
						<select v-model="assignmentDraft.targetAssetUuid" required>
							<option value="" disabled>
								Target asset
							</option><option v-for="asset in assets" :key="asset.uuid" :value="asset.uuid">
								{{ asset.name }}
							</option>
						</select>
						<input v-model="assignmentDraft.context" placeholder="context (trip, fuel)">
						<label><span>Effective from</span><input v-model="assignmentDraft.effectiveFrom" type="date" required></label>
						<label><span>Effective until</span><input v-model="assignmentDraft.effectiveUntil" type="date"></label>
						<label class="check-field"><input v-model="assignmentDraft.isPrimary" type="checkbox"><span>Primary</span></label>
						<button type="submit" :disabled="saving || assets.length < 2">
							Add assignment
						</button>
					</form>
				</section>

				<section class="panel">
					<h2>Maintenance forecast policy</h2>
					<p class="panel-copy">
						These lead values decide when upcoming maintenance enters the work queue. They do not change whether maintenance is actually due.
					</p>
					<form v-if="reminderPolicy" class="reminder-policy-form" @submit.prevent="submitReminderPolicy">
						<label><span>Calendar lead days</span><input
							v-model.number="reminderPolicyDraft.calendarLeadDays"
							type="number"
							min="0"
							max="3650"
							required></label>
						<label><span>Meter lead percent</span><input
							v-model.number="reminderPolicyDraft.meterLeadPercent"
							type="number"
							min="0"
							max="100"
							required></label>
						<button type="submit" :disabled="saving">
							Save forecast policy
						</button>
						<span class="policy-source">{{ reminderPolicy.source === 'default' ? 'Using application defaults' : `Workspace policy · revision ${reminderPolicy.revision}` }}</span>
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
									{{ expandedAsset === asset.uuid ? 'Close' : 'Details & meters' }}
								</button>
							</div>
							<div v-if="expandedAsset === asset.uuid" class="asset-details">
								<section class="profile-section">
									<h4>Maintenance profile</h4>
									<div v-if="profileInstallations[asset.uuid]" class="profile-installed">
										<strong>{{ profileInstallations[asset.uuid]?.profile.id }} {{ profileInstallations[asset.uuid]?.profile.version }}</strong>
										<span>{{ profileInstallations[asset.uuid]?.profile.origin }} · {{ profileInstallations[asset.uuid]?.profile.trustState }} · {{ profileInstallations[asset.uuid]?.bindings.length }} source bindings</span>
										<code>{{ profileInstallations[asset.uuid]?.profile.contentHash }}</code>
									</div>
									<template v-else>
										<p class="panel-copy">
											Profiles are validated server-side, previewed against this asset, then materialized as ordinary editable components, meters, groups, and work definitions.
										</p>
										<label><span>Bundled profile</span><select :value="selectedProfileHash" @change="selectBundledProfile(($event.target as HTMLSelectElement).value)">
											<option value="">Local JSON</option>
											<option v-for="profile in profileCatalog" :key="profile.contentHash" :value="profile.contentHash">{{ profile.name }} · {{ profile.version }}</option>
										</select></label>
										<label><span>Profile JSON</span><textarea v-model="profileDraftText" rows="8" @input="selectedProfileHash = ''; profileValidationMessage = ''" /></label>
										<div class="button-row">
											<button
												class="button--secondary"
												type="button"
												:disabled="saving || profileDraftText.trim() === ''"
												@click="validateProfileDraft">
												Validate
											</button>
											<button
												class="button--secondary"
												type="button"
												:disabled="saving || profileDraftText.trim() === ''"
												@click="previewProfileForAsset(asset)">
												Preview
											</button>
											<button type="button" :disabled="saving || !(profilePreviews[asset.uuid]?.installable)" @click="installProfileForAsset(asset)">
												Install profile
											</button>
										</div>
										<p v-if="profileValidationMessage" class="profile-validation">
											{{ profileValidationMessage }}
										</p>
										<div v-if="profilePreviews[asset.uuid]" class="profile-preview">
											<strong>{{ profilePreviews[asset.uuid]?.installable ? 'Ready to install' : 'Cannot install yet' }}</strong>
											<span>{{ profilePreviews[asset.uuid]?.materializes.components }} components · {{ profilePreviews[asset.uuid]?.materializes.meters }} meters · {{ profilePreviews[asset.uuid]?.materializes.workDefinitions }} work definitions</span>
											<ul v-if="profilePreviews[asset.uuid]?.conflicts.length">
												<li v-for="conflict in profilePreviews[asset.uuid]?.conflicts" :key="conflict">
													{{ conflict }}
												</li>
											</ul>
											<ul v-if="profilePreviews[asset.uuid]?.warnings.length">
												<li v-for="warning in profilePreviews[asset.uuid]?.warnings" :key="warning">
													{{ warning }}
												</li>
											</ul>
										</div>
									</template>
								</section>
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
								<section class="meter-section">
									<h4>Meters & readings</h4>
									<ul class="compact-list">
										<li v-for="meter in meters[asset.uuid] ?? []" :key="meter.uuid">
											<strong>{{ meter.name }}</strong>
											<span><template v-if="latestReading(meter.uuid)">{{ latestReading(meter.uuid)?.originalValue }} {{ latestReading(meter.uuid)?.originalUnit }} · </template>{{ meter.dimension }} · {{ meter.monotonic ? 'monotonic' : 'non-monotonic' }}</span>
										</li>
									</ul>
									<form class="meter-form" @submit.prevent="submitMeter(asset)">
										<input
											v-model="meterDraft.key"
											pattern="[a-z0-9][a-z0-9_-]*"
											placeholder="odometer"
											required>
										<input v-model="meterDraft.name" placeholder="Odometer" required>
										<select v-model="meterDraft.dimension" @change="syncMeterUnit">
											<option value="distance">
												distance
											</option><option value="runtime">
												runtime
											</option><option value="usage_count">
												usage count
											</option>
										</select>
										<select v-model="meterDraft.displayUnit">
											<option v-for="unit in meterUnits(meterDraft.dimension)" :key="unit" :value="unit">
												{{ unit }}
											</option>
										</select>
										<select v-model="meterDraft.componentUuid">
											<option value="">
												Whole asset
											</option><option v-for="component in components[asset.uuid] ?? []" :key="component.uuid" :value="component.uuid">
												{{ component.name }}
											</option>
										</select>
										<label class="check-field"><input v-model="meterDraft.monotonic" type="checkbox"><span>Monotonic</span></label>
										<button type="submit">
											Add meter
										</button>
									</form>
									<form class="reading-form" @submit.prevent="submitReading">
										<select v-model="readingDraft.meterUuid" required @change="syncReadingUnit">
											<option value="" disabled>
												Meter
											</option><option v-for="meter in meters[asset.uuid] ?? []" :key="meter.uuid" :value="meter.uuid">
												{{ meter.name }}
											</option>
										</select>
										<input
											v-model="readingDraft.value"
											inputmode="decimal"
											placeholder="Reading"
											required>
										<input v-model="readingDraft.unit" placeholder="unit" required>
										<input v-model="readingDraft.observedAt" type="datetime-local" required>
										<button type="submit" :disabled="(meters[asset.uuid] ?? []).length === 0">
											Record reading
										</button>
									</form>
								</section>
								<section class="status-section">
									<h4>Maintenance status</h4>
									<p class="panel-copy">
										Derived from the current schedules, completed activity, and effective meter history. Due state is not stored.
									</p>
									<ul class="compact-list status-list">
										<li v-for="item in maintenanceStatus[asset.uuid] ?? []" :key="item.definition.uuid">
											<div class="status-line">
												<strong>{{ item.definition.title }}</strong>
												<span class="status-chip" :data-state="item.state">{{ dueStateLabel(item.state) }}</span>
											</div>
											<span>{{ maintenanceRuleLabel(item) }}</span>
											<span v-if="item.lastPerformedAt">Last completed {{ new Date(item.lastPerformedAt).toLocaleString() }}</span>
										</li>
									</ul>
								</section>
								<section class="occurrence-section">
									<div class="section-heading section-heading--compact">
										<div>
											<h4>Maintenance work queue</h4><p class="panel-copy">
												Due soon is policy-layer attention; each row still displays current derived status.
											</p>
										</div>
										<button
											class="button--secondary"
											type="button"
											:disabled="saving"
											@click="reconcileWorkQueue(asset.uuid)">
											Reconcile work queue
										</button>
									</div>
									<p v-if="(maintenanceOccurrences[asset.uuid] ?? []).length === 0" class="empty-inline">
										No open maintenance occurrences.
									</p>
									<ul v-else class="compact-list occurrence-list">
										<li v-for="occurrence in maintenanceOccurrences[asset.uuid] ?? []" :key="occurrence.uuid">
											<div class="status-line">
												<strong>{{ occurrence.current?.definition.title ?? occurrence.definitionUuid }}</strong><span class="status-chip" :data-state="occurrence.current?.forecast.state">{{ forecastLabel(occurrence) }}</span>
											</div>
											<span>Opened {{ new Date(occurrence.openedAt).toLocaleString() }}<template v-if="occurrence.current?.lastPerformedAt"> · baseline {{ new Date(occurrence.current.lastPerformedAt).toLocaleString() }}</template></span>
										</li>
									</ul>
								</section>
								<section class="work-section">
									<h4>Maintenance definitions</h4>
									<p class="panel-copy">
										Every definition carries an explicit schedule. Choose Unscheduled for ad-hoc work.
									</p>
									<ul class="compact-list">
										<li v-for="definition in workDefinitions[asset.uuid] ?? []" :key="definition.uuid">
											<strong>{{ definition.title }}</strong>
											<span>{{ definition.kind }} · {{ workScheduleLabel(definition.schedule) }}</span>
										</li>
									</ul>
									<form class="work-group-form" @submit.prevent="submitWorkGroup(asset)">
										<input
											v-model="workGroupDraft.key"
											pattern="[a-z0-9][a-z0-9_-]*"
											placeholder="engine"
											required>
										<input v-model="workGroupDraft.name" placeholder="Engine" required>
										<input v-model="workGroupDraft.description" placeholder="Description (optional)">
										<input v-model.number="workGroupDraft.sortOrder" type="number" placeholder="Sort order">
										<button type="submit">
											Add work group
										</button>
									</form>
									<form class="work-definition-form" @submit.prevent="submitWorkDefinition(asset)">
										<input
											v-model="workDefinitionDraft.key"
											pattern="[a-z0-9][a-z0-9_-]*"
											placeholder="oil_change"
											required>
										<input v-model="workDefinitionDraft.title" placeholder="Oil change" required>
										<input v-model="workDefinitionDraft.kind" placeholder="maintenance" required>
										<select v-model="workDefinitionDraft.groupUuid">
											<option value="">
												No work group
											</option>
											<option v-for="group in workGroups[asset.uuid] ?? []" :key="group.uuid" :value="group.uuid">
												{{ group.name }}
											</option>
										</select>
										<select v-model="workDefinitionDraft.componentUuid">
											<option value="">
												Whole asset
											</option>
											<option v-for="component in components[asset.uuid] ?? []" :key="component.uuid" :value="component.uuid">
												{{ component.name }}
											</option>
										</select>
										<select v-model="workDefinitionDraft.scheduleMode">
											<option value="none">
												Unscheduled (schedule: none)
											</option>
											<option value="calendar">
												Calendar interval
											</option>
											<option value="business_days">
												Business-day interval
											</option>
											<option value="meter">
												Meter interval
											</option>
											<option value="meter_calendar">
												Meter OR calendar
											</option>
										</select>
										<template v-if="workDefinitionDraft.scheduleMode === 'calendar' || workDefinitionDraft.scheduleMode === 'meter_calendar'">
											<input
												v-model.number="workDefinitionDraft.calendarValue"
												type="number"
												min="1"
												required>
											<select v-model="workDefinitionDraft.calendarUnit">
												<option value="day">
													days
												</option><option value="week">
													weeks
												</option><option value="month">
													months
												</option><option value="year">
													years
												</option>
											</select>
										</template>
										<template v-if="workDefinitionDraft.scheduleMode === 'business_days'">
											<input
												v-model.number="workDefinitionDraft.businessDaysValue"
												type="number"
												min="1"
												required>
											<div class="weekday-picker" aria-label="Business days">
												<label v-for="day in businessWeekdayOptions" :key="day.value" class="weekday-option">
													<input v-model="workDefinitionDraft.businessWeekdays" type="checkbox" :value="day.value">
													<span>{{ day.label }}</span>
												</label>
											</div>
										</template>
										<template v-if="workDefinitionDraft.scheduleMode === 'meter' || workDefinitionDraft.scheduleMode === 'meter_calendar'">
											<select v-model="workDefinitionDraft.meterUuid" required @change="syncWorkMeterUnit(asset.uuid)">
												<option value="" disabled>
													Schedule meter
												</option>
												<option v-for="meter in meters[asset.uuid] ?? []" :key="meter.uuid" :value="meter.uuid">
													{{ meter.name }}
												</option>
											</select>
											<input
												v-model="workDefinitionDraft.meterValue"
												inputmode="decimal"
												placeholder="Interval"
												required>
											<input v-model="workDefinitionDraft.meterUnit" placeholder="unit" required>
										</template>
										<button type="submit">
											Add work definition
										</button>
									</form>
								</section>
								<section class="activity-section">
									<h4>Maintenance activity</h4>
									<p class="panel-copy">
										Record what actually happened. Work items and meter snapshots are immutable ledger facts.
									</p>
									<ul class="compact-list">
										<li v-for="activity in activities[asset.uuid] ?? []" :key="activity.uuid">
											<strong>{{ activity.summary || activity.items[0]?.title || 'Maintenance activity' }}</strong>
											<span>{{ new Date(activity.performedAt).toLocaleString() }}</span>
											<span v-if="activity.items[0]?.componentName">{{ activity.items[0]?.componentName }}</span>
										</li>
									</ul>
									<form class="activity-form" @submit.prevent="submitActivity(asset)">
										<select v-model="activityDraft.definitionUuid">
											<option value="">
												Ad-hoc work
											</option>
											<option v-for="definition in workDefinitions[asset.uuid] ?? []" :key="definition.uuid" :value="definition.uuid">
												{{ definition.title }}
											</option>
										</select>
										<input
											v-if="activityDraft.definitionUuid === ''"
											v-model="activityDraft.title"
											placeholder="Ad-hoc work title"
											required>
										<input v-model="activityDraft.performedAt" type="datetime-local" required>
										<input v-model="activityDraft.summary" placeholder="Summary (optional)">
										<select v-model="activityDraft.meterUuid">
											<option value="">
												No meter reading
											</option><option v-for="meter in meters[asset.uuid] ?? []" :key="meter.uuid" :value="meter.uuid">
												{{ meter.name }}
											</option>
										</select>
										<input v-model="activityDraft.meterValue" placeholder="Meter value (optional)">
										<input v-model="activityDraft.meterUnit" placeholder="unit">
										<button type="submit">
											Record activity
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

.inline-form, .spec-form, .meter-form, .reading-form, .work-group-form, .work-definition-form, .activity-form, .reminder-policy-form { display: grid; gap: 8px; }

.relationship-form, .assignment-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: end; gap: 10px; }

.panel-copy { color: var(--color-text-maxcontrast); }

.profile-section textarea { width: 100%; min-height: 150px; font-family: monospace; }

.profile-installed, .profile-preview { display: grid; gap: 6px; margin: 10px 0; }

.profile-installed code { overflow-wrap: anywhere; color: var(--color-text-maxcontrast); }

.button-row { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }

.profile-validation { overflow-wrap: anywhere; color: var(--color-text-maxcontrast); }

.relationship-list li { align-items: baseline; }

.check-field { display: flex; align-items: center; gap: 8px; min-height: 42px; }

.check-field input { min-height: auto; }

.inline-form { grid-template-columns: 1.4fr 1fr 1.2fr auto; }

.spec-form { grid-template-columns: repeat(3, minmax(0, 1fr)); }

.profile-section, .meter-section, .status-section, .occurrence-section, .work-section, .activity-section { grid-column: 1 / -1; padding-top: 14px; border-top: 1px solid var(--color-border); }

.meter-form { grid-template-columns: repeat(3, minmax(0, 1fr)) repeat(2, minmax(120px, .7fr)) auto auto; align-items: end; }

.reading-form { grid-template-columns: 1.5fr 1fr .6fr 1.2fr auto; align-items: end; margin-top: 10px; }

.work-group-form { grid-template-columns: 1fr 1.4fr 2fr .7fr auto; margin-bottom: 10px; }

.status-line { display: flex; align-items: center; justify-content: space-between; gap: 10px; }

.status-chip { padding: 2px 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius-pill); font-size: .78rem; font-weight: 700; }

.status-chip[data-state="due"], .status-chip[data-state="overdue"] { border-color: var(--color-error); color: var(--color-error); }

.status-chip[data-state="baseline_required"], .status-chip[data-state="unknown"], .status-chip[data-state="due_soon"] { border-color: var(--color-warning); color: var(--color-warning); }

.work-definition-form { grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: end; }

.activity-form { grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: end; }

.reminder-policy-form { grid-template-columns: repeat(3, minmax(0, 1fr)) auto; align-items: end; }

.policy-source, .empty-inline { color: var(--color-text-maxcontrast); font-size: .9rem; }

.section-heading--compact { align-items: end; margin-bottom: 8px; }

.weekday-picker { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; min-height: 42px; padding: 6px 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius); }

.weekday-option { display: flex; grid-template-columns: none; align-items: center; gap: 4px; font-weight: 500; }

.weekday-option input { min-height: auto; }

.spec-form button { grid-column: 3; }

.empty-state { display: grid; place-items: center; min-height: 130px; color: var(--color-text-maxcontrast); }
@media (max-width: 900px) { .form-grid, .form-grid--category, .asset-details { grid-template-columns: 1fr 1fr; }.inline-form, .spec-form, .relationship-form, .assignment-form, .meter-form, .reading-form, .work-group-form, .work-definition-form, .activity-form, .reminder-policy-form { grid-template-columns: 1fr 1fr; }.spec-form button { grid-column: auto; } }
@media (max-width: 600px) { .page-shell { width: min(100% - 20px, 1180px); padding-top: 22px; }.page-header, .asset-summary, .form-grid, .form-grid--category, .asset-details, .inline-form, .spec-form, .relationship-form, .assignment-form, .meter-form, .reading-form, .work-group-form, .work-definition-form, .activity-form, .reminder-policy-form { display: grid; grid-template-columns: 1fr; } }
</style>
