<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Apply a maintenance profile: pick a bundled one (or paste JSON), preview
// what it adds, then install. Installing is idempotent per attempt UUID.
import type { Asset, BundledProfile, ProfileDocument, ProfileInstallation, ProfilePreview } from '../types.ts'

import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import SelectField from './SelectField.vue'
import { errorMessage, formatDate } from '../format.ts'
import { t } from '../l10n.ts'
import { getProfileInstallation, getProfiles, installProfile, previewProfile, validateProfile } from '../services/api.ts'
import { can } from '../store.ts'

const props = defineProps<{ asset: Asset }>()
const emit = defineEmits<{ (event: 'changed'): void }>()

const catalog = ref<BundledProfile[]>([])
const installation = ref<ProfileInstallation | null>(null)
const selectedHash = ref<string | null>(null)
const custom = ref(false)
const customText = ref('')
const preview = ref<ProfilePreview | null>(null)
const attempt = ref<{ hash: string, uuid: string } | null>(null)
const error = ref('')
const message = ref('')
const busy = ref(false)

const options = computed(() => catalog.value.map((profile) => ({ value: profile.contentHash, label: `${profile.name} (${profile.version})` })))
const selected = computed(() => catalog.value.find((profile) => profile.contentHash === selectedHash.value) ?? null)

function documentToInstall(): ProfileDocument {
	if (!custom.value) {
		if (selected.value === null) {
			throw new Error(t('maintenance_tracker', 'Choose a profile first.'))
		}
		return selected.value.profile
	}
	let parsed: unknown
	try {
		parsed = JSON.parse(customText.value)
	} catch {
		throw new Error(t('maintenance_tracker', 'Profile JSON is not valid JSON.'))
	}
	if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
		throw new Error(t('maintenance_tracker', 'Profile JSON must contain an object at the root.'))
	}
	return parsed as ProfileDocument
}

async function load(): Promise<void> {
	try {
		const [list, current] = await Promise.all([getProfiles(), getProfileInstallation(props.asset.uuid)])
		catalog.value = list.items
		installation.value = current.installation
	} catch (reason) {
		error.value = errorMessage(reason)
	}
}

async function run(operation: () => Promise<void>): Promise<void> {
	busy.value = true
	error.value = ''
	message.value = ''
	try {
		await operation()
	} catch (reason) {
		error.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

function doPreview() {
	return run(async () => {
		const document = documentToInstall()
		if (custom.value) {
			await validateProfile(document)
		}
		preview.value = await previewProfile(props.asset.uuid, document)
		if (attempt.value?.hash !== preview.value.profile.contentHash) {
			attempt.value = { hash: preview.value.profile.contentHash, uuid: crypto.randomUUID() }
		}
	})
}

function doInstall() {
	return run(async () => {
		if (preview.value === null || attempt.value === null || !preview.value.installable) {
			return
		}
		installation.value = await installProfile(props.asset.uuid, attempt.value.uuid, documentToInstall())
		preview.value = null
		message.value = t('maintenance_tracker', 'Profile applied. Its tasks, meters and components are now on this item.')
		emit('changed')
	})
}

watch([selectedHash, custom], () => {
	preview.value = null
})
onMounted(load)
</script>

<template>
	<div class="profile">
		<p v-if="installation" class="muted">
			{{ t('maintenance_tracker', 'Applied: {profile} {version} on {date}.', { profile: installation.profile.id, version: installation.profile.version, date: formatDate(installation.installedAt) }) }}
		</p>
		<p v-else class="muted">
			{{ t('maintenance_tracker', 'A profile adds a recommended set of tasks, meters and components for a kind of item, for example a car or a furnace.') }}
		</p>
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="message" type="success">
			<p>{{ message }}</p>
		</NcNoteCard>
		<template v-if="can.manageInventory && !installation">
			<SelectField
				v-if="!custom"
				v-model="selectedHash"
				:options="options"
				:label="t('maintenance_tracker', 'Profile')"
				:placeholder="t('maintenance_tracker', 'Choose a profile')" />
			<p v-if="selected && !custom" class="muted">
				{{ selected.description }}
			</p>
			<NcCheckboxRadioSwitch v-model="custom" type="switch">
				{{ t('maintenance_tracker', 'Paste a profile file instead (advanced)') }}
			</NcCheckboxRadioSwitch>
			<NcTextArea
				v-if="custom"
				v-model="customText"
				:label="t('maintenance_tracker', 'Profile JSON')"
				rows="8"
				resize="vertical" />
			<div class="actions">
				<NcButton :disabled="busy || (!custom && !selected)" @click="doPreview">
					{{ t('maintenance_tracker', 'Preview') }}
				</NcButton>
				<NcButton
					v-if="preview"
					variant="primary"
					:disabled="busy || !preview.installable"
					@click="doInstall">
					{{ t('maintenance_tracker', 'Apply profile') }}
				</NcButton>
			</div>
			<div v-if="preview" class="preview" aria-live="polite">
				<p>
					{{ t('maintenance_tracker', 'Adds {tasks} tasks, {meters} meters, {components} components and {groups} task groups.', { tasks: preview.materializes.workDefinitions, meters: preview.materializes.meters, components: preview.materializes.components, groups: preview.materializes.workGroups }) }}
				</p>
				<NcNoteCard v-if="!preview.applicable" type="warning">
					<p>{{ t('maintenance_tracker', 'This profile is not meant for this kind of item.') }}</p>
				</NcNoteCard>
				<ul v-if="preview.conflicts.length > 0 || preview.warnings.length > 0">
					<li v-for="conflict in preview.conflicts" :key="`c-${conflict}`">
						{{ conflict }}
					</li>
					<li v-for="warning in preview.warnings" :key="`w-${warning}`">
						{{ warning }}
					</li>
				</ul>
			</div>
		</template>
	</div>
</template>

<style scoped>
.profile {
	display: grid;
	gap: 10px;
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.preview {
	padding: 8px 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
