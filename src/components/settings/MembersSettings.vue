<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Members and roles of the current workspace, and ownership transfer.
import type { OwnershipTransfer, WorkspaceMemberSummary, WorkspaceRole } from '../../workspaceTypes.ts'

import { mdiAccountRemoveOutline } from '@mdi/js'
import { getCurrentUser } from '@nextcloud/auth'
import { computed, onMounted, reactive, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConfirmDialog from '../ConfirmDialog.vue'
import SelectField from '../SelectField.vue'
import { errorMessage, formatDate, roleLabel } from '../../format.ts'
import { t } from '../../l10n.ts'
import { addMember, changeMemberRole, listMembers, listTransfers, offerTransfer, removeMember, transferAction } from '../../services/workspaceApi.ts'
import { activeWorkspace, can } from '../../store.ts'

const members = ref<WorkspaceMemberSummary[]>([])
const outgoing = ref<OwnershipTransfer[]>([])
const error = ref('')
const status = ref('')
const busy = ref(false)
const removing = ref<WorkspaceMemberSummary | null>(null)
const draft = reactive({ userUid: '', role: 'contributor' as string | null })
const transfer = reactive({ toUid: null as string | null, leaveAfter: false, expiresInDays: '7' })
const me = getCurrentUser()?.uid ?? ''

const roleOptions = (['manager', 'contributor', 'viewer'] as WorkspaceRole[]).map((value) => ({ value, label: roleLabel(value) }))
const roleHelp = computed(() => [
	{ role: roleLabel('owner'), text: t('maintenance_tracker', 'everything, including members, workspace settings and ownership') },
	{ role: roleLabel('manager'), text: t('maintenance_tracker', 'add and change items, tasks, parts, vendors and the forecast policy') },
	{ role: roleLabel('contributor'), text: t('maintenance_tracker', 'log maintenance, readings, costs and files') },
	{ role: roleLabel('viewer'), text: t('maintenance_tracker', 'read only') },
])
const candidates = computed(() => members.value.filter((member) => member.role !== 'owner').map((member) => ({ value: member.userUid, label: `${member.userUid} (${roleLabel(member.role)})` })))
const pending = computed(() => outgoing.value.filter((entry) => entry.workspace?.uuid === activeWorkspace.value?.uuid))

async function load(): Promise<void> {
	const workspace = activeWorkspace.value
	if (workspace === null || !can.readMembers) {
		return
	}
	try {
		members.value = await listMembers(workspace.uuid)
		outgoing.value = can.ownWorkspace ? (await listTransfers()).outgoing : []
	} catch (reason) {
		error.value = errorMessage(reason)
	}
}

async function run(operation: () => Promise<unknown>, done = ''): Promise<void> {
	busy.value = true
	error.value = ''
	status.value = ''
	try {
		await operation()
		status.value = done
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
		await load()
	}
}

const workspaceUuid = () => activeWorkspace.value?.uuid ?? ''

function add(): void {
	const uid = draft.userUid.trim()
	if (uid === '') {
		return
	}
	void run(async () => {
		await addMember(workspaceUuid(), uid, (draft.role ?? 'contributor') as WorkspaceRole)
		draft.userUid = ''
	}, t('maintenance_tracker', 'Added {user}.', { user: uid }))
}

function setRole(member: WorkspaceMemberSummary, role: string | null): void {
	if (role === null || role === member.role) {
		return
	}
	void run(() => changeMemberRole(workspaceUuid(), member.userUid, role as WorkspaceRole), t('maintenance_tracker', '{user} is now {role}.', { user: member.userUid, role: roleLabel(role as WorkspaceRole) }))
}

async function confirmRemove(): Promise<void> {
	const member = removing.value
	removing.value = null
	if (member !== null) {
		await run(() => removeMember(workspaceUuid(), member.userUid), t('maintenance_tracker', 'Removed {user}.', { user: member.userUid }))
	}
}

function offer(): void {
	if (transfer.toUid === null) {
		return
	}
	const toUid = transfer.toUid
	void run(() => offerTransfer(workspaceUuid(), { toUid, leaveAfter: transfer.leaveAfter, expiresInDays: Number(transfer.expiresInDays) || 7 }), t('maintenance_tracker', 'Offer sent to {user}.', { user: toUid }))
}

onMounted(load)
</script>

<template>
	<div class="mt-section">
		<NcNoteCard v-if="!can.readMembers" type="info">
			<p>{{ t('maintenance_tracker', 'Only owners and managers can see the member list.') }}</p>
		</NcNoteCard>
		<template v-else>
			<NcNoteCard v-if="error" type="error">
				<p role="alert">
					{{ error }}
				</p>
			</NcNoteCard>
			<NcNoteCard v-if="status" type="success">
				<p role="status">
					{{ status }}
				</p>
			</NcNoteCard>
			<ul class="list" :aria-label="t('maintenance_tracker', 'Members')">
				<NcListItem
					v-for="member in members"
					:key="member.userUid"
					:name="member.userUid"
					:forceDisplayActions="true"
					compact>
					<template #icon>
						<NcAvatar :user="member.userUid" :size="32" hideStatus />
					</template>
					<template #subname>
						{{ t('maintenance_tracker', '{role} · member since {date}', { role: roleLabel(member.role), date: formatDate(member.createdAt) }) }}
					</template>
					<template v-if="can.manageMembers && member.role !== 'owner' && member.userUid !== me" #extra-actions>
						<div class="role-select">
							<SelectField
								:modelValue="member.role"
								:options="roleOptions"
								:label="t('maintenance_tracker', 'Role of {user}', { user: member.userUid })"
								:disabled="busy"
								@update:modelValue="setRole(member, $event)" />
						</div>
					</template>
					<template v-if="can.manageMembers && member.role !== 'owner'" #actions>
						<NcActionButton closeAfterClick @click="removing = member">
							<template #icon>
								<NcIconSvgWrapper :path="mdiAccountRemoveOutline" />
							</template>
							{{ t('maintenance_tracker', 'Remove from workspace') }}
						</NcActionButton>
					</template>
				</NcListItem>
			</ul>

			<form v-if="can.manageMembers" class="inline-form" @submit.prevent="add">
				<h3>{{ t('maintenance_tracker', 'Add member') }}</h3>
				<div class="row">
					<NcTextField v-model="draft.userUid" :label="t('maintenance_tracker', 'Nextcloud user ID')" required />
					<SelectField v-model="draft.role" :options="roleOptions" :label="t('maintenance_tracker', 'Role')" />
					<NcButton type="submit" variant="primary" :disabled="busy || draft.userUid.trim() === ''">
						{{ t('maintenance_tracker', 'Add') }}
					</NcButton>
				</div>
			</form>
			<details class="help">
				<summary>{{ t('maintenance_tracker', 'What each role can do') }}</summary>
				<dl>
					<template v-for="entry in roleHelp" :key="entry.role">
						<dt>{{ entry.role }}</dt>
						<dd>{{ entry.text }}</dd>
					</template>
				</dl>
			</details>

			<section v-if="can.ownWorkspace" class="transfer" aria-labelledby="transfer-heading">
				<h3 id="transfer-heading">
					{{ t('maintenance_tracker', 'Transfer ownership') }}
				</h3>
				<div v-for="entry in pending" :key="entry.uuid" class="row">
					<p v-if="entry.status === 'pending'">
						{{ t('maintenance_tracker', 'Waiting for {user} to accept (expires {date}).', { user: entry.toUid, date: formatDate(entry.expiresAt) }) }}
					</p>
					<p v-else>
						{{ t('maintenance_tracker', 'Transfer to {user} stopped at the “{step}” step; it is safe to resume.', { user: entry.toUid, step: entry.step }) }}
					</p>
					<NcButton v-if="entry.status === 'pending'" :disabled="busy" @click="run(() => transferAction(entry.uuid, 'cancel'))">
						{{ t('maintenance_tracker', 'Cancel offer') }}
					</NcButton>
					<NcButton v-if="entry.resumable" :disabled="busy" @click="run(() => transferAction(entry.uuid, 'resume'))">
						{{ t('maintenance_tracker', 'Resume') }}
					</NcButton>
					<NcButton v-if="entry.abortable" :disabled="busy" @click="run(() => transferAction(entry.uuid, 'abort'))">
						{{ t('maintenance_tracker', 'Abort') }}
					</NcButton>
				</div>
				<p v-if="pending.length === 0 && candidates.length === 0" class="muted">
					{{ t('maintenance_tracker', 'Add a member first; ownership can only go to an existing member, who must accept it.') }}
				</p>
				<form v-else-if="pending.length === 0" class="inline-form" @submit.prevent="offer">
					<div class="row">
						<SelectField v-model="transfer.toUid" :options="candidates" :label="t('maintenance_tracker', 'New owner')" />
						<NcTextField v-model="transfer.expiresInDays" :label="t('maintenance_tracker', 'Offer valid for (days)')" inputmode="numeric" />
					</div>
					<NcCheckboxRadioSwitch v-model="transfer.leaveAfter" type="switch">
						{{ t('maintenance_tracker', 'Leave the workspace afterwards (otherwise you become a Manager)') }}
					</NcCheckboxRadioSwitch>
					<p class="muted">
						{{ t('maintenance_tracker', 'When accepted, the workspace evidence folder moves into the new owner\'s Files and counts against their quota; member shares are re-created from the new owner.') }}
					</p>
					<NcButton type="submit" :disabled="busy || transfer.toUid === null">
						{{ t('maintenance_tracker', 'Offer ownership') }}
					</NcButton>
				</form>
			</section>
		</template>
		<ConfirmDialog
			:open="removing !== null"
			:name="t('maintenance_tracker', 'Remove member?')"
			:message="t('maintenance_tracker', 'Remove {user} from this workspace? They lose access to its items and files.', { user: removing?.userUid ?? '' })"
			:confirmLabel="t('maintenance_tracker', 'Remove')"
			@update:open="removing = $event ? removing : null"
			@confirm="confirmRemove" />
	</div>
</template>

<style scoped>
.role-select {
	width: 170px;
}

.help dl {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
}

.help dt {
	font-weight: bold;
}

.help dd {
	margin: 0;
}

.transfer {
	margin-top: 24px;
}
</style>
