/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type {
	AssetCostList,
	CompatiblePartList,
	CostEntry,
	CostSettings,
	CreateCost,
	CreatePart,
	CreateVendor,
	LinkSubjectType,
	OutsideLink,
	OutsideLinkList,
	Part,
	PartList,
	UpdatePart,
	Vendor,
	VendorList,
} from '../catalogTypes.ts'
import type { OcsEnvelope } from './api.ts'

import axios from '@nextcloud/axios'
import { data, endpoint, requestOptions } from './api.ts'

export async function getParts(): Promise<PartList> {
	return data(await axios.get<OcsEnvelope<PartList>>(endpoint('/parts'), requestOptions))
}

export async function createPart(part: CreatePart): Promise<Part> {
	return data(await axios.post<OcsEnvelope<Part>>(endpoint('/parts'), { part }, requestOptions))
}

export async function archivePart(uuid: string, expectedRevision: number): Promise<Part> {
	return data(await axios.delete<OcsEnvelope<Part>>(endpoint(`/parts/${encodeURIComponent(uuid)}`), { ...requestOptions, data: { expectedRevision } }))
}

export async function getVendors(): Promise<VendorList> {
	return data(await axios.get<OcsEnvelope<VendorList>>(endpoint('/vendors'), requestOptions))
}

export async function createVendor(vendor: CreateVendor): Promise<Vendor> {
	return data(await axios.post<OcsEnvelope<Vendor>>(endpoint('/vendors'), { vendor }, requestOptions))
}

export async function archiveVendor(uuid: string, expectedRevision: number): Promise<Vendor> {
	return data(await axios.delete<OcsEnvelope<Vendor>>(endpoint(`/vendors/${encodeURIComponent(uuid)}`), { ...requestOptions, data: { expectedRevision } }))
}

export async function getCompatibleParts(assetUuid: string): Promise<CompatiblePartList> {
	return data(await axios.get<OcsEnvelope<CompatiblePartList>>(endpoint(`/assets/${encodeURIComponent(assetUuid)}/compatible-parts`), requestOptions))
}

export async function getAssetCosts(assetUuid: string): Promise<AssetCostList> {
	return data(await axios.get<OcsEnvelope<AssetCostList>>(endpoint(`/assets/${encodeURIComponent(assetUuid)}/costs`), requestOptions))
}

export async function createCost(assetUuid: string, cost: CreateCost): Promise<CostEntry> {
	return data(await axios.post<OcsEnvelope<CostEntry>>(endpoint(`/assets/${encodeURIComponent(assetUuid)}/costs`), { cost }, requestOptions))
}

export async function archiveCost(uuid: string, expectedRevision: number): Promise<CostEntry> {
	return data(await axios.delete<OcsEnvelope<CostEntry>>(endpoint(`/costs/${encodeURIComponent(uuid)}`), { ...requestOptions, data: { expectedRevision } }))
}

export async function getCostSettings(): Promise<CostSettings> {
	return data(await axios.get<OcsEnvelope<CostSettings>>(endpoint('/cost-settings'), requestOptions))
}

export async function updateCostSettings(expectedRevision: number, settings: Pick<CostSettings, 'defaultCurrency' | 'taxEnabled' | 'multiCurrency'>): Promise<CostSettings> {
	return data(await axios.patch<OcsEnvelope<CostSettings>>(endpoint('/cost-settings'), { expectedRevision, settings }, requestOptions))
}

function linkBase(subjectType: LinkSubjectType, subjectUuid: string): string {
	return `/${subjectType === 'part' ? 'parts' : 'vendors'}/${encodeURIComponent(subjectUuid)}/links`
}

export async function getLinks(subjectType: LinkSubjectType, subjectUuid: string): Promise<OutsideLinkList> {
	return data(await axios.get<OcsEnvelope<OutsideLinkList>>(endpoint(linkBase(subjectType, subjectUuid)), requestOptions))
}

export async function createLink(subjectType: LinkSubjectType, subjectUuid: string, link: { uuid: string, label: string, url: string }): Promise<OutsideLink> {
	return data(await axios.post<OcsEnvelope<OutsideLink>>(endpoint(linkBase(subjectType, subjectUuid)), { link }, requestOptions))
}

export async function archiveLink(subjectType: LinkSubjectType, subjectUuid: string, linkUuid: string, expectedRevision: number): Promise<OutsideLink> {
	return data(await axios.delete<OcsEnvelope<OutsideLink>>(endpoint(`${linkBase(subjectType, subjectUuid)}/${encodeURIComponent(linkUuid)}`), { ...requestOptions, data: { expectedRevision } }))
}

export async function updatePart(uuid: string, expectedRevision: number, part: UpdatePart): Promise<Part> {
	return data(await axios.patch<OcsEnvelope<Part>>(endpoint(`/parts/${encodeURIComponent(uuid)}`), { expectedRevision, part }, requestOptions))
}

export async function addPartFit(uuid: string, expectedRevision: number, assetUuid: string): Promise<Part> {
	return data(await axios.post<OcsEnvelope<Part>>(endpoint(`/parts/${encodeURIComponent(uuid)}/fits`), { expectedRevision, assetUuid }, requestOptions))
}

export async function removePartFit(uuid: string, expectedRevision: number, assetUuid: string): Promise<Part> {
	return data(await axios.delete<OcsEnvelope<Part>>(endpoint(`/parts/${encodeURIComponent(uuid)}/fits/${encodeURIComponent(assetUuid)}`), { ...requestOptions, params: { expectedRevision } }))
}

export async function updateVendor(uuid: string, expectedRevision: number, vendor: Omit<CreateVendor, 'uuid'>): Promise<Vendor> {
	return data(await axios.patch<OcsEnvelope<Vendor>>(endpoint(`/vendors/${encodeURIComponent(uuid)}`), { expectedRevision, vendor }, requestOptions))
}
