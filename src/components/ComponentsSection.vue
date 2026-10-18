<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// Components (engine, battery, filter housing) and specifications
// (oil capacity, tyre pressure) of one item.
import type { Component, Specification } from '../types.ts'

import { mdiPlus } from '@mdi/js'
import { computed, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SelectField from './SelectField.vue'
import { errorMessage, keyFrom } from '../format.ts'
import { t } from '../l10n.ts'
import { createComponent, createSpecification, getSpecifications } from '../services/api.ts'
import { can } from '../store.ts'

const props = defineProps<{ assetUuid: string, components: Component[] }>()
const emit = defineEmits<{ (event: 'changed'): void }>()

const specifications = ref<Specification[]>([])
const dialog = ref<'component' | 'specification' | null>(null)
const busy = ref(false)
const error = ref('')
const formError = ref('')
const componentDraft = reactive({ name: '', type: '', parentUuid: null as string | null, manufacturer: '', model: '', partNumber: '', serialNumber: '' })
const specDraft = reactive({ label: '', value: '', unit: '', componentUuid: null as string | null })

const componentOptions = computed(() => props.components.map((component) => ({ value: component.uuid, label: component.name })))
const componentName = (uuid: string | null) => props.components.find((component) => component.uuid === uuid)?.name ?? ''

async function load(): Promise<void> {
	try {
		specifications.value = (await getSpecifications(props.assetUuid)).items
	} catch (reason) {
		error.value = errorMessage(reason)
	}
}

function open(kind: 'component' | 'specification'): void {
	Object.assign(componentDraft, { name: '', type: '', parentUuid: null, manufacturer: '', model: '', partNumber: '', serialNumber: '' })
	Object.assign(specDraft, { label: '', value: '', unit: '', componentUuid: null })
	formError.value = ''
	dialog.value = kind
}

async function save(): Promise<false | void> {
	busy.value = true
	formError.value = ''
	try {
		if (dialog.value === 'component') {
			if (componentDraft.name.trim() === '') {
				formError.value = t('maintenance_tracker', 'Enter a name.')
				return false
			}
			await createComponent(props.assetUuid, {
				uuid: crypto.randomUUID(),
				name: componentDraft.name.trim(),
				type: componentDraft.type.trim() || 'component',
				parentUuid: componentDraft.parentUuid,
				manufacturer: componentDraft.manufacturer.trim() || null,
				model: componentDraft.model.trim() || null,
				partNumber: componentDraft.partNumber.trim() || null,
				serialNumber: componentDraft.serialNumber.trim() || null,
			})
			emit('changed')
		} else {
			if (specDraft.label.trim() === '' || specDraft.value.trim() === '') {
				formError.value = t('maintenance_tracker', 'Enter a name and a value.')
				return false
			}
			await createSpecification(props.assetUuid, {
				uuid: crypto.randomUUID(),
				key: keyFrom(specDraft.label),
				label: specDraft.label.trim(),
				value: specDraft.value.trim(),
				unit: specDraft.unit.trim() || null,
				componentUuid: specDraft.componentUuid,
			})
			await load()
		}
		dialog.value = null
	} catch (reason) {
		formError.value = errorMessage(reason)
		return false
	} finally {
		busy.value = false
	}
}

const buttons = computed(() => [
	{ label: t('maintenance_tracker', 'Cancel'), callback: () => { dialog.value = null } },
	{ label: t('maintenance_tracker', 'Add'), type: 'submit' as const, variant: 'primary' as const, disabled: busy.value },
])

onMounted(load)
</script>

<template>
	<div class="columns">
		<NcNoteCard v-if="error" type="error">
			<p role="alert">
				{{ error }}
			</p>
		</NcNoteCard>
		<div>
			<div class="bar">
				<h4>{{ t('maintenance_tracker', 'Components') }}</h4>
				<NcButton v-if="can.manageInventory" variant="tertiary" @click="open('component')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('maintenance_tracker', 'Add component') }}
				</NcButton>
			</div>
			<p v-if="components.length === 0" class="muted">
				{{ t('maintenance_tracker', 'No components. Add parts of the item you track separately, such as the engine or a water heater element.') }}
			</p>
			<ul class="plain">
				<li v-for="component in components" :key="component.uuid">
					<strong>{{ component.name }}</strong>
					<span class="muted">{{ [component.type !== 'component' ? component.type : '', component.manufacturer, component.model, component.partNumber, componentName(component.parentUuid) ? t('maintenance_tracker', 'in {parent}', { parent: componentName(component.parentUuid) }) : ''].filter(Boolean).join(' · ') }}</span>
				</li>
			</ul>
		</div>
		<div>
			<div class="bar">
				<h4>{{ t('maintenance_tracker', 'Specifications') }}</h4>
				<NcButton v-if="can.manageInventory" variant="tertiary" @click="open('specification')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('maintenance_tracker', 'Add specification') }}
				</NcButton>
			</div>
			<p v-if="specifications.length === 0" class="muted">
				{{ t('maintenance_tracker', 'No specifications. Keep handy facts here: oil capacity, filter size, tyre pressure.') }}
			</p>
			<dl class="specs">
				<template v-for="spec in specifications" :key="spec.uuid">
					<dt>
						{{ spec.label }}<template v-if="componentName(spec.componentUuid)">
							({{ componentName(spec.componentUuid) }})
						</template>
					</dt>
					<dd>{{ typeof spec.value === 'object' ? JSON.stringify(spec.value) : String(spec.value) }} {{ spec.unit ?? '' }}</dd>
				</template>
			</dl>
		</div>

		<NcDialog
			:open="dialog !== null"
			:name="dialog === 'component' ? t('maintenance_tracker', 'Add component') : t('maintenance_tracker', 'Add specification')"
			:buttons="buttons"
			isForm
			@submit="save"
			@update:open="dialog = $event ? dialog : null">
			<div class="form-stack">
				<NcNoteCard v-if="formError" type="error">
					<p role="alert">
						{{ formError }}
					</p>
				</NcNoteCard>
				<template v-if="dialog === 'component'">
					<NcTextField
						v-model="componentDraft.name"
						:label="t('maintenance_tracker', 'Name')"
						maxlength="255"
						required />
					<NcTextField v-model="componentDraft.type" :label="t('maintenance_tracker', 'Kind (optional, e.g. engine, battery)')" maxlength="64" />
					<SelectField
						v-if="components.length > 0"
						v-model="componentDraft.parentUuid"
						:options="componentOptions"
						:label="t('maintenance_tracker', 'Part of (optional)')"
						clearable />
					<div class="form-row">
						<NcTextField v-model="componentDraft.manufacturer" :label="t('maintenance_tracker', 'Manufacturer')" />
						<NcTextField v-model="componentDraft.model" :label="t('maintenance_tracker', 'Model')" />
					</div>
					<div class="form-row">
						<NcTextField v-model="componentDraft.partNumber" :label="t('maintenance_tracker', 'Part number')" />
						<NcTextField v-model="componentDraft.serialNumber" :label="t('maintenance_tracker', 'Serial number')" />
					</div>
				</template>
				<template v-else>
					<NcTextField
						v-model="specDraft.label"
						:label="t('maintenance_tracker', 'Name')"
						:helperText="t('maintenance_tracker', 'For example “Engine oil capacity”.')"
						required />
					<div class="form-row">
						<NcTextField v-model="specDraft.value" :label="t('maintenance_tracker', 'Value')" required />
						<NcTextField v-model="specDraft.unit" :label="t('maintenance_tracker', 'Unit (optional)')" />
					</div>
					<SelectField
						v-if="components.length > 0"
						v-model="specDraft.componentUuid"
						:options="componentOptions"
						:label="t('maintenance_tracker', 'Component (optional)')"
						clearable />
				</template>
			</div>
		</NcDialog>
	</div>
</template>

<style scoped>
.columns {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 16px;
}

.bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.bar h4 {
	margin: 0;
	font-size: 15px;
}

.plain {
	display: grid;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.plain li {
	display: grid;
}

.specs {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 0;
}

.specs dt {
	color: var(--color-text-maxcontrast);
}

.specs dd {
	margin: 0;
}

.form-stack {
	display: grid;
	gap: 12px;
	padding-bottom: 8px;
}

.form-row {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 12px;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
