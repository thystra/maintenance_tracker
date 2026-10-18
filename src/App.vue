<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { mdiCogOutline, mdiFolderPlusOutline, mdiPackageVariantClosed, mdiStorefrontOutline, mdiViewDashboardOutline, mdiWrenchOutline } from '@mdi/js'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import GroupDialogs from './components/GroupDialogs.vue'
import GroupNavItem from './components/GroupNavItem.vue'
import SelectField from './components/SelectField.vue'
import { errorMessage, roleLabel } from './format.ts'
import { openGroupDialog } from './groupActions.ts'
import { t } from './l10n.ts'
import { activeWorkspace, can, childGroups, loadAssets, loadCategories, loadGroups, loadWorkspaces, state, switchWorkspace } from './store.ts'

const route = useRoute()
const router = useRouter()
const error = ref('')
const announcement = ref('')

const workspaceOptions = computed(() => state.workspaces.map((workspace) => ({
	value: workspace.uuid,
	label: workspace.kind === 'personal'
		? t('maintenance_tracker', '{name} (personal)', { name: workspace.name })
		: t('maintenance_tracker', '{name} ({role})', { name: workspace.name, role: roleLabel(workspace.role) }),
})))

const workspaceChoice = computed({
	get: () => activeWorkspace.value?.uuid ?? null,
	set: (uuid: string | null) => {
		if (uuid !== null && uuid !== activeWorkspace.value?.uuid) {
			void changeWorkspace(uuid)
		}
	},
})

async function changeWorkspace(uuid: string): Promise<void> {
	error.value = ''
	try {
		await switchWorkspace(uuid)
		announcement.value = t('maintenance_tracker', 'Switched to workspace {name}.', { name: activeWorkspace.value?.name ?? '' })
		if (route.name === 'item') {
			await router.push({ name: 'items' })
		}
	} catch (reason) {
		error.value = errorMessage(reason)
	}
}

// Page titles and focus management: NcAppContent puts the page heading into the
// document title and a visually hidden h1; after each navigation focus moves to the page heading so screen-reader and
// keyboard users land at the start of the new content.
const topGroups = computed(() => childGroups(null))
const groupsOpen = ref(true)

function groupsChanged(message: string): void {
	announcement.value = message
}

const pageHeading = computed(() => state.pageTitle || (route.meta.title?.() ?? t('maintenance_tracker', 'Maintenance Tracker')))

// Switching tabs inside a page keeps the heading and focus where they are.
watch(() => `${String(route.name)}|${String(route.params.uuid ?? '')}|${String(route.params.section ?? '')}`, async (now, before) => {
	if (now.split('|').slice(0, 2).join('|') !== before?.split('|').slice(0, 2).join('|')) {
		state.pageTitle = ''
	}
	// No focus move on the first navigation: the page just loaded.
	if (before === undefined || before.startsWith('undefined|')) {
		return
	}
	await nextTick()
	window.setTimeout(() => document.querySelector<HTMLElement>('#maintenance-main [data-page-heading]')?.focus(), 50)
}, { immediate: true })

onMounted(async () => {
	try {
		await loadWorkspaces()
		await Promise.all([loadAssets(), loadCategories(), loadGroups()])
	} catch (reason) {
		error.value = errorMessage(reason)
	}
})
</script>

<template>
	<NcContent appName="maintenance_tracker">
		<NcAppNavigation :aria-label="t('maintenance_tracker', 'Maintenance Tracker navigation')">
			<template #search>
				<div class="workspace-switcher">
					<SelectField
						v-model="workspaceChoice"
						:options="workspaceOptions"
						:label="t('maintenance_tracker', 'Workspace')"
						:disabled="workspaceOptions.length === 0" />
				</div>
			</template>
			<template #list>
				<NcAppNavigationItem :name="t('maintenance_tracker', 'Dashboard')" :to="{ name: 'dashboard' }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiViewDashboardOutline" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('maintenance_tracker', 'Items')"
					:to="{ name: 'items' }"
					:active="(route.name === 'items' && !route.query.group) || route.name === 'item'"
					:allowCollapse="topGroups.length > 0"
					:open="groupsOpen"
					@update:open="groupsOpen = $event">
					<template #icon>
						<NcIconSvgWrapper :path="mdiWrenchOutline" />
					</template>
					<template #counter>
						<NcCounterBubble v-if="state.assetsLoaded" :count="state.assets.length" />
					</template>
					<template v-if="can.manageInventory" #actions>
						<NcActionButton closeAfterClick @click="openGroupDialog('create')">
							<template #icon>
								<NcIconSvgWrapper :path="mdiFolderPlusOutline" />
							</template>
							{{ t('maintenance_tracker', 'New group') }}
						</NcActionButton>
					</template>
					<GroupNavItem v-for="group in topGroups" :key="group.uuid" :group="group" />
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('maintenance_tracker', 'Parts')" :to="{ name: 'parts' }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPackageVariantClosed" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('maintenance_tracker', 'Vendors')" :to="{ name: 'vendors' }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiStorefrontOutline" />
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<ul class="app-navigation-footer">
					<NcAppNavigationItem :name="t('maintenance_tracker', 'Settings')" :to="{ name: 'settings' }" :active="route.name === 'settings'">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCogOutline" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>
		<NcAppContent :pageHeading="pageHeading">
			<main id="maintenance-main" class="maintenance-main">
				<p class="hidden-visually" role="status" aria-live="polite">
					{{ announcement }}
				</p>
				<NcNoteCard v-if="error" type="error" role="alert">
					{{ error }}
				</NcNoteCard>
				<p v-if="activeWorkspace?.orphaned" class="orphaned" role="status">
					{{ t('maintenance_tracker', 'This workspace has no owner because the owner\'s account was deleted. A Nextcloud administrator can assign one with') }}
					<code>occ maintenance_tracker:workspace:assign-owner</code>.
				</p>
				<NcLoadingIcon v-if="!state.loaded && !error" :size="44" :name="t('maintenance_tracker', 'Loading')" />
				<RouterView v-else-if="state.loaded" :key="state.generation" />
			</main>
			<GroupDialogs @changed="groupsChanged" />
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.workspace-switcher {
	padding: 4px 8px 8px;
}

.app-navigation-footer {
	padding: 4px 0 8px;
}

.maintenance-main {
	box-sizing: border-box;
	max-width: 1100px;
	margin: 0 auto;
	padding: 12px 20px 48px 56px;
}

.orphaned {
	padding: 8px 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

@media (max-width: 1024px) {
	.maintenance-main {
		padding: 12px 12px 48px 56px;
	}
}
</style>
