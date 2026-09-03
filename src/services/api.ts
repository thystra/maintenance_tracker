/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type {
	Asset,
	AssetList,
	Capabilities,
	Category,
	CategoryList,
	Component,
	ComponentList,
	CreateAsset,
	CreateCategory,
	CreateComponent,
	CreateSpecification,
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
