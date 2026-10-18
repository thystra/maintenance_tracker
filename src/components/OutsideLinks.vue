<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { LinkSubjectType, OutsideLink } from '../catalogTypes.ts'

import { mdiOpenInNew } from '@mdi/js'
import { onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { t } from '../l10n.ts'
import { archiveLink, createLink, getLinks } from '../services/catalogApi.ts'

const props = defineProps<{ subjectType: LinkSubjectType, subjectUuid: string }>()

const enabled = ref(true)
const links = ref<OutsideLink[]>([])
const error = ref('')
const saving = ref(false)
const adding = ref(false)
const draft = reactive({ label: '', url: '' })

/**
 * Defense in depth: the server only stores validated https URLs, but never
 * bind anything else to an href.
 *
 * @param url Stored link URL.
 */
function safeHref(url: string): string | undefined {
	return url.startsWith('https://') ? url : undefined
}

function readableError(reason: unknown): string {
	return reason instanceof Error && reason.message !== '' ? reason.message : t('maintenance_tracker', 'The request could not be completed.')
}

async function load(): Promise<void> {
	try {
		const list = await getLinks(props.subjectType, props.subjectUuid)
		enabled.value = list.enabled
		links.value = list.items
	} catch (reason) {
		error.value = readableError(reason)
	}
}

async function mutate(operation: () => Promise<unknown>): Promise<void> {
	saving.value = true
	error.value = ''
	try {
		await operation()
		await load()
	} catch (reason) {
		error.value = readableError(reason)
	} finally {
		saving.value = false
	}
}

async function submit(): Promise<void> {
	await mutate(async () => {
		await createLink(props.subjectType, props.subjectUuid, { uuid: crypto.randomUUID(), label: draft.label, url: draft.url.trim() })
		Object.assign(draft, { label: '', url: '' })
		adding.value = false
	})
}

onMounted(load)
</script>

<template>
	<div v-if="enabled" class="outside-links">
		<p v-if="error" class="outside-links-error" role="alert">
			{{ error }}
		</p>
		<ul v-if="links.length > 0">
			<li v-for="link in links" :key="link.uuid">
				<NcIconSvgWrapper :path="mdiOpenInNew" :size="16" />
				<a :href="safeHref(link.url)" target="_blank" rel="noopener noreferrer">{{ link.label }}</a>
				<NcButton
					variant="tertiary"
					size="small"
					:disabled="saving"
					@click="mutate(() => archiveLink(subjectType, subjectUuid, link.uuid, link.revision))">
					{{ t('maintenance_tracker', 'Remove') }}
				</NcButton>
			</li>
		</ul>
		<NcButton v-if="!adding" variant="tertiary" @click="adding = true">
			{{ t('maintenance_tracker', 'Add link') }}
		</NcButton>
		<form v-else class="outside-links-form" @submit.prevent="submit">
			<NcTextField
				v-model="draft.label"
				required
				maxlength="255"
				:label="t('maintenance_tracker', 'Link label')" />
			<NcTextField
				v-model="draft.url"
				type="url"
				required
				maxlength="2048"
				pattern="https://.+"
				:placeholder="t('maintenance_tracker', 'https://')"
				:label="t('maintenance_tracker', 'Link URL')" />
			<NcButton type="submit" :disabled="saving">
				{{ t('maintenance_tracker', 'Save') }}
			</NcButton>
		</form>
	</div>
</template>

<style scoped>
.outside-links {
	display: grid;
	gap: 6px;
}

.outside-links ul {
	display: grid;
	gap: 2px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.outside-links li {
	display: flex;
	align-items: center;
	gap: 8px;
}

.outside-links a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.outside-links-form {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: 8px;
	align-items: end;
}

.outside-links-error {
	color: var(--color-text-error, var(--color-error));
}
</style>
