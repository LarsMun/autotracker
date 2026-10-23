<script setup>
import { ref } from 'vue'

defineProps({ text: String })
const open = ref(false)
</script>

<template>
    <span class="relative inline-block align-middle">
        <button type="button" class="flex size-4 items-center justify-center rounded-full border border-stone-400 text-[10px] leading-none font-semibold text-stone-500 hover:border-teal-700 hover:text-teal-700"
                :aria-expanded="open" aria-label="Uitleg" @click="open = !open" @blur="open = false">i</button>
        <span v-if="open" role="tooltip" class="absolute top-6 left-1/2 z-10 w-60 -translate-x-1/2 rounded-md bg-stone-900 px-3 py-2 text-xs font-normal text-white shadow-lg">{{ text }}</span>
    </span>
</template>
