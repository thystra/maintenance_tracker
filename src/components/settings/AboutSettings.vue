<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Settings → About: app identity and version from the capabilities API
// (info.xml / Application::APP_VERSION), never a hard-coded string.
import type { Capabilities } from '../../types.ts'

import { onMounted, ref } from 'vue'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { errorMessage } from '../../format.ts'
import { t } from '../../l10n.ts'
import { getCapabilities } from '../../services/api.ts'

const caps = ref<Capabilities | null>(null)
const error = ref('')

onMounted(async () => {
	try {
		caps.value = await getCapabilities()
	} catch (reason) {
		error.value = errorMessage(reason)
	}
})
</script>

<template>
	<div class="about">
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<dl v-if="caps" class="about-dl">
			<div>
				<dt>{{ t('maintenance_tracker', 'App') }}</dt>
				<dd>{{ caps.app }}</dd>
			</div>
			<div>
				<dt>{{ t('maintenance_tracker', 'Version') }}</dt>
				<dd data-test="app-version">
					{{ caps.appVersion }}
				</dd>
			</div>
			<div>
				<dt>{{ t('maintenance_tracker', 'API') }}</dt>
				<dd>{{ caps.apiVersion }} ({{ caps.apiStability }})</dd>
			</div>
		</dl>
		<p class="muted">
			{{ t('maintenance_tracker', 'Maintenance Tracker tracks items, schedules, fuel, costs and parts inside Nextcloud.') }}
		</p>
	</div>
</template>

<style scoped>
.about {
	display: grid;
	gap: 16px;
}

.about-dl {
	display: grid;
	gap: 8px;
	margin: 0;
}

.about-dl > div {
	display: grid;
	grid-template-columns: 120px minmax(0, 1fr);
	gap: 8px;
}

.about-dl dt {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.about-dl dd {
	margin: 0;
	font-weight: 600;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
