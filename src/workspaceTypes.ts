/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type WorkspaceRole = 'owner' | 'manager' | 'contributor' | 'viewer'

export interface WorkspaceSummary {
	uuid: string
	name: string
	kind: string
	role: WorkspaceRole
	ownerUid: string
	orphaned: boolean
}

export interface WorkspaceMemberSummary {
	userUid: string
	role: WorkspaceRole
	createdAt: string
}

export interface TransferQuota {
	folderBytes: number
	/** Only present for the recipient. */
	recipientFreeBytes?: number | null
	recipientQuotaLimited?: boolean
	fits?: boolean
}

export interface OwnershipTransfer {
	uuid: string
	fromUid: string
	toUid: string
	reason: 'owner_request' | 'account_deletion' | 'admin'
	status: 'pending' | 'in_progress' | 'completed' | 'cancelled' | 'declined' | 'expired' | 'aborted'
	step: string
	leaveAfter: boolean
	expiresAt: string
	createdAt: string
	finishedAt: string | null
	resumable: boolean
	abortable: boolean
	error: string | null
	workspace: { uuid: string, name: string } | null
	quota: TransferQuota | null
}
