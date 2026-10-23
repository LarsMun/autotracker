<script setup>
import { statusLabel } from '../lib/format'

defineProps({ status: String })

const classes = {
    running: 'bg-sky-100 text-sky-800',
    success: 'bg-emerald-100 text-emerald-800',
    incomplete: 'bg-amber-100 text-amber-800',
    failed: 'bg-red-100 text-red-800',
}
</script>

<template>
    <span v-if="status" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium" :class="classes[status]">
        <span v-if="status === 'running'" class="size-1.5 animate-pulse rounded-full bg-sky-500"></span>
        {{ statusLabel[status] ?? status }}
    </span>
    <span v-else class="text-xs text-stone-400">nog niet gedraaid</span>
</template>
