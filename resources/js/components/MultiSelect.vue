<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'

// v-model: the chosen values. Options: { value, label, n, group }; a new group gets a heading.
const props = defineProps({
    label: String,
    options: Array,
    placeholder: { type: String, default: 'Alle' },
    disabled: Boolean,
})
const selected = defineModel({ type: Array, default: () => [] })

const open = ref(false)
const root = ref(null)

const summary = computed(() => {
    if (!selected.value.length) return props.placeholder
    const labels = selected.value.map((v) => props.options.find((o) => o.value === v)?.label ?? v)
    return labels.length <= 2 ? labels.join(', ') : `${labels.length} gekozen`
})
const toggle = (value) => {
    selected.value = selected.value.includes(value) ? selected.value.filter((v) => v !== value) : [...selected.value, value]
}

const close = (e) => {
    if (e.type === 'keydown' ? e.key === 'Escape' : !root.value?.contains(e.target)) open.value = false
}
watch(open, (isOpen) => {
    const method = isOpen ? 'addEventListener' : 'removeEventListener'
    document[method]('mousedown', close)
    document[method]('keydown', close)
})
watch(() => props.disabled, (d) => d && (open.value = false))
onBeforeUnmount(() => (open.value = false))
</script>

<template>
    <div ref="root" class="relative text-xs text-stone-500">
        <span>{{ label }}</span>
        <button type="button" :disabled="disabled" :aria-expanded="open"
                class="mt-1 flex w-full min-w-48 items-center justify-between gap-2 rounded-md border border-stone-300 bg-white px-2 py-1.5 text-left text-sm text-stone-900 disabled:cursor-not-allowed disabled:bg-stone-50 disabled:text-stone-400"
                @click="open = !open">
            <span class="truncate">{{ summary }}</span>
            <span aria-hidden="true" class="text-stone-400">▾</span>
        </button>
        <div v-if="open" class="absolute z-20 mt-1 max-h-80 w-full min-w-56 overflow-y-auto rounded-md border border-stone-200 bg-white py-1 shadow-lg">
            <template v-for="(o, i) in options" :key="o.value">
                <p v-if="o.group && o.group !== options[i - 1]?.group" class="px-3 pb-0.5 pt-2 text-xs font-medium text-stone-500">{{ o.group }}</p>
                <label class="flex cursor-pointer items-center gap-2 px-3 py-1 text-sm text-stone-900 hover:bg-stone-50">
                    <input type="checkbox" class="accent-teal-700" :checked="selected.includes(o.value)" @change="toggle(o.value)" />
                    <span class="flex-1 truncate">{{ o.label }}</span>
                    <span class="tabular text-xs text-stone-400">{{ o.n }}</span>
                </label>
            </template>
            <p v-if="!options.length" class="px-3 py-1 text-sm text-stone-400">Niets te kiezen.</p>
        </div>
    </div>
</template>
