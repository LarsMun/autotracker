<script setup>
import { comfortDots, comfortLabel } from '../lib/uitrusting'

defineProps({ niveau: Number, sport: Boolean })
</script>

<template>
    <span class="inline-flex items-center gap-1">
        <span v-if="niveau" class="text-[11px] leading-none tracking-tight text-teal-700" :title="`Comfortniveau ${niveau} van 3 (${comfortLabel[niveau]})`" :aria-label="`Comfortniveau ${niveau} van 3`">{{ comfortDots(niveau) }}</span>
        <span v-if="sport" class="rounded bg-red-50 px-1.5 py-0.5 text-[11px] font-medium text-red-800">sport</span>
    </span>
</template>
