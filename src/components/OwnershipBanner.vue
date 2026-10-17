<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { OwnershipTransfer } from '../workspaceTypes.ts'

import { onMounted, reactive, ref } from 'vue'
import { t } from '../l10n.ts'
import { acceptTransfer, listTransfers, ocsMessage, transferAction } from '../services/workspaceApi.ts'

const emit = defineEmits<{ (event: 'changed'): void }>()

const incoming = ref<OwnershipTransfer[]>([])
const acknowledged = reactive<Record<string, boolean>>({})
const busy = ref(false)
const error = ref('')
const notice = ref('')

function bytes(value: number | null | undefined): string {
	if (value === null || value === undefined) {
		return 'unlimited'
	}
	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	let size = value
	let unit = 0
	while (size >= 1024 && unit < units.length - 1) {
		size /= 1024
		unit++
	}
	return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`
}

async function load(): Promise<void> {
	try {
		incoming.value = (await listTransfers()).incoming
	} catch (reason) {
		error.value = ocsMessage(reason)
	}
}

async function act(operation: () => Promise<OwnershipTransfer>, done: string): Promise<void> {
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		const result = await operation()
		notice.value = result.status === 'completed' ? done : `Transfer ${result.status}.`
		emit('changed')
	} catch (reason) {
		error.value = ocsMessage(reason)
	} finally {
		busy.value = false
		await load()
	}
}

onMounted(load)
defineExpose({ load })
</script>

<template>
	<div v-if="incoming.length > 0 || notice || error" class="ownership-banner">
		<p v-if="notice" class="notice" role="status">
			{{ notice }}
		</p>
		<p v-if="error" class="notice notice--error" role="alert">
			{{ error }}
		</p>
		<section
			v-for="transfer in incoming"
			:key="transfer.uuid"
			class="panel ownership-offer"
			aria-live="polite">
			<template v-if="transfer.status === 'pending'">
				<h2>{{ t('maintenance_tracker', 'Workspace ownership offered to you') }}</h2>
				<p>
					{{ transfer.leaveAfter
						? t('maintenance_tracker', '{user} wants to make you the owner of {workspace} (offer expires {date}). They will leave the workspace.', { user: transfer.fromUid, workspace: transfer.workspace?.name ?? t('maintenance_tracker', 'a workspace'), date: transfer.expiresAt.slice(0, 10) })
						: t('maintenance_tracker', '{user} wants to make you the owner of {workspace} (offer expires {date}). They will stay on as a Manager.', { user: transfer.fromUid, workspace: transfer.workspace?.name ?? t('maintenance_tracker', 'a workspace'), date: transfer.expiresAt.slice(0, 10) }) }}
				</p>
				<p v-if="transfer.quota">
					{{ t('maintenance_tracker', 'Accepting moves the workspace evidence folder ({folderBytes}) into your Files. Uploads by every member will count against your storage quota; you have {recipientFreeBytes} free.', { folderBytes: bytes(transfer.quota.folderBytes), recipientFreeBytes: bytes(transfer.quota.recipientFreeBytes) }) }}
				</p>
				<p v-if="transfer.quota && transfer.quota.fits === false" class="notice notice--error" role="alert">
					{{ t('maintenance_tracker', 'The folder does not fit in your remaining quota. Free up space or ask an administrator before accepting.') }}
				</p>
				<label class="checkbox">
					<input v-model="acknowledged[transfer.uuid]" type="checkbox">
					<span>{{ t('maintenance_tracker', 'I understand the evidence counts against my quota and I become responsible for this workspace.') }}</span>
				</label>
				<div class="actions">
					<button
						type="button"
						class="primary"
						:disabled="busy || !acknowledged[transfer.uuid] || transfer.quota?.fits === false"
						@click="act(() => acceptTransfer(transfer.uuid), t('maintenance_tracker', 'You now own the workspace.'))">
						{{ t('maintenance_tracker', 'Accept ownership') }}
					</button>
					<button type="button" :disabled="busy" @click="act(() => transferAction(transfer.uuid, 'decline'), t('maintenance_tracker', 'Offer declined.'))">
						{{ t('maintenance_tracker', 'Decline') }}
					</button>
				</div>
			</template>
			<template v-else>
				<h2>{{ t('maintenance_tracker', 'Ownership transfer not finished') }}</h2>
				<p>
					{{ t('maintenance_tracker', 'The transfer of {workspace} stopped at the “{step}” step. It is safe to resume; nothing is half-moved.', { workspace: transfer.workspace?.name ?? t('maintenance_tracker', 'a workspace'), step: transfer.step }) }}
				</p>
				<div class="actions">
					<button
						type="button"
						class="primary"
						:disabled="busy"
						@click="act(() => transferAction(transfer.uuid, 'resume'), t('maintenance_tracker', 'Transfer finished.'))">
						{{ t('maintenance_tracker', 'Resume transfer') }}
					</button>
					<button
						v-if="transfer.abortable"
						type="button"
						:disabled="busy"
						@click="act(() => transferAction(transfer.uuid, 'abort'), t('maintenance_tracker', 'Transfer aborted.'))">
						{{ t('maintenance_tracker', 'Abort') }}
					</button>
				</div>
			</template>
		</section>
	</div>
</template>

<style scoped>
.ownership-offer {
	border-inline-start: 4px solid var(--color-primary-element);
}

.actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.checkbox {
	display: flex;
	gap: 8px;
	align-items: flex-start;
}
</style>
