/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Evidence, EvidenceFolder, EvidenceKind, EvidenceTargetType, UploadTarget } from '../evidenceTypes.ts'
import type { OcsEnvelope } from './api.ts'

import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateRemoteUrl } from '@nextcloud/router'
import { t } from '../l10n.ts'
import { data, endpoint, requestOptions } from './api.ts'

export async function listEvidence(targetType: EvidenceTargetType, targetUuid: string): Promise<Evidence[]> {
	const query = `targetType=${encodeURIComponent(targetType)}&targetUuid=${encodeURIComponent(targetUuid)}`
	return (await data(await axios.get<OcsEnvelope<{ items: Evidence[] }>>(endpoint(`/evidence?${query}`), requestOptions))).items
}

export async function attachEvidence(input: { uuid: string, kind: EvidenceKind, title?: string, path: string, targetType: EvidenceTargetType, targetUuid: string }): Promise<Evidence> {
	const evidence = { uuid: input.uuid, kind: input.kind, title: input.title || undefined, path: input.path, links: [{ targetType: input.targetType, targetUuid: input.targetUuid }] }
	return data(await axios.post<OcsEnvelope<Evidence>>(endpoint('/evidence'), { evidence }, requestOptions))
}

export async function archiveEvidence(uuid: string, expectedRevision: number): Promise<Evidence> {
	return data(await axios.delete<OcsEnvelope<Evidence>>(endpoint(`/evidence/${encodeURIComponent(uuid)}`), { ...requestOptions, data: { expectedRevision } }))
}

export async function getEvidenceFolder(): Promise<EvidenceFolder> {
	return data(await axios.get<OcsEnvelope<EvidenceFolder>>(endpoint('/evidence-folder'), requestOptions))
}

export async function getUploadTarget(targetType: EvidenceTargetType, targetUuid: string, fileName: string): Promise<UploadTarget> {
	return data(await axios.post<OcsEnvelope<UploadTarget>>(endpoint('/evidence-folder/upload-target'), { target: { targetType, targetUuid, fileName } }, requestOptions))
}

/**
 * Upload bytes through Nextcloud WebDAV into the app-managed folder so Files
 * enforces permissions, quota, and versioning. Returns the stored path.
 *
 * @param path Destination path in the current user's Files view.
 * @param file Browser file to upload.
 */
export async function uploadToFiles(path: string, file: File): Promise<string> {
	const user = getCurrentUser()
	if (user === null) {
		throw new Error(t('maintenance_tracker', 'Not signed in'))
	}
	const encoded = path.split('/').map(encodeURIComponent).join('/')
	await axios.put(generateRemoteUrl(`dav/files/${encodeURIComponent(user.uid)}${encoded}`), file, {
		headers: { 'Content-Type': file.type || 'application/octet-stream', 'If-None-Match': '*' },
	})
	return path
}
