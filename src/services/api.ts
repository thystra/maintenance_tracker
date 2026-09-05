/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type {
	Asset,
	AssetList,
	Assignment,
	AssignmentList,
	Capabilities,
	Category,
	CategoryList,
	Component,
	ComponentList,
	CreateAsset,
	CreateAssignment,
	CreateCategory,
	CreateComponent,
	CreateMeter,
	CreateReading,
	CreateRelationship,
	CreateSpecification,
	Meter,
	MeterList,
	Reading,
	ReadingList,
	Relationship,
	RelationshipList,
	RelationshipTypeList,
	Specification,
	SpecificationList,
} from '../types.ts'

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

interface OcsEnvelope<T> {
	ocs: {
		meta: {
			status: string
			statuscode: number
			message: string
		}
		data: T
	}
}

const requestOptions = {
	headers: {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	},
}

function endpoint(path: string): string {
	return generateOcsUrl(`/apps/maintenance_tracker/api/v1${path}`)
}

function data<T>(response: { data: OcsEnvelope<T> }): T {
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
