<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { severity } from '../format.ts'

defineProps<{ state: string, label: string }>()
</script>

<template>
	<span class="state-badge" :class="`state-badge--${severity(state)}`">{{ label }}</span>
</template>

<style scoped>
.state-badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	font-size: var(--font-size-small, 13px);
	font-weight: bold;
	white-space: nowrap;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.state-badge--error {
	background: var(--color-error);
	color: var(--color-error-text, #fff);
}

.state-badge--warning {
	background: var(--color-warning);
	color: var(--color-warning-text, #000);
}

.state-badge--ok {
	background: var(--color-success);
	color: var(--color-success-text, #fff);
}
</style>
