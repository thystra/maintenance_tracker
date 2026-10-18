<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import ExportPanel from '../ExportPanel.vue'
import ImportPanel from '../ImportPanel.vue'
import { roleLabel } from '../../format.ts'
import { activeWorkspace, loadWorkspaces, switchWorkspace } from '../../store.ts'

async function restored(uuid: string): Promise<void> {
	await loadWorkspaces()
	await switchWorkspace(uuid)
}
</script>

<template>
	<div class="mt-section">
		<ExportPanel v-if="activeWorkspace" :workspace="activeWorkspace.uuid" :role="roleLabel(activeWorkspace.role)" />
		<ImportPanel @imported="restored" />
	</div>
</template>
