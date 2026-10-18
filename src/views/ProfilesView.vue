<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Maintenance profiles: the workspace's own (editable) and the bundled ones.
// Import reads a local JSON file only; nothing is fetched from the network.
import type { BundledProfile } from '../types.ts'

import { mdiClipboardListOutline, mdiContentCopy, mdiDeleteOutline, mdiDownload, mdiPencilOutline, mdiPlus, mdiUpload } from '@mdi/js'
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import PageHeader from '../components/PageHeader.vue'
import { errorMessage } from '../format.ts'
import { n, t } from '../l10n.ts'
import { downloadProfile } from '../profileEditor.ts'
import { MAX_PROFILE_BYTES } from '../profileTypes.ts'
import { getProfiles } from '../services/api.ts'
import { createWorkspaceProfile, deleteWorkspaceProfile } from '../services/profileApi.ts'
import { can, categoryName } from '../store.ts'

const router = useRouter()
const profiles = ref<BundledProfile[]>([])
const loading = ref(true)
const error = ref('')
const status = ref('')
const deleting = ref<BundledProfile | null>(null)
const busy = ref(false)
const fileInput = useTemplateRef<HTMLInputElement>('fileInput')

const byName = (left: BundledProfile, right: BundledProfile): number => left.name.localeCompare(right.name)
const workspaceProfiles = computed(() => profiles.value.filter((profile) => profile.origin === 'workspace').sort(byName))
const bundledProfiles = computed(() => profiles.value.filter((profile) => profile.origin !== 'workspace').sort(byName))

/**
 * One-line summary of what a profile sets up.
 *
 * @param profile Catalog entry.
 */
function summary(profile: BundledProfile): string {
	const s = profile.summary
	return [
		categoryName(profile.category),
		t('maintenance_tracker', 'version {version}', { version: profile.version }),
		n('maintenance_tracker', '%n task', '%n tasks', s.workDefinitions),
		n('maintenance_tracker', '%n component', '%n components', s.components),
		n('maintenance_tracker', '%n meter', '%n meters', s.meters),
		s.parts > 0 ? n('maintenance_tracker', '%n part', '%n parts', s.parts) : '',
		(s.specifications ?? 0) > 0 ? n('maintenance_tracker', '%n spec', '%n specs', s.specifications ?? 0) : '',
		(s.fuelRegimes ?? 0) > 0 ? n('maintenance_tracker', '%n fuel regime', '%n fuel regimes', s.fuelRegimes ?? 0) : '',
	].filter((part) => part !== '').join(' · ')
}

async function load(): Promise<void> {
	loading.value = true
	try {
		profiles.value = (await getProfiles()).items
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		loading.value = false
	}
}

function edit(profile: BundledProfile): void {
	void router.push({ name: 'profile', params: { uuid: profile.uuid ?? 'new' } })
}

function duplicate(profile: BundledProfile): void {
	void router.push({ name: 'profile', params: { uuid: 'new' }, query: profile.origin === 'workspace' ? { from: profile.uuid } : { bundled: profile.contentHash } })
}

async function importFile(event: Event): Promise<void> {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0]
	input.value = ''
	if (file === undefined) {
		return
	}
	error.value = ''
	if (file.size > MAX_PROFILE_BYTES) {
		error.value = t('maintenance_tracker', 'That file is larger than 1 MiB, the limit for a profile.')
		return
	}
	let document: unknown
	try {
		document = JSON.parse(await file.text())
	} catch {
		error.value = t('maintenance_tracker', 'That file is not valid JSON.')
		return
	}
	if (document === null || typeof document !== 'object' || Array.isArray(document)) {
		error.value = t('maintenance_tracker', 'A profile file must contain one JSON object.')
		return
	}
	busy.value = true
	try {
		const created = await createWorkspaceProfile(crypto.randomUUID(), document as Record<string, unknown>)
		profiles.value = [...profiles.value, created]
		status.value = t('maintenance_tracker', 'Imported {name}.', { name: created.name })
	} catch (reason) {
		error.value = t('maintenance_tracker', 'Could not import the profile: {reason}', { reason: errorMessage(reason) })
	} finally {
		busy.value = false
	}
}

async function remove(): Promise<void> {
	const profile = deleting.value
	if (profile?.uuid === undefined || profile.revision === undefined) {
		return
	}
	busy.value = true
	try {
		await deleteWorkspaceProfile(profile.uuid, profile.revision)
		profiles.value = profiles.value.filter((candidate) => candidate.uuid !== profile.uuid)
		status.value = t('maintenance_tracker', 'Deleted {name}.', { name: profile.name })
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		deleting.value = null
		busy.value = false
	}
}

onMounted(load)
</script>

<template>
	<div>
		<PageHeader :title="t('maintenance_tracker', 'Profiles')" :subtitle="t('maintenance_tracker', 'Reusable setups: components, meters, specs, parts, tasks and fuel regimes')">
			<template v-if="can.manageProfiles" #actions>
				<NcButton :disabled="busy" @click="fileInput?.click()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiUpload" />
					</template>
					{{ t('maintenance_tracker', 'Import profile') }}
				</NcButton>
				<NcButton variant="primary" :to="{ name: 'profile', params: { uuid: 'new' } }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('maintenance_tracker', 'New profile') }}
				</NcButton>
				<input
					ref="fileInput"
					class="hidden-visually"
					type="file"
					accept="application/json,.json"
					tabindex="-1"
					:aria-label="t('maintenance_tracker', 'Profile JSON file')"
					@change="importFile">
			</template>
		</PageHeader>
		<p class="hidden-visually" role="status">
			{{ status }}
		</p>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<NcLoadingIcon v-if="loading" :size="44" :name="t('maintenance_tracker', 'Loading')" />
		<template v-else>
			<section class="group" aria-labelledby="workspace-profiles-heading">
				<h3 id="workspace-profiles-heading">
					{{ t('maintenance_tracker', 'Workspace profiles') }}
				</h3>
				<NcEmptyContent
					v-if="workspaceProfiles.length === 0"
					class="empty"
					:name="t('maintenance_tracker', 'No workspace profiles yet')"
					:description="t('maintenance_tracker', 'Build one here, duplicate a bundled profile, import a JSON file, or use “Save as profile” on an item\'s Setup tab.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClipboardListOutline" :size="64" />
					</template>
				</NcEmptyContent>
				<ul v-else class="list" :aria-label="t('maintenance_tracker', 'Workspace profiles')">
					<NcListItem
						v-for="profile in workspaceProfiles"
						:key="profile.uuid"
						:name="profile.name"
						:forceDisplayActions="true"
						:to="can.manageProfiles ? { name: 'profile', params: { uuid: profile.uuid } } : undefined">
						<template #subname>
							{{ summary(profile) }}
						</template>
						<template #actions>
							<NcActionButton v-if="can.manageProfiles" closeAfterClick @click="edit(profile)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiPencilOutline" />
								</template>
								{{ t('maintenance_tracker', 'Edit') }}
							</NcActionButton>
							<NcActionButton v-if="can.manageProfiles" closeAfterClick @click="duplicate(profile)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiContentCopy" />
								</template>
								{{ t('maintenance_tracker', 'Duplicate') }}
							</NcActionButton>
							<NcActionButton closeAfterClick @click="downloadProfile(profile.profile)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDownload" />
								</template>
								{{ t('maintenance_tracker', 'Export JSON') }}
							</NcActionButton>
							<NcActionButton v-if="can.manageProfiles" closeAfterClick @click="deleting = profile">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDeleteOutline" />
								</template>
								{{ t('maintenance_tracker', 'Delete') }}
							</NcActionButton>
						</template>
					</NcListItem>
				</ul>
			</section>
			<section class="group" aria-labelledby="bundled-profiles-heading">
				<h3 id="bundled-profiles-heading">
					{{ t('maintenance_tracker', 'Bundled profiles') }}
				</h3>
				<p class="hint">
					{{ t('maintenance_tracker', 'Shipped with the app and reviewed. Duplicate one to adapt it for your workspace.') }}
				</p>
				<ul class="list" :aria-label="t('maintenance_tracker', 'Bundled profiles')">
					<NcListItem
						v-for="profile in bundledProfiles"
						:key="profile.contentHash"
						:name="profile.name"
						:forceDisplayActions="true">
						<template #subname>
							{{ summary(profile) }}
						</template>
						<template #actions>
							<NcActionButton v-if="can.manageProfiles" closeAfterClick @click="duplicate(profile)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiContentCopy" />
								</template>
								{{ t('maintenance_tracker', 'Duplicate') }}
							</NcActionButton>
							<NcActionButton closeAfterClick @click="downloadProfile(profile.profile)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDownload" />
								</template>
								{{ t('maintenance_tracker', 'Export JSON') }}
							</NcActionButton>
						</template>
					</NcListItem>
				</ul>
			</section>
		</template>

		<ConfirmDialog
			:open="deleting !== null"
			:name="t('maintenance_tracker', 'Delete profile?')"
			:message="t('maintenance_tracker', 'Delete “{name}”? Items already set up from it keep their setup.', { name: deleting?.name ?? '' })"
			:busy="busy"
			@update:open="deleting = $event ? deleting : null"
			@confirm="remove" />
	</div>
</template>

<style scoped>
.group {
	margin-bottom: 24px;
}

.group h3 {
	margin: 0 0 4px;
	font-size: 17px;
}

.hint {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
}

.list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.empty {
	margin-top: 12px;
}
</style>
