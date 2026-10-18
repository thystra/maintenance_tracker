<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
// One item group in the sidebar tree, with its subgroups nested below it.
import type { ItemGroup } from '../groupTypes.ts'

import { mdiArrowRightBold, mdiDeleteOutline, mdiFolderOutline, mdiFolderPlusOutline, mdiPencilOutline } from '@mdi/js'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { openGroupDialog } from '../groupActions.ts'
import { t } from '../l10n.ts'
import { can, childGroups, groupPath, itemsInGroup } from '../store.ts'

defineOptions({ name: 'GroupNavItem' })

const props = defineProps<{ group: ItemGroup }>()

const route = useRoute()
const router = useRouter()
const children = computed(() => childGroups(props.group.uuid))
const count = computed(() => itemsInGroup(props.group.uuid).length)
const active = computed(() => route.name === 'items' && route.query.group === props.group.uuid)
const open = ref(false)

// Keep the branch leading to the selected group expanded.
watch(() => route.query.group, (selected) => {
	if (typeof selected === 'string' && selected !== props.group.uuid && groupPath(selected).some((entry) => entry.uuid === props.group.uuid)) {
		open.value = true
	}
}, { immediate: true })

function show(): void {
	void router.push({ name: 'items', query: { group: props.group.uuid } })
}
</script>

<template>
	<NcAppNavigationItem
		:name="group.name"
		:active="active"
		:allowCollapse="children.length > 0"
		:open="open"
		@update:open="open = $event"
		@click="show">
		<template #icon>
			<NcIconSvgWrapper :path="mdiFolderOutline" />
		</template>
		<template #counter>
			<NcCounterBubble v-if="count > 0" :count="count" />
		</template>
		<template v-if="can.manageInventory" #actions>
			<NcActionButton closeAfterClick @click="openGroupDialog('create', null, group.uuid)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFolderPlusOutline" />
				</template>
				{{ t('maintenance_tracker', 'Add subgroup') }}
			</NcActionButton>
			<NcActionButton closeAfterClick @click="openGroupDialog('rename', group)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPencilOutline" />
				</template>
				{{ t('maintenance_tracker', 'Rename') }}
			</NcActionButton>
			<NcActionButton closeAfterClick @click="openGroupDialog('move', group)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArrowRightBold" />
				</template>
				{{ t('maintenance_tracker', 'Move') }}
			</NcActionButton>
			<NcActionButton closeAfterClick @click="openGroupDialog('delete', group)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiDeleteOutline" />
				</template>
				{{ t('maintenance_tracker', 'Delete') }}
			</NcActionButton>
		</template>
		<GroupNavItem v-for="child in children" :key="child.uuid" :group="child" />
	</NcAppNavigationItem>
</template>
