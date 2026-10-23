<script setup>
import { computed } from 'vue'

// A column header that sorts: the first click ascending, the next click the other way round.
// v-model is the sort key, "-" in front for descending ("prijs" / "-prijs").
const sort = defineModel({ type: String, required: true })
const props = defineProps({ column: String, label: String, align: { type: String, default: 'left' } })

const active = computed(() => sort.value.replace(/^-/, '') === props.column)
const descending = computed(() => active.value && sort.value.startsWith('-'))

const toggle = () => (sort.value = active.value && !descending.value ? `-${props.column}` : props.column)
</script>

<template>
    <th class="px-3 py-2 font-medium" :class="align === 'right' ? 'text-right' : 'text-left'" :aria-sort="active ? (descending ? 'descending' : 'ascending') : 'none'">
        <button type="button" class="inline-flex items-center gap-1 rounded hover:text-stone-900 focus-visible:outline-2 focus-visible:outline-teal-600"
                :class="[active ? 'text-stone-900' : '', align === 'right' ? 'flex-row-reverse' : '']" @click="toggle">
            {{ label }}
            <span aria-hidden="true" class="w-2 text-[10px]" :class="active ? 'text-teal-700' : 'text-stone-300'">{{ active ? (descending ? '▼' : '▲') : '↕' }}</span>
        </button>
    </th>
</template>
