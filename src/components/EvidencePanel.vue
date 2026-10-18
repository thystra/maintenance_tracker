<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Evidence, EvidenceKind, EvidenceTargetType } from '../evidenceTypes.ts'

import { mdiCloudUploadOutline, mdiFolderOutline } from '@mdi/js'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import SelectField from './SelectField.vue'
import { t } from '../l10n.ts'
import { archiveEvidence, attachEvidence, getUploadTarget, listEvidence, uploadToFiles } from '../services/evidenceApi.ts'

import '@nextcloud/dialogs/style.css'

const props = defineProps<{ targetType: EvidenceTargetType, targetUuid: string, compact?: boolean }>()

const kinds: EvidenceKind[] = ['photo', 'receipt', 'invoice', 'document', 'video', 'other']
const items = ref<Evidence[]>([])
const kind = ref<string | null>('receipt')
const kindLabels: Record<EvidenceKind, string> = {
	photo: t('maintenance_tracker', 'Photo'),
	receipt: t('maintenance_tracker', 'Receipt'),
	invoice: t('maintenance_tracker', 'Invoice'),
	document: t('maintenance_tracker', 'Document'),
	video: t('maintenance_tracker', 'Video'),
	other: t('maintenance_tracker', 'Other'),
}
const kindOptions = computed(() => kinds.map((value) => ({ value, label: kindLabels[value] })))
const selectedKind = (): EvidenceKind => (kinds as string[]).includes(kind.value ?? '') ? kind.value as EvidenceKind : 'other'
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
	await run(() => attachEvidence({ uuid: crypto.randomUUID(), kind: selectedKind(), path, targetType: props.targetType, targetUuid: props.targetUuid }))
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
		await attachEvidence({ uuid: crypto.randomUUID(), kind: selectedKind(), title: file.name, path, targetType: props.targetType, targetUuid: props.targetUuid })
	})
	if (fileInput.value) {
		fileInput.value.value = ''
	}
}

onMounted(load)
</script>

<template>
	<section class="evidence-panel" :class="{ compact }">
		<p v-if="!compact" class="muted">
			{{ t('maintenance_tracker', 'Photos, receipts and manuals. Files stay in Nextcloud Files; this only links them.') }}
		</p>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<p v-if="items.length === 0 && !compact" class="muted">
			{{ t('maintenance_tracker', 'No files attached yet.') }}
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
					<span class="muted"> · {{ kindLabels[item.kind] ?? item.kind }} · {{ size(item.size) }}<template v-if="item.storage === 'workspace'"> {{ t('maintenance_tracker', '· workspace folder') }}</template></span>
					<em v-if="item.availability !== 'available'"> · {{ availabilityText[item.availability] }}</em>
					<em v-else-if="item.file?.changed"> {{ t('maintenance_tracker', '· file changed since it was attached') }}</em>
				</span>
				<NcButton
					v-if="item.canChange"
					variant="tertiary"
					:disabled="busy"
					:title="t('maintenance_tracker', 'Removes the link; the file stays in Files')"
					@click="run(() => archiveEvidence(item.uuid, item.revision))">
					{{ t('maintenance_tracker', 'Remove') }}
				</NcButton>
			</li>
		</ul>
		<div class="evidence-actions">
			<div class="evidence-kind">
				<SelectField v-model="kind" :options="kindOptions" :label="t('maintenance_tracker', 'File type')" />
			</div>
			<NcButton :disabled="busy" @click="pickFromFiles">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFolderOutline" />
				</template>
				{{ t('maintenance_tracker', 'Attach from Files') }}
			</NcButton>
			<NcButton :disabled="busy" @click="fileInput?.click()">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCloudUploadOutline" />
				</template>
				{{ t('maintenance_tracker', 'Upload') }}
			</NcButton>
			<input
				ref="fileInput"
				class="hidden-visually"
				type="file"
				tabindex="-1"
				:aria-label="t('maintenance_tracker', 'Upload a file')"
				:disabled="busy"
				@change="upload">
		</div>
	</section>
</template>

<style scoped>
.evidence-panel {
	display: grid;
	gap: 8px;
}

.evidence-list {
	display: grid;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.evidence-list li {
	display: flex;
	align-items: center;
	gap: 10px;
}

.evidence-thumb {
	width: 48px;
	height: 48px;
	border-radius: var(--border-radius);
	object-fit: cover;
}

.evidence-text {
	flex: 1;
	min-width: 0;
	overflow-wrap: anywhere;
}

.evidence-text a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.evidence-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: 8px;
}

.evidence-kind {
	min-width: 180px;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
