<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Page heading that receives focus after navigation (see App.vue).
defineProps<{ title: string, subtitle?: string }>()
</script>

<template>
	<header class="page-header">
		<div class="page-header__text">
			<h2 class="page-header__title" tabindex="-1" data-page-heading>
				{{ title }}
			</h2>
			<p v-if="subtitle" class="page-header__subtitle">
				{{ subtitle }}
			</p>
		</div>
		<div class="page-header__actions">
			<slot name="actions" />
		</div>
	</header>
</template>

<style scoped>
.page-header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
}

.page-header__title {
	margin: 0;
	font-size: 22px;
	line-height: 1.3;
}

.page-header__title:focus,
.page-header__title:focus-visible {
	/* Focus lands here programmatically after navigation; it is not a control. */
	outline: none;
}

.page-header__subtitle {
	margin: 2px 0 0;
	color: var(--color-text-maxcontrast);
}

.page-header__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
