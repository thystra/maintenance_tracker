<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Settings, split into sections so each page stays short. Workspace-wide
// sections come first; personal delivery choices after. Server-wide options
// stay in Nextcloud's administration settings.
import { computed } from 'vue'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import PageHeader from '../components/PageHeader.vue'
import CategoriesSettings from '../components/settings/CategoriesSettings.vue'
import CostSettings from '../components/settings/CostSettings.vue'
import DataSettings from '../components/settings/DataSettings.vue'
import ForecastSettings from '../components/settings/ForecastSettings.vue'
import MembersSettings from '../components/settings/MembersSettings.vue'
import RemindersSettings from '../components/settings/RemindersSettings.vue'
import WorkspaceSettings from '../components/settings/WorkspaceSettings.vue'
import { t } from '../l10n.ts'

const props = defineProps<{ section?: string }>()

const sections = [
	{ id: 'workspace', label: t('maintenance_tracker', 'Workspaces'), description: t('maintenance_tracker', 'Switch, create and rename workspaces.'), component: WorkspaceSettings },
	{ id: 'members', label: t('maintenance_tracker', 'Members and ownership'), description: t('maintenance_tracker', 'Who can use this workspace, their roles, and handing it over.'), component: MembersSettings },
	{ id: 'costs', label: t('maintenance_tracker', 'Costs and currency'), description: t('maintenance_tracker', 'Currency, tax lines and multiple currencies.'), component: CostSettings },
	{ id: 'forecast', label: t('maintenance_tracker', 'Due soon window'), description: t('maintenance_tracker', 'How early tasks are flagged as due soon.'), component: ForecastSettings },
	{ id: 'reminders', label: t('maintenance_tracker', 'Reminders and calendar'), description: t('maintenance_tracker', 'Your notifications and calendar entries.'), component: RemindersSettings },
	{ id: 'categories', label: t('maintenance_tracker', 'Categories'), description: t('maintenance_tracker', 'How items are grouped.'), component: CategoriesSettings },
	{ id: 'data', label: t('maintenance_tracker', 'Export and restore'), description: t('maintenance_tracker', 'Download your data or restore a backup.'), component: DataSettings },
]

const active = computed(() => sections.find((section) => section.id === props.section) ?? sections[0]!)
</script>

<template>
	<div class="settings-page">
		<PageHeader :title="t('maintenance_tracker', 'Settings')" :subtitle="active.label" />
		<div class="settings-layout">
			<nav class="settings-nav" :aria-label="t('maintenance_tracker', 'Settings sections')">
				<ul>
					<li v-for="entry in sections" :key="entry.id">
						<RouterLink
							:to="{ name: 'settings', params: { section: entry.id === 'workspace' ? undefined : entry.id } }"
							:class="{ active: entry.id === active.id }"
							:aria-current="entry.id === active.id ? 'page' : undefined">
							{{ entry.label }}
						</RouterLink>
					</li>
				</ul>
			</nav>
			<section class="settings-body" :aria-labelledby="`settings-${active.id}`">
				<h2 :id="`settings-${active.id}`" class="settings-title">
					{{ active.label }}
				</h2>
				<p class="muted">
					{{ active.description }}
				</p>
				<component :is="active.component" :key="active.id" />
				<NcNoteCard type="info" class="admin-note">
					<p>{{ t('maintenance_tracker', 'Server-wide options, such as whether outside links are allowed, are in Nextcloud Administration settings under Maintenance Tracker.') }}</p>
				</NcNoteCard>
			</section>
		</div>
	</div>
</template>

<style scoped>
.settings-layout {
	display: grid;
	grid-template-columns: 220px minmax(0, 1fr);
	gap: 24px;
}

.settings-nav ul {
	display: grid;
	gap: 2px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.settings-nav a {
	display: block;
	padding: 8px 12px;
	border-radius: var(--border-radius-element, var(--border-radius-large));
	color: var(--color-main-text);
}

.settings-nav a:hover,
.settings-nav a:focus-visible {
	background: var(--color-background-hover);
}

.settings-nav a.active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: bold;
}

.settings-title {
	margin: 0;
	font-size: 20px;
}

.admin-note {
	margin-top: 32px;
}

.muted {
	color: var(--color-text-maxcontrast);
}

@media (max-width: 800px) {
	.settings-layout {
		grid-template-columns: 1fr;
	}

	.settings-nav ul {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;
	}
}
</style>

<style>
/* Shared layout for the section components. */
.settings-page .mt-section {
	display: grid;
	gap: 12px;
	max-width: 760px;
	margin-top: 12px;
}

.settings-page .mt-section h3 {
	margin: 12px 0 0;
	font-size: 16px;
}

.settings-page .mt-section .list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.settings-page .mt-section .inline-form {
	display: grid;
	gap: 10px;
	justify-items: start;
}

.settings-page .mt-section .row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: end;
	width: 100%;
}

.settings-page .mt-section .row > * {
	flex: 1 1 200px;
}

.settings-page .mt-section .row > button,
.settings-page .mt-section .row > .button-vue {
	flex: 0 0 auto;
}

.settings-page .mt-section .row.narrow {
	max-width: 360px;
}

.settings-page .mt-section .muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
