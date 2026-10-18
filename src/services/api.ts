/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type {
	Activity,
	ActivityList,
	Asset,
	AssetList,
	Assignment,
	AssignmentList,
	AuditEntry,
	Capabilities,
	Category,
	CategoryList,
	Component,
	ComponentList,
	CreateActivity,
	CreateAsset,
	CreateAssignment,
	CreateCategory,
	CreateComponent,
	CreateMeter,
	CreateReading,
	CreateRelationship,
	CreateSpecification,
	CreateWorkDefinition,
	CreateWorkGroup,
	MaintenanceForecastList,
	MaintenanceOccurrenceList,
	MaintenanceStatusList,
	Meter,
	MeterList,
	ProfileCatalogList,
	ProfileDocument,
	ProfileInstallation,
	ProfileInstallationEnvelope,
	ProfilePreview,
	ProfileValidationResult,
	Reading,
	ReadingList,
	Relationship,
	RelationshipList,
	RelationshipTypeList,
	ReminderPolicy,
	Specification,
	SpecificationList,
	WorkDefinition,
	WorkDefinitionList,
	WorkGroup,
	WorkGroupList,
	WorkspaceForecast,
} from '../types.ts'

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export interface OcsEnvelope<T> {
	ocs: {
		meta: {
			status: string
			statuscode: number
			message: string
		}
		data: T
	}
}

export const requestOptions = {
	headers: {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	},
}

const WORKSPACE_KEY = 'maintenance_tracker.workspace'

/** Workspace selected in the switcher; null means the personal workspace. */
export function currentWorkspace(): string | null {
	try {
		return window.localStorage.getItem(WORKSPACE_KEY)
	} catch {
		return null
	}
}

export function selectWorkspace(uuid: string | null): void {
	try {
		if (uuid === null) {
			window.localStorage.removeItem(WORKSPACE_KEY)
		} else {
			window.localStorage.setItem(WORKSPACE_KEY, uuid)
		}
	} catch {
		// Storage unavailable: stay on the personal workspace.
	}
}

/**
 * OCS URL for an app endpoint. Workspace-scoped endpoints carry the selected
 * workspace; account-level endpoints (workspaces, transfers, capabilities) do not.
 *
 * @param path App API path starting with a slash.
 */
export function endpoint(path: string): string {
	const workspace = currentWorkspace()
	const global = /^\/(workspaces|ownership-transfers|integrations|capabilities)(\/|\?|$)/.test(path)
	if (workspace !== null && !global && !/[?&]workspace=/.test(path)) {
		path += `${path.includes('?') ? '&' : '?'}workspace=${encodeURIComponent(workspace)}`
	}
	return generateOcsUrl(`/apps/maintenance_tracker/api/v1${path}`)
}

export function data<T>(response: { data: OcsEnvelope<T> }): T {
	return response.data.ocs.data
}

export async function getCapabilities(): Promise<Capabilities> {
	return data(await axios.get<OcsEnvelope<Capabilities>>(endpoint('/capabilities'), requestOptions))
}

export async function getAssets(cursor: string | null = null): Promise<AssetList> {
	return data(await axios.get<OcsEnvelope<AssetList>>(endpoint('/assets'), {
		...requestOptions,
		params: cursor === null ? undefined : { cursor },
	}))
}

export async function createAsset(asset: CreateAsset): Promise<Asset> {
	return data(await axios.post<OcsEnvelope<Asset>>(endpoint('/assets'), { asset }, requestOptions))
}

export async function getCategories(): Promise<CategoryList> {
	return data(await axios.get<OcsEnvelope<CategoryList>>(endpoint('/categories'), requestOptions))
}

export async function createCategory(category: CreateCategory): Promise<Category> {
	return data(await axios.post<OcsEnvelope<Category>>(endpoint('/categories'), { category }, requestOptions))
}

export async function getComponents(assetUuid: string): Promise<ComponentList> {
	return data(await axios.get<OcsEnvelope<ComponentList>>(endpoint(`/assets/${assetUuid}/components`), requestOptions))
}

export async function createComponent(assetUuid: string, component: CreateComponent): Promise<Component> {
	return data(await axios.post<OcsEnvelope<Component>>(endpoint(`/assets/${assetUuid}/components`), { component }, requestOptions))
}

export async function getSpecifications(assetUuid: string): Promise<SpecificationList> {
	return data(await axios.get<OcsEnvelope<SpecificationList>>(endpoint(`/assets/${assetUuid}/specifications`), requestOptions))
}

export async function createSpecification(assetUuid: string, specification: CreateSpecification): Promise<Specification> {
	return data(await axios.post<OcsEnvelope<Specification>>(endpoint(`/assets/${assetUuid}/specifications`), { specification }, requestOptions))
}

export async function getRelationshipTypes(): Promise<RelationshipTypeList> {
	return data(await axios.get<OcsEnvelope<RelationshipTypeList>>(endpoint('/relationship-types'), requestOptions))
}

export async function getRelationships(): Promise<RelationshipList> {
	return data(await axios.get<OcsEnvelope<RelationshipList>>(endpoint('/relationships'), requestOptions))
}

export async function createRelationship(relationship: CreateRelationship): Promise<Relationship> {
	return data(await axios.post<OcsEnvelope<Relationship>>(endpoint('/relationships'), { relationship }, requestOptions))
}

export async function updateRelationship(uuid: string, expectedRevision: number, relationship: Partial<Pick<CreateRelationship, 'context' | 'isDefault' | 'notes'>>): Promise<Relationship> {
	return data(await axios.patch<OcsEnvelope<Relationship>>(endpoint(`/relationships/${uuid}`), { expectedRevision, relationship }, requestOptions))
}

export async function archiveRelationship(uuid: string, expectedRevision: number): Promise<Relationship> {
	return data(await axios.delete<OcsEnvelope<Relationship>>(endpoint(`/relationships/${uuid}`), {
		...requestOptions,
		data: { expectedRevision },
	}))
}

export async function getAssignments(): Promise<AssignmentList> {
	return data(await axios.get<OcsEnvelope<AssignmentList>>(endpoint('/assignments'), requestOptions))
}

export async function createAssignment(assignment: CreateAssignment): Promise<Assignment> {
	return data(await axios.post<OcsEnvelope<Assignment>>(endpoint('/assignments'), { assignment }, requestOptions))
}

export async function updateAssignment(uuid: string, expectedRevision: number, assignment: Partial<Pick<CreateAssignment, 'context' | 'isPrimary' | 'effectiveFrom' | 'effectiveUntil' | 'notes'>>): Promise<Assignment> {
	return data(await axios.patch<OcsEnvelope<Assignment>>(endpoint(`/assignments/${uuid}`), { expectedRevision, assignment }, requestOptions))
}

export async function archiveAssignment(uuid: string, expectedRevision: number): Promise<Assignment> {
	return data(await axios.delete<OcsEnvelope<Assignment>>(endpoint(`/assignments/${uuid}`), {
		...requestOptions,
		data: { expectedRevision },
	}))
}

export async function getMeters(assetUuid: string): Promise<MeterList> {
	return data(await axios.get<OcsEnvelope<MeterList>>(endpoint(`/assets/${assetUuid}/meters`), requestOptions))
}

export async function createMeter(assetUuid: string, meter: CreateMeter): Promise<Meter> {
	return data(await axios.post<OcsEnvelope<Meter>>(endpoint(`/assets/${assetUuid}/meters`), { meter }, requestOptions))
}

export async function getReadings(meterUuid: string): Promise<ReadingList> {
	return data(await axios.get<OcsEnvelope<ReadingList>>(endpoint(`/meters/${meterUuid}/readings`), requestOptions))
}

export async function createReading(meterUuid: string, reading: CreateReading): Promise<Reading> {
	return data(await axios.post<OcsEnvelope<Reading>>(endpoint(`/meters/${meterUuid}/readings`), { reading }, requestOptions))
}

export async function getWorkGroups(assetUuid: string): Promise<WorkGroupList> {
	return data(await axios.get<OcsEnvelope<WorkGroupList>>(endpoint(`/assets/${assetUuid}/work-groups`), requestOptions))
}

export async function createWorkGroup(assetUuid: string, group: CreateWorkGroup): Promise<WorkGroup> {
	return data(await axios.post<OcsEnvelope<WorkGroup>>(endpoint(`/assets/${assetUuid}/work-groups`), { group }, requestOptions))
}

export async function getWorkDefinitions(assetUuid: string, scheduled: boolean | null = null): Promise<WorkDefinitionList> {
	return data(await axios.get<OcsEnvelope<WorkDefinitionList>>(endpoint(`/assets/${assetUuid}/work-definitions`), {
		...requestOptions,
		params: scheduled === null ? undefined : { scheduled: scheduled ? 'true' : 'false' },
	}))
}

export async function createWorkDefinition(assetUuid: string, definition: CreateWorkDefinition): Promise<WorkDefinition> {
	return data(await axios.post<OcsEnvelope<WorkDefinition>>(endpoint(`/assets/${assetUuid}/work-definitions`), { definition }, requestOptions))
}

export async function getMaintenanceStatus(assetUuid: string, asOf: string | null = null): Promise<MaintenanceStatusList> {
	return data(await axios.get<OcsEnvelope<MaintenanceStatusList>>(endpoint(`/assets/${assetUuid}/maintenance-status`), {
		...requestOptions,
		params: asOf === null ? undefined : { asOf },
	}))
}

export async function getActivities(assetUuid: string): Promise<ActivityList> {
	return data(await axios.get<OcsEnvelope<ActivityList>>(endpoint(`/assets/${assetUuid}/activities`), requestOptions))
}

export async function createActivity(assetUuid: string, activity: CreateActivity): Promise<Activity> {
	return data(await axios.post<OcsEnvelope<Activity>>(endpoint(`/assets/${assetUuid}/activities`), { activity }, requestOptions))
}

export async function getMaintenanceForecast(assetUuid: string, asOf: string | null = null): Promise<MaintenanceForecastList> {
	return data(await axios.get<OcsEnvelope<MaintenanceForecastList>>(endpoint(`/assets/${assetUuid}/maintenance-forecast`), {
		...requestOptions,
		params: asOf === null ? undefined : { asOf },
	}))
}

export async function getMaintenanceOccurrences(assetUuid: string, includeClosed = false, asOf: string | null = null): Promise<MaintenanceOccurrenceList> {
	return data(await axios.get<OcsEnvelope<MaintenanceOccurrenceList>>(endpoint(`/assets/${assetUuid}/maintenance-occurrences`), {
		...requestOptions,
		params: { ...(includeClosed ? { includeClosed: 'true' } : {}), ...(asOf === null ? {} : { asOf }) },
	}))
}

export async function reconcileMaintenanceOccurrences(assetUuid: string, asOf: string | null = null): Promise<MaintenanceOccurrenceList> {
	return data(await axios.post<OcsEnvelope<MaintenanceOccurrenceList>>(endpoint(`/assets/${assetUuid}/maintenance-occurrences/reconcile`), null, {
		...requestOptions,
		params: asOf === null ? undefined : { asOf },
	}))
}

export async function getReminderPolicy(): Promise<ReminderPolicy> {
	return data(await axios.get<OcsEnvelope<ReminderPolicy>>(endpoint('/reminder-policy'), requestOptions))
}

export async function updateReminderPolicy(expectedRevision: number, policy: Partial<Pick<ReminderPolicy, 'calendarLeadDays' | 'meterLeadPercent'>>): Promise<ReminderPolicy> {
	return data(await axios.patch<OcsEnvelope<ReminderPolicy>>(endpoint('/reminder-policy'), { expectedRevision, policy }, requestOptions))
}

export async function getProfiles(): Promise<ProfileCatalogList> {
	return data(await axios.get<OcsEnvelope<ProfileCatalogList>>(endpoint('/profiles'), requestOptions))
}

export async function validateProfile(profile: ProfileDocument): Promise<ProfileValidationResult> {
	return data(await axios.post<OcsEnvelope<ProfileValidationResult>>(endpoint('/profiles/validate'), { profile }, requestOptions))
}

export async function getProfileInstallation(assetUuid: string): Promise<ProfileInstallationEnvelope> {
	return data(await axios.get<OcsEnvelope<ProfileInstallationEnvelope>>(endpoint(`/assets/${assetUuid}/profile-installation`), requestOptions))
}

export async function previewProfile(assetUuid: string, profile: ProfileDocument): Promise<ProfilePreview> {
	return data(await axios.post<OcsEnvelope<ProfilePreview>>(endpoint(`/assets/${assetUuid}/profiles/preview`), { profile }, requestOptions))
}

export async function installProfile(assetUuid: string, installationUuid: string, profile: ProfileDocument): Promise<ProfileInstallation> {
	return data(await axios.post<OcsEnvelope<ProfileInstallation>>(endpoint(`/assets/${assetUuid}/profiles/install`), { installationUuid, profile }, requestOptions))
}

export async function getAsset(uuid: string): Promise<Asset> {
	return data(await axios.get<OcsEnvelope<Asset>>(endpoint(`/assets/${encodeURIComponent(uuid)}`), requestOptions))
}

export async function updateAsset(uuid: string, expectedRevision: number, asset: Partial<CreateAsset>): Promise<Asset> {
	return data(await axios.patch<OcsEnvelope<Asset>>(endpoint(`/assets/${encodeURIComponent(uuid)}`), { expectedRevision, asset }, requestOptions))
}

/**
 * Soft-deletes the item (kept as a tombstone for sync and audit).
 *
 * @param uuid Item UUID.
 * @param expectedRevision Revision the caller last read.
 */
export async function deleteAsset(uuid: string, expectedRevision: number): Promise<Asset> {
	return data(await axios.delete<OcsEnvelope<Asset>>(endpoint(`/assets/${encodeURIComponent(uuid)}`), { ...requestOptions, params: { expectedRevision } }))
}

export async function updateWorkDefinition(uuid: string, expectedRevision: number, definition: Partial<CreateWorkDefinition>): Promise<WorkDefinition> {
	return data(await axios.patch<OcsEnvelope<WorkDefinition>>(endpoint(`/work-definitions/${encodeURIComponent(uuid)}`), { expectedRevision, definition }, requestOptions))
}

export async function archiveWorkDefinition(uuid: string, expectedRevision: number): Promise<WorkDefinition> {
	return data(await axios.delete<OcsEnvelope<WorkDefinition>>(endpoint(`/work-definitions/${encodeURIComponent(uuid)}`), { ...requestOptions, params: { expectedRevision } }))
}

export async function archiveActivity(uuid: string, expectedRevision: number): Promise<Activity> {
	return data(await axios.delete<OcsEnvelope<Activity>>(endpoint(`/activities/${encodeURIComponent(uuid)}`), { ...requestOptions, params: { expectedRevision } }))
}

export async function archiveMeter(uuid: string, expectedRevision: number): Promise<Meter> {
	return data(await axios.delete<OcsEnvelope<Meter>>(endpoint(`/meters/${encodeURIComponent(uuid)}`), { ...requestOptions, params: { expectedRevision } }))
}

export async function getWorkspaceForecast(): Promise<WorkspaceForecast> {
	return data(await axios.get<OcsEnvelope<WorkspaceForecast>>(endpoint('/maintenance-forecast'), requestOptions))
}

export async function getAudit(limit = 15): Promise<AuditEntry[]> {
	return (data(await axios.get<OcsEnvelope<{ items: AuditEntry[] }>>(endpoint('/audit'), { ...requestOptions, params: { limit } }))).items
}
