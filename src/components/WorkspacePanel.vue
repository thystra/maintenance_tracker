<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { OwnershipTransfer, WorkspaceMemberSummary, WorkspaceSummary } from '../workspaceTypes.ts'

import { computed, onMounted, reactive, ref } from 'vue'
import ExportPanel from './ExportPanel.vue'
import { t } from '../l10n.ts'
import { currentWorkspace, selectWorkspace } from '../services/api.ts'
import { listMembers, listTransfers, listWorkspaces, ocsMessage, offerTransfer, transferAction } from '../services/workspaceApi.ts'

const workspaces = ref<WorkspaceSummary[]>([])
const members = ref<WorkspaceMemberSummary[]>([])
const outgoing = ref<OwnershipTransfer[]>([])
const selected = ref<string>('')
const open = ref(false)
const exportOpen = ref(false)
const busy = ref(false)
const error = ref('')
const draft = reactive({ toUid: '', leaveAfter: false, expiresInDays: 7 })

const active = computed<WorkspaceSummary | null>(() => {
	const chosen = currentWorkspace()
	return workspaces.value.find((workspace) => chosen === null ? workspace.kind === 'personal' : workspace.uuid === chosen) ?? null
})
const candidates = computed(() => members.value.filter((member) => member.role !== 'owner'))
const pending = computed(() => outgoing.value.filter((transfer) => transfer.workspace?.uuid === active.value?.uuid))

async function load(): Promise<void> {
	error.value = ''
	try {
		workspaces.value = await listWorkspaces()
		selected.value = active.value?.uuid ?? ''
		outgoing.value = (await listTransfers()).outgoing
		members.value = active.value?.role === 'owner' ? await listMembers(active.value.uuid) : []
	} catch (reason) {
		error.value = ocsMessage(reason)
	}
}

function switchWorkspace(): void {
	const target = workspaces.value.find((workspace) => workspace.uuid === selected.value)
	selectWorkspace(target === undefined || target.kind === 'personal' ? null : target.uuid)
	window.location.reload()
}

async function act(operation: () => Promise<unknown>): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		await operation()
	} catch (reason) {
		error.value = ocsMessage(reason)
	} finally {
		busy.value = false
		await load()
	}
}

async function offer(): Promise<void> {
	const workspace = active.value
	if (workspace === null) {
		return
	}
	await act(() => offerTransfer(workspace.uuid, { toUid: draft.toUid, leaveAfter: draft.leaveAfter, expiresInDays: Number(draft.expiresInDays) }))
}

onMounted(load)
defineExpose({ load })
</script>

<template>
	<section class="panel workspace-panel">
		<div class="workspace-bar">
			<label>
				<span>{{ t('maintenance_tracker', 'Workspace') }}</span>
				<select v-model="selected" :disabled="workspaces.length < 2" @change="switchWorkspace">
					<option v-for="workspace in workspaces" :key="workspace.uuid" :value="workspace.uuid">
						{{ workspace.kind === 'personal' ? t('maintenance_tracker', '{name} ({role}, personal)', { name: workspace.name, role: workspace.role }) : t('maintenance_tracker', '{name} ({role})', { name: workspace.name, role: workspace.role }) }}
					</option>
				</select>
			</label>
			<button
				v-if="active?.role === 'owner'"
				type="button"
				aria-controls="workspace-settings"
				:aria-expanded="open ? 'true' : 'false'"
				@click="open = !open">
				{{ open ? t('maintenance_tracker', 'Hide workspace settings') : t('maintenance_tracker', 'Workspace settings') }}
			</button>
			<button
				v-if="active !== null"
				type="button"
				aria-controls="workspace-export"
				:aria-expanded="exportOpen ? 'true' : 'false'"
				@click="exportOpen = !exportOpen">
				{{ exportOpen ? t('maintenance_tracker', 'Hide export') : t('maintenance_tracker', 'Export data') }}
			</button>
		</div>
		<p v-if="active?.orphaned" class="notice notice--error" role="status">
			{{ t('maintenance_tracker', 'This workspace has no owner because the owner\'s account was deleted. A Nextcloud administrator can assign one with') }}
			<code>occ maintenance_tracker:workspace:assign-owner</code>.
		</p>
		<p v-if="error" class="notice notice--error" role="alert">
			{{ error }}
		</p>
		<div v-if="open && active?.role === 'owner'" id="workspace-settings" class="workspace-settings">
			<h2>{{ t('maintenance_tracker', 'Transfer ownership') }}</h2>
			<template v-if="pending.length > 0">
				<div v-for="transfer in pending" :key="transfer.uuid" class="transfer-status">
					<p v-if="transfer.status === 'pending'">
						{{ t('maintenance_tracker', 'Waiting for {user} to accept (expires {date}).', { user: transfer.toUid, date: transfer.expiresAt.slice(0, 10) }) }}
					</p>
					<p v-else>
						{{ t('maintenance_tracker', 'Transfer to {user} stopped at the “{step}” step; it is safe to resume.', { user: transfer.toUid, step: transfer.step }) }}
					</p>
					<button
						v-if="transfer.status === 'pending'"
						type="button"
						:disabled="busy"
						@click="act(() => transferAction(transfer.uuid, 'cancel'))">
						{{ t('maintenance_tracker', 'Cancel offer') }}
					</button>
					<button
						v-if="transfer.resumable"
						type="button"
						:disabled="busy"
						@click="act(() => transferAction(transfer.uuid, 'resume'))">
						{{ t('maintenance_tracker', 'Resume') }}
					</button>
					<button
						v-if="transfer.abortable"
						type="button"
						:disabled="busy"
						@click="act(() => transferAction(transfer.uuid, 'abort'))">
						{{ t('maintenance_tracker', 'Abort') }}
					</button>
				</div>
			</template>
			<p v-else-if="candidates.length === 0">
				{{ t('maintenance_tracker', 'Add a member first; ownership can only go to an existing member, who must accept it.') }}
			</p>
			<form v-else class="form-grid" @submit.prevent="offer">
				<label><span>{{ t('maintenance_tracker', 'New owner') }}</span>
					<select v-model="draft.toUid" required>
						<option value="" disabled>{{ t('maintenance_tracker', 'Choose a member') }}</option>
						<option v-for="member in candidates" :key="member.userUid" :value="member.userUid">{{ member.userUid }} ({{ member.role }})</option>
					</select>
				</label>
				<label><span>{{ t('maintenance_tracker', 'Offer valid for (days)') }}</span><input
					v-model.number="draft.expiresInDays"
					type="number"
					min="1"
					max="30"
					required></label>
				<label class="checkbox"><input v-model="draft.leaveAfter" type="checkbox"><span>{{ t('maintenance_tracker', 'Leave the workspace afterwards (otherwise you become a Manager)') }}</span></label>
				<p class="hint">
					{{ t('maintenance_tracker', 'When accepted, the workspace evidence folder moves into the new owner\'s Files and counts against their quota; member shares are re-created from the new owner.') }}
				</p>
				<button type="submit" :disabled="busy || draft.toUid === ''">
					{{ t('maintenance_tracker', 'Offer ownership') }}
				</button>
			</form>
		</div>
		<ExportPanel
			v-if="exportOpen && active !== null"
			id="workspace-export"
			:workspace="active.uuid"
			:role="active.role" />
	</section>
</template>

<style scoped>
.workspace-bar {
	display: flex;
	gap: 12px;
	align-items: flex-end;
	flex-wrap: wrap;
}

.transfer-status {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}

.checkbox {
	display: flex;
	gap: 8px;
	align-items: flex-start;
}

.hint {
	color: var(--color-text-maxcontrast);
}
</style>
