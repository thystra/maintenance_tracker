/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OwnershipTransfer, WorkspaceMemberSummary, WorkspaceSummary } from '../workspaceTypes.ts'
import type { OcsEnvelope } from './api.ts'

import axios from '@nextcloud/axios'
import { data, endpoint, requestOptions } from './api.ts'

/**
 * Human-readable OCS failure message, falling back to the HTTP error.
 *
 * @param reason The caught request error.
 */
export function ocsMessage(reason: unknown): string {
	const message = (reason as { response?: { data?: OcsEnvelope<unknown> } })?.response?.data?.ocs?.meta?.message
	if (typeof message === 'string' && message !== '') {
		return message
	}
	return reason instanceof Error && reason.message !== '' ? reason.message : 'The request could not be completed.'
}

export async function listWorkspaces(): Promise<WorkspaceSummary[]> {
	return (await data(await axios.get<OcsEnvelope<{ items: WorkspaceSummary[] }>>(endpoint('/workspaces'), requestOptions))).items
}

export async function listMembers(workspace: string): Promise<WorkspaceMemberSummary[]> {
	return (await data(await axios.get<OcsEnvelope<{ items: WorkspaceMemberSummary[] }>>(endpoint(`/workspaces/${encodeURIComponent(workspace)}/members`), requestOptions))).items
}

export async function listTransfers(): Promise<{ incoming: OwnershipTransfer[], outgoing: OwnershipTransfer[] }> {
	return data(await axios.get<OcsEnvelope<{ incoming: OwnershipTransfer[], outgoing: OwnershipTransfer[] }>>(endpoint('/ownership-transfers'), requestOptions))
}

export async function offerTransfer(workspace: string, transfer: { toUid: string, leaveAfter: boolean, expiresInDays: number }): Promise<OwnershipTransfer> {
	return data(await axios.post<OcsEnvelope<OwnershipTransfer>>(endpoint(`/workspaces/${encodeURIComponent(workspace)}/ownership-transfers`), { transfer }, requestOptions))
}

export async function acceptTransfer(uuid: string): Promise<OwnershipTransfer> {
	return data(await axios.post<OcsEnvelope<OwnershipTransfer>>(endpoint(`/ownership-transfers/${encodeURIComponent(uuid)}/accept`), { acknowledgeQuota: true }, requestOptions))
}

export async function transferAction(uuid: string, action: 'decline' | 'resume' | 'abort' | 'cancel'): Promise<OwnershipTransfer> {
	const url = endpoint(`/ownership-transfers/${encodeURIComponent(uuid)}${action === 'cancel' ? '' : `/${action}`}`)
	const response = action === 'cancel'
		? await axios.delete<OcsEnvelope<OwnershipTransfer>>(url, requestOptions)
		: await axios.post<OcsEnvelope<OwnershipTransfer>>(url, {}, requestOptions)
	return data(response)
}
