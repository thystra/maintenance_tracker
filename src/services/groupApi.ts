/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ItemGroup, ItemGroupDeleted, ItemGroupList } from '../groupTypes.ts'
import type { OcsEnvelope } from './api.ts'

import axios from '@nextcloud/axios'
import { data, endpoint, requestOptions } from './api.ts'

export async function getItemGroups(): Promise<ItemGroupList> {
	return data(await axios.get<OcsEnvelope<ItemGroupList>>(endpoint('/item-groups'), requestOptions))
}

export async function createItemGroup(group: { uuid: string, name: string, parentUuid: string | null }): Promise<ItemGroup> {
	return data(await axios.post<OcsEnvelope<ItemGroup>>(endpoint('/item-groups'), { group }, requestOptions))
}

export async function renameItemGroup(uuid: string, expectedRevision: number, name: string): Promise<ItemGroup> {
	return data(await axios.patch<OcsEnvelope<ItemGroup>>(endpoint(`/item-groups/${encodeURIComponent(uuid)}`), { expectedRevision, group: { name } }, requestOptions))
}

export async function moveItemGroup(uuid: string, expectedRevision: number, parentUuid: string | null): Promise<ItemGroup> {
	return data(await axios.post<OcsEnvelope<ItemGroup>>(endpoint(`/item-groups/${encodeURIComponent(uuid)}/move`), { expectedRevision, parentUuid }, requestOptions))
}

export async function deleteItemGroup(uuid: string, expectedRevision: number): Promise<ItemGroupDeleted> {
	return data(await axios.delete<OcsEnvelope<ItemGroupDeleted>>(endpoint(`/item-groups/${encodeURIComponent(uuid)}`), { ...requestOptions, params: { expectedRevision } }))
}

export async function setItemGroup(assetUuid: string, groupUuid: string | null): Promise<{ assetUuid: string, groupUuid: string | null }> {
	return data(await axios.put<OcsEnvelope<{ assetUuid: string, groupUuid: string | null }>>(endpoint(`/assets/${encodeURIComponent(assetUuid)}/group`), { groupUuid }, requestOptions))
}
