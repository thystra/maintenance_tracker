/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ProfileDocumentV2 } from '../profileTypes.ts'
import type { BundledProfile, ProfileDocument } from '../types.ts'
import type { OcsEnvelope } from './api.ts'

import axios from '@nextcloud/axios'
import { data, endpoint, requestOptions } from './api.ts'

const library = (uuid: string): string => endpoint(`/profile-library/${encodeURIComponent(uuid)}`)

export async function getWorkspaceProfile(uuid: string): Promise<BundledProfile> {
	return data(await axios.get<OcsEnvelope<BundledProfile>>(library(uuid), requestOptions))
}

export async function createWorkspaceProfile(uuid: string, profile: ProfileDocumentV2 | ProfileDocument): Promise<BundledProfile> {
	return data(await axios.post<OcsEnvelope<BundledProfile>>(endpoint('/profile-library'), { uuid, profile }, requestOptions))
}

export async function updateWorkspaceProfile(uuid: string, expectedRevision: number, profile: ProfileDocumentV2): Promise<BundledProfile> {
	return data(await axios.put<OcsEnvelope<BundledProfile>>(library(uuid), { expectedRevision, profile }, requestOptions))
}

export async function deleteWorkspaceProfile(uuid: string, expectedRevision: number): Promise<{ uuid: string, archived: boolean }> {
	return data(await axios.delete<OcsEnvelope<{ uuid: string, archived: boolean }>>(library(uuid), { ...requestOptions, params: { expectedRevision } }))
}

export async function saveAssetAsProfile(assetUuid: string, input: { uuid: string, name: string, id?: string, version?: string }): Promise<BundledProfile> {
	return data(await axios.post<OcsEnvelope<BundledProfile>>(endpoint(`/assets/${encodeURIComponent(assetUuid)}/save-as-profile`), input, requestOptions))
}
