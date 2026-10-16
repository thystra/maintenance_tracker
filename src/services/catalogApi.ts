/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type {
	CreatePart,
	CreateVendor,
	Part,
	PartList,
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
