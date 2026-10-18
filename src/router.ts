/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createRouter, createWebHashHistory } from 'vue-router'
import DashboardView from './views/DashboardView.vue'
import ItemDetailView from './views/ItemDetailView.vue'
import ItemsView from './views/ItemsView.vue'
import PartsView from './views/PartsView.vue'
import ProfileEditorView from './views/ProfileEditorView.vue'
import ProfilesView from './views/ProfilesView.vue'
import SettingsView from './views/SettingsView.vue'
import VendorsView from './views/VendorsView.vue'
import { t } from './l10n.ts'

// Hash history: the app page is served by one controller route, so deep links
// such as /apps/maintenance_tracker/#/items/<uuid> need no server routes.
export const router = createRouter({
	history: createWebHashHistory(),
	routes: [
		{ path: '/', name: 'dashboard', component: DashboardView, meta: { title: () => t('maintenance_tracker', 'Dashboard') } },
		{ path: '/items', name: 'items', component: ItemsView, meta: { title: () => t('maintenance_tracker', 'Items') } },
		{ path: '/items/:uuid/:tab?', name: 'item', component: ItemDetailView, props: true, meta: { title: () => t('maintenance_tracker', 'Item') } },
		{ path: '/parts', name: 'parts', component: PartsView, meta: { title: () => t('maintenance_tracker', 'Parts') } },
		{ path: '/vendors', name: 'vendors', component: VendorsView, meta: { title: () => t('maintenance_tracker', 'Vendors') } },
		{ path: '/profiles', name: 'profiles', component: ProfilesView, meta: { title: () => t('maintenance_tracker', 'Profiles') } },
		{ path: '/profiles/:uuid', name: 'profile', component: ProfileEditorView, props: true, meta: { title: () => t('maintenance_tracker', 'Profile') } },
		{ path: '/settings/:section?', name: 'settings', component: SettingsView, props: true, meta: { title: () => t('maintenance_tracker', 'Settings') } },
		{ path: '/:pathMatch(.*)*', redirect: '/' },
	],
	scrollBehavior: () => ({ top: 0 }),
})

declare module 'vue-router' {
	interface RouteMeta {
		title?: () => string
	}
}
