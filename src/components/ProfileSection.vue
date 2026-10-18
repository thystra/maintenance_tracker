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
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SelectField from './SelectField.vue'
import { errorMessage, formatDate } from '../format.ts'
import { t } from '../l10n.ts'
import { getProfileInstallation, getProfiles, installProfile, previewProfile, validateProfile } from '../services/api.ts'
import { saveAssetAsProfile } from '../services/profileApi.ts'
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

const options = computed(() => catalog.value.map((profile) => ({
	value: profile.contentHash,
	label: profile.origin === 'workspace'
		? t('maintenance_tracker', '{name} ({version}, this workspace)', { name: profile.name, version: profile.version })
		: `${profile.name} (${profile.version})`,
})))

// "Save as profile": snapshot of this item's setup into the workspace library.
const saveOpen = ref(false)
const saveName = ref('')
const saveVersion = ref('1.0.0')
const saveError = ref('')
const saveUuid = ref('')
const saved = ref<{ uuid: string, name: string } | null>(null)

function openSave(): void {
	saveName.value = t('maintenance_tracker', '{name} setup', { name: props.asset.name }).slice(0, 255)
	saveVersion.value = '1.0.0'
	saveError.value = ''
	saveUuid.value = crypto.randomUUID()
	saveOpen.value = true
}

async function doSave(): Promise<void> {
	if (saveName.value.trim() === '') {
		saveError.value = t('maintenance_tracker', 'Give the profile a name.')
		return
	}
	busy.value = true
	saveError.value = ''
	try {
		const profile = await saveAssetAsProfile(props.asset.uuid, { uuid: saveUuid.value, name: saveName.value.trim(), version: saveVersion.value.trim() || '1.0.0' })
		saved.value = { uuid: profile.uuid ?? saveUuid.value, name: profile.name }
		catalog.value = [...catalog.value.filter((entry) => entry.uuid !== profile.uuid), profile]
		saveOpen.value = false
	} catch (reason) {
		saveError.value = errorMessage(reason)
	} finally {
		busy.value = false
	}
}

const saveButtons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => {
		saveOpen.value = false
		return false
	} },
	{ label: t('maintenance_tracker', 'Save profile'), variant: 'primary' as const, disabled: busy.value, callback: () => {
		void doSave()
		return false
	} },
])
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
				<p v-if="(preview.materializes.specifications ?? 0) > 0 || (preview.materializes.fuelRegimes ?? 0) > 0">
					{{ t('maintenance_tracker', 'Also adds {specs} specs and {regimes} fuel regimes.', { specs: preview.materializes.specifications ?? 0, regimes: preview.materializes.fuelRegimes ?? 0 }) }}
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
				<ul v-if="preview.notices && preview.notices.length > 0" class="muted">
					<li v-for="notice in preview.notices" :key="`n-${notice}`">
						{{ notice }}
					</li>
				</ul>
			</div>
		</template>
		<div v-if="can.manageProfiles" class="actions">
			<NcButton variant="secondary" @click="openSave">
				{{ t('maintenance_tracker', 'Save as profile') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="saved" type="success">
			<p>
				{{ t('maintenance_tracker', 'Saved this item\'s setup as the profile “{name}”.', { name: saved.name }) }}
				<RouterLink class="link" :to="{ name: 'profile', params: { uuid: saved.uuid } }">
					{{ t('maintenance_tracker', 'Open in the profile editor') }}
				</RouterLink>
			</p>
		</NcNoteCard>
		<NcDialog
			:open="saveOpen"
			:name="t('maintenance_tracker', 'Save as profile')"
			:buttons="saveButtons"
			size="small"
			@update:open="saveOpen = $event">
			<p class="muted">
				{{ t('maintenance_tracker', 'Saves components, meters, specs, compatible parts, task groups, tasks and fuel regimes. History, readings, fill-ups, costs and files are not included.') }}
			</p>
			<NcNoteCard v-if="saveError" type="error">
				<p role="alert">
					{{ saveError }}
				</p>
			</NcNoteCard>
			<div class="dialog-fields">
				<NcTextField v-model="saveName" required :label="t('maintenance_tracker', 'Profile name')" />
				<NcTextField v-model="saveVersion" :label="t('maintenance_tracker', 'Version')" />
			</div>
		</NcDialog>
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

.dialog-fields {
	display: grid;
	gap: 8px;
	margin-bottom: 8px;
}

.link {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
