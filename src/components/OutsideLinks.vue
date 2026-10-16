<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { LinkSubjectType, OutsideLink } from '../catalogTypes.ts'

import { onMounted, reactive, ref } from 'vue'
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
	return reason instanceof Error && reason.message !== '' ? reason.message : 'The request could not be completed.'
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
				<a :href="safeHref(link.url)" target="_blank" rel="noopener noreferrer">{{ link.label }}</a>
				<button type="button" :disabled="saving" @click="mutate(() => archiveLink(subjectType, subjectUuid, link.uuid, link.revision))">
					Remove
				</button>
			</li>
		</ul>
		<button v-if="!adding" type="button" @click="adding = true">
			Add link
		</button>
		<form v-else class="outside-links-form" @submit.prevent="submit">
			<input
				v-model="draft.label"
				required
				maxlength="255"
				placeholder="Label"
				aria-label="Link label">
			<input
				v-model="draft.url"
				type="url"
				required
				maxlength="2048"
				pattern="https://.+"
				placeholder="https://"
				aria-label="Link URL">
			<button type="submit" :disabled="saving">
				Save
			</button>
		</form>
	</div>
</template>

<style scoped>
.outside-links { flex-basis: 100%; display: grid; gap: 4px; font-size: .9em; }

.outside-links ul { display: grid; gap: 2px; margin: 0; padding: 0; list-style: none; }

.outside-links li { display: flex; align-items: center; gap: 8px; }

.outside-links a { color: var(--color-primary-element); text-decoration: underline; }

.outside-links button { min-height: 28px; padding: 2px 10px; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius-pill); background: var(--color-main-background); color: var(--color-main-text); cursor: pointer; justify-self: start; }

.outside-links-form { display: flex; flex-wrap: wrap; gap: 6px; }

.outside-links-form input { min-height: 32px; padding: 4px 8px; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius); }

.outside-links-error { color: var(--color-error); }
</style>
