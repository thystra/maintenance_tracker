/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FuelOdometerLink, FuelPeriod, FuelRegime, FuelSettings, FuelStats, FuelSummaryItem, FuelTicket, FuelTicketInput, FuelTicketList, FuelVehicle } from '../fuelTypes.ts'
import type { OcsEnvelope } from './api.ts'

import axios from '@nextcloud/axios'
import { data, endpoint, requestOptions } from './api.ts'

const asset = (uuid: string, path: string): string => endpoint(`/assets/${encodeURIComponent(uuid)}/${path}`)

export async function getFuelSettings(assetUuid: string): Promise<FuelSettings> {
	return data(await axios.get<OcsEnvelope<FuelSettings>>(asset(assetUuid, 'fuel-settings'), requestOptions))
}

export async function updateFuelSettings(assetUuid: string, expectedRevision: number, settings: Partial<Pick<FuelSettings, 'fuelTab' | 'distanceUnit' | 'volumeUnit' | 'economyUnit' | 'fuelType'>>): Promise<FuelSettings> {
	return data(await axios.put<OcsEnvelope<FuelSettings>>(asset(assetUuid, 'fuel-settings'), { expectedRevision, settings }, requestOptions))
}

export async function createFuelRegime(assetUuid: string, regime: { uuid: string, name: string, isDefault?: boolean }): Promise<FuelRegime> {
	return data(await axios.post<OcsEnvelope<FuelRegime>>(asset(assetUuid, 'fuel-regimes'), { regime }, requestOptions))
}

export async function updateFuelRegime(uuid: string, expectedRevision: number, regime: { name?: string, isDefault?: boolean }): Promise<FuelRegime> {
	return data(await axios.patch<OcsEnvelope<FuelRegime>>(endpoint(`/fuel-regimes/${encodeURIComponent(uuid)}`), { expectedRevision, regime }, requestOptions))
}

export async function deleteFuelRegime(uuid: string, expectedRevision: number): Promise<{ uuid: string, tickets: number }> {
	return data(await axios.delete<OcsEnvelope<{ uuid: string, tickets: number }>>(endpoint(`/fuel-regimes/${encodeURIComponent(uuid)}`), { ...requestOptions, params: { expectedRevision } }))
}

export async function getFuelTickets(assetUuid: string): Promise<FuelTicketList> {
	return data(await axios.get<OcsEnvelope<FuelTicketList>>(asset(assetUuid, 'fuel'), requestOptions))
}

export async function createFuelTicket(assetUuid: string, ticket: FuelTicketInput): Promise<FuelTicket> {
	return data(await axios.post<OcsEnvelope<FuelTicket>>(asset(assetUuid, 'fuel'), { ticket }, requestOptions))
}

export async function updateFuelTicket(uuid: string, expectedRevision: number, ticket: FuelTicketInput): Promise<FuelTicket> {
	return data(await axios.patch<OcsEnvelope<FuelTicket>>(endpoint(`/fuel/${encodeURIComponent(uuid)}`), { expectedRevision, ticket }, requestOptions))
}

export async function deleteFuelTicket(uuid: string, expectedRevision: number): Promise<FuelTicket> {
	return data(await axios.delete<OcsEnvelope<FuelTicket>>(endpoint(`/fuel/${encodeURIComponent(uuid)}`), { ...requestOptions, params: { expectedRevision } }))
}

export async function getFuelStats(assetUuid: string, period: FuelPeriod, unit: string | null, from?: string, to?: string): Promise<FuelStats> {
	return data(await axios.get<OcsEnvelope<FuelStats>>(asset(assetUuid, 'fuel-stats'), { ...requestOptions, params: { period, unit: unit ?? undefined, from, to } }))
}

export async function getFuelSummary(): Promise<{ items: FuelSummaryItem[] }> {
	return data(await axios.get<OcsEnvelope<{ items: FuelSummaryItem[] }>>(endpoint('/fuel-summary'), requestOptions))
}

export async function getFuelVehicles(): Promise<{ items: FuelVehicle[] }> {
	return data(await axios.get<OcsEnvelope<{ items: FuelVehicle[] }>>(endpoint('/fuel-vehicles'), requestOptions))
}

export async function linkFuelOdometer(assetUuid: string): Promise<FuelOdometerLink> {
	return data(await axios.post<OcsEnvelope<FuelOdometerLink>>(asset(assetUuid, 'fuel-odometer'), {}, requestOptions))
}
