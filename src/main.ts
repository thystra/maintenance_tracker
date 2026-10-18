/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router.ts'

createApp(App).use(router).mount('#maintenance_tracker')
