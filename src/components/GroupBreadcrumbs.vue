<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// "Items › House › Basement" trail for a group (and optionally the current item).
import { computed } from 'vue'
import { t } from '../l10n.ts'
import { groupPath } from '../store.ts'

const props = defineProps<{ groupUuid: string | null, current?: string }>()

const path = computed(() => groupPath(props.groupUuid))
</script>

<template>
	<nav class="breadcrumbs" :aria-label="t('maintenance_tracker', 'Breadcrumbs')">
		<ol>
			<li>
				<RouterLink :to="{ name: 'items' }">
					{{ t('maintenance_tracker', 'Items') }}
				</RouterLink>
			</li>
			<li v-for="(group, index) in path" :key="group.uuid">
				<span v-if="index === path.length - 1 && current === undefined" aria-current="page">{{ group.name }}</span>
				<RouterLink v-else :to="{ name: 'items', query: { group: group.uuid } }">
					{{ group.name }}
				</RouterLink>
			</li>
			<li v-if="current !== undefined">
				<span aria-current="page">{{ current }}</span>
			</li>
		</ol>
	</nav>
</template>

<style scoped>
.breadcrumbs ol {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 0 0 4px;
	padding: 0;
	list-style: none;
	color: var(--color-text-maxcontrast);
}

.breadcrumbs li + li::before {
	content: '›';
	margin-inline-end: 4px;
}

.breadcrumbs a {
	color: var(--color-text-maxcontrast);
}

.breadcrumbs a:hover,
.breadcrumbs a:focus-visible {
	color: var(--color-main-text);
	text-decoration: underline;
}

.breadcrumbs [aria-current] {
	color: var(--color-main-text);
}
</style>
