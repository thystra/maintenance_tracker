/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type {
	Asset,
	AssetList,
	Capabilities,
	CreateAsset,
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
	return data(await axios.get<OcsEnvelope<Capabilities>>(
		endpoint('/capabilities'),
		requestOptions,
	))
}

export async function getAssets(cursor: string | null = null): Promise<AssetList> {
	return data(await axios.get<OcsEnvelope<AssetList>>(
		endpoint('/assets'),
		{
			...requestOptions,
			params: cursor === null ? undefined : { cursor },
		},
	))
}

export async function createAsset(asset: CreateAsset): Promise<Asset> {
	return data(await axios.post<OcsEnvelope<Asset>>(
		endpoint('/assets'),
		{ asset },
		requestOptions,
	))
}
