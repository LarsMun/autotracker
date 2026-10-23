<script setup>
import { merkdealerUitleg, sellerClass, sellerLabel } from '../lib/seller'

defineProps({ type: String, garantie: String })
</script>

<template>
    <span class="inline-flex flex-wrap gap-1">
        <span v-if="type" class="rounded px-1.5 py-0.5 text-[11px] font-medium" :class="sellerClass[type]" :title="type === 'merkdealer' ? merkdealerUitleg : null">{{ sellerLabel[type] }}</span>
        <span v-if="garantie === 'MERKGARANTIE'" class="rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] text-emerald-800">merkgarantie</span>
        <span v-else-if="garantie === 'BOVAG'" class="rounded bg-stone-50 px-1.5 py-0.5 text-[11px] text-stone-600 ring-1 ring-stone-200">BOVAG</span>
    </span>
</template>
