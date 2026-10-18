<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// A lightweight economy-over-time chart: one dot per measured full-to-full
// segment, hollow dots for segments that mixed regimes. Plain SVG, no chart
// library; the same values are in a visually hidden table for screen readers.
import type { EconomyUnit, FuelStats } from '../fuelTypes.ts'

import { computed } from 'vue'
import { formatDate } from '../format.ts'
import { economyUnitLabel, formatEconomy, formatNumber } from '../fuelFormat.ts'
import { n, t } from '../l10n.ts'

const props = defineProps<{ points: FuelStats['chart'], unit: EconomyUnit, regimeNames: Record<string, string> }>()

const WIDTH = 600
const HEIGHT = 180
const PAD = { left: 44, right: 12, top: 12, bottom: 24 }

const values = computed(() => props.points.filter((point) => point.economy !== null) as Array<FuelStats['chart'][number] & { economy: number }>)
const range = computed(() => {
	const list = values.value.map((point) => point.economy)
	const min = Math.min(...list)
	const max = Math.max(...list)
	const spread = max - min || Math.max(1, max * 0.1)
	return { min: Math.max(0, min - spread * 0.15), max: max + spread * 0.15 }
})
const dots = computed(() => values.value.map((point, index) => {
	const x = PAD.left + (values.value.length === 1 ? (WIDTH - PAD.left - PAD.right) / 2 : index * (WIDTH - PAD.left - PAD.right) / (values.value.length - 1))
	const y = PAD.top + (1 - (point.economy - range.value.min) / (range.value.max - range.value.min)) * (HEIGHT - PAD.top - PAD.bottom)
	return { ...point, x, y }
}))
const line = computed(() => dots.value.map((dot) => `${dot.x.toFixed(1)},${dot.y.toFixed(1)}`).join(' '))
const caption = computed(() => values.value.length === 0
	? ''
	: n('maintenance_tracker', 'Fuel economy of %n measured segment from {from} to {to}', 'Fuel economy of %n measured segments from {from} to {to}', values.value.length, {
			from: formatDate(values.value[0]!.date),
			to: formatDate(values.value[values.value.length - 1]!.date),
		}))

/**
 * Regime label of a chart point.
 *
 * @param point The point.
 */
function regimeOf(point: FuelStats['chart'][number]): string {
	if (point.mixed) {
		return t('maintenance_tracker', 'Mixed regimes')
	}
	return point.regimeUuid ? (props.regimeNames[point.regimeUuid] ?? '') : t('maintenance_tracker', 'No regime')
}
</script>

<template>
	<figure v-if="values.length > 0" class="fuel-chart">
		<svg
			:viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
			role="img"
			:aria-label="caption">
			<line
				:x1="PAD.left"
				:x2="WIDTH - PAD.right"
				:y1="HEIGHT - PAD.bottom"
				:y2="HEIGHT - PAD.bottom"
				class="axis" />
			<text
				:x="PAD.left - 6"
				:y="PAD.top + 4"
				text-anchor="end"
				class="label">{{ formatNumber(range.max) }}</text>
			<text
				:x="PAD.left - 6"
				:y="HEIGHT - PAD.bottom"
				text-anchor="end"
				class="label">{{ formatNumber(range.min) }}</text>
			<text :x="PAD.left" :y="HEIGHT - 6" class="label">{{ formatDate(values[0]!.date) }}</text>
			<text
				:x="WIDTH - PAD.right"
				:y="HEIGHT - 6"
				text-anchor="end"
				class="label">{{ formatDate(values[values.length - 1]!.date) }}</text>
			<polyline v-if="dots.length > 1" :points="line" class="line" />
			<circle
				v-for="(dot, index) in dots"
				:key="index"
				:cx="dot.x"
				:cy="dot.y"
				r="4"
				:class="dot.mixed ? 'dot dot--mixed' : 'dot'">
				<title>{{ formatDate(dot.date) }}: {{ formatEconomy(dot.economy, unit) }} · {{ regimeOf(dot) }}</title>
			</circle>
		</svg>
		<figcaption class="muted">
			{{ caption }} ({{ economyUnitLabel(unit) }}).
			<span v-if="dots.some((dot) => dot.mixed)">{{ t('maintenance_tracker', 'Hollow dots mixed regimes.') }}</span>
		</figcaption>
		<table class="hidden-visually">
			<caption>{{ caption }}</caption>
			<thead>
				<tr>
					<th scope="col">
						{{ t('maintenance_tracker', 'Date') }}
					</th>
					<th scope="col">
						{{ t('maintenance_tracker', 'Economy') }}
					</th>
					<th scope="col">
						{{ t('maintenance_tracker', 'Regime') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(point, index) in values" :key="index">
					<td>{{ formatDate(point.date) }}</td>
					<td>{{ formatEconomy(point.economy, unit) }}</td>
					<td>{{ regimeOf(point) }}</td>
				</tr>
			</tbody>
		</table>
	</figure>
</template>

<style scoped>
.fuel-chart {
	margin: 0;
}

.fuel-chart svg {
	display: block;
	width: 100%;
	max-width: 760px;
	height: auto;
}

.axis {
	stroke: var(--color-border-dark);
	stroke-width: 1;
}

.line {
	fill: none;
	stroke: var(--color-primary-element);
	stroke-width: 2;
	vector-effect: non-scaling-stroke;
}

.dot {
	fill: var(--color-primary-element);
	stroke: var(--color-primary-element);
	stroke-width: 2;
}

.dot--mixed {
	fill: var(--color-main-background);
}

.label {
	font-size: 11px;
	fill: var(--color-text-maxcontrast);
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
