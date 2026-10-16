/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type EvidenceKind = 'photo' | 'video' | 'receipt' | 'invoice' | 'document' | 'other'
export type EvidenceTargetType = 'asset' | 'activity' | 'cost'

export interface EvidenceFile {
	fileId: number
	name: string
	/** Path in the viewer's own Files view. */
	path: string
	currentSize: number
	changed: boolean
	filesUrl: string
	downloadUrl: string
	previewUrl: string | null
}

export interface Evidence {
	uuid: string
	kind: EvidenceKind
	title: string
	mime: string
	size: number
	sha256: string | null
	storage: 'workspace' | 'linked'
	attachedBy: string
	notes: string | null
	links: { targetType: EvidenceTargetType, targetUuid: string }[]
	revision: number
	createdAt: string
	updatedAt: string
	archived: boolean
	canChange: boolean
	/** available: openable by you; not_shared: exists but not shared with you; missing: deleted or unreachable. */
	availability: 'available' | 'not_shared' | 'missing'
	file: EvidenceFile | null
}

export interface EvidenceFolder {
	exists?: boolean
	available: boolean
	path: string | null
	writable: boolean
	ownerUid: string
	freeBytes: number | null
	quotaLimited: boolean
	reason: string | null
}

export interface UploadTarget extends EvidenceFolder {
	fileName: string
	uploadPath: string | null
}
