<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Evidence, EvidenceKind, EvidenceTargetType } from '../evidenceTypes.ts'

import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { onMounted, ref } from 'vue'
import { t } from '../l10n.ts'
import { archiveEvidence, attachEvidence, getUploadTarget, listEvidence, uploadToFiles } from '../services/evidenceApi.ts'

import '@nextcloud/dialogs/style.css'

const props = defineProps<{ targetType: EvidenceTargetType, targetUuid: string, compact?: boolean }>()

const kinds: EvidenceKind[] = ['photo', 'receipt', 'invoice', 'document', 'video', 'other']
const items = ref<Evidence[]>([])
const kind = ref<EvidenceKind>('receipt')
const error = ref('')
const busy = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const availabilityText: Record<Evidence['availability'], string> = {
	available: '',
	not_shared: t('maintenance_tracker', 'Not shared with you in Files'),
	missing: t('maintenance_tracker', 'File was deleted or is no longer reachable'),
}

/**
 * @param reason Thrown value.
 */
function readableError(reason: unknown): string {
	const status = (reason as { response?: { status?: number } })?.response?.status
	if (status === 507) {
		return t('maintenance_tracker', 'Not enough storage: uploads to the workspace folder count against the workspace owner\'s quota.')
	}
	if (status === 403) {
		return t('maintenance_tracker', 'You do not have permission to add files to the workspace folder.')
	}
	return reason instanceof Error && reason.message !== '' ? reason.message : t('maintenance_tracker', 'The request could not be completed.')
}

/**
 * Only same-origin relative URLs from the server are bound to href/src.
 *
 * @param url Server-provided URL.
 */
function safeUrl(url: string | null | undefined): string | undefined {
	return typeof url === 'string' && url.startsWith('/') && !url.startsWith('//') ? url : undefined
}

/**
 * @param bytes Size in bytes.
 */
function size(bytes: number): string {
	return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`
}

async function load(): Promise<void> {
	try {
		items.value = await listEvidence(props.targetType, props.targetUuid)
	} catch (reason) {
		error.value = readableError(reason)
	}
}

async function run(operation: () => Promise<unknown>): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		await operation()
		await load()
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		busy.value = false
	}
}

async function pickFromFiles(): Promise<void> {
	let path: string
	try {
		path = await getFilePickerBuilder(t('maintenance_tracker', 'Attach evidence from Files'))
			.setMultiSelect(false)
			.allowDirectories(false)
			.addButton({ label: t('maintenance_tracker', 'Attach'), variant: 'primary', callback: () => {} })
			.build()
			.pick()
	} catch {
		return
	}
	await run(() => attachEvidence({ uuid: crypto.randomUUID(), kind: kind.value, path, targetType: props.targetType, targetUuid: props.targetUuid }))
}

async function upload(event: Event): Promise<void> {
	const file = (event.target as HTMLInputElement).files?.[0]
	if (!file) {
		return
	}
	await run(async () => {
		const target = await getUploadTarget(props.targetType, props.targetUuid, file.name)
		if (!target.available || target.uploadPath === null) {
			throw new Error(target.reason ?? t('maintenance_tracker', 'The workspace evidence folder is not available to you.'))
		}
		if (!target.writable) {
			throw new Error(t('maintenance_tracker', 'Your role can view but not add files in the workspace folder.'))
		}
		if (target.quotaLimited && target.freeBytes !== null && file.size > target.freeBytes) {
			throw new Error(t('maintenance_tracker', 'Not enough storage left in the workspace owner\'s quota for this file.'))
		}
		const path = await uploadToFiles(target.uploadPath, file)
		await attachEvidence({ uuid: crypto.randomUUID(), kind: kind.value, title: file.name, path, targetType: props.targetType, targetUuid: props.targetUuid })
	})
	if (fileInput.value) {
		fileInput.value.value = ''
	}
}

onMounted(load)
</script>

<template>
	<section class="evidence-panel" :class="{ compact }">
		<h4 v-if="!compact">
			{{ t('maintenance_tracker', 'Evidence') }}
		</h4>
		<p v-if="error" class="evidence-error" role="alert">
			{{ error }}
		</p>
		<ul v-if="items.length > 0" class="evidence-list">
			<li v-for="item in items" :key="item.uuid">
				<img
					v-if="safeUrl(item.file?.previewUrl)"
					:src="safeUrl(item.file?.previewUrl)"
					alt=""
					class="evidence-thumb"
					loading="lazy">
				<span class="evidence-text">
					<a
						v-if="item.file && safeUrl(item.file.filesUrl)"
						:href="safeUrl(item.file.filesUrl)"
						target="_blank"
						rel="noopener noreferrer">{{ item.title }}</a>
					<strong v-else>{{ item.title }}</strong>
					<span> · {{ item.kind }} · {{ size(item.size) }}<template v-if="item.storage === 'workspace'"> {{ t('maintenance_tracker', '· workspace folder') }}</template></span>
					<em v-if="item.availability !== 'available'"> · {{ availabilityText[item.availability] }}</em>
					<em v-else-if="item.file?.changed"> {{ t('maintenance_tracker', '· file changed since it was attached') }}</em>
				</span>
				<button
					v-if="item.canChange"
					type="button"
					:disabled="busy"
					:title="t('maintenance_tracker', 'Removes the evidence record; the file stays in Files')"
					@click="run(() => archiveEvidence(item.uuid, item.revision))">
					{{ t('maintenance_tracker', 'Remove') }}
				</button>
			</li>
		</ul>
		<div class="evidence-actions">
			<select v-model="kind" :aria-label="t('maintenance_tracker', 'Evidence kind')">
				<option v-for="k in kinds" :key="k" :value="k">
					{{ k }}
				</option>
			</select>
			<button type="button" :disabled="busy" @click="pickFromFiles">
				{{ t('maintenance_tracker', 'Attach from Files') }}
			</button>
			<label class="evidence-upload">
				{{ t('maintenance_tracker', 'Upload') }}
				<input
					ref="fileInput"
					type="file"
					:disabled="busy"
					@change="upload">
			</label>
		</div>
	</section>
</template>

<style scoped>
.evidence-panel { display: grid; gap: 8px; }

.evidence-list { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; }

.evidence-list li { display: flex; align-items: center; gap: 10px; }

.evidence-thumb { width: 48px; height: 48px; border-radius: var(--border-radius); object-fit: cover; }

.evidence-text { flex: 1; }

.evidence-text a { color: var(--color-primary-element); text-decoration: underline; }

.evidence-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

.evidence-actions select { min-height: 34px; }

.evidence-panel button, .evidence-upload { min-height: 34px; padding: 4px 14px; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius-pill); background: var(--color-main-background); color: var(--color-main-text); cursor: pointer; }

.evidence-upload input { display: none; }

.evidence-error { color: var(--color-error); }

.compact { font-size: .9em; }
</style>
