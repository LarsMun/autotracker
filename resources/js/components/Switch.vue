<script setup>
defineProps({ label: String })
const model = defineModel({ type: Boolean })
</script>

<template>
    <button type="button" role="switch" :aria-checked="model" :aria-label="label"
            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
            :class="model ? 'bg-teal-700' : 'bg-stone-300'" @click="model = !model">
        <span class="inline-block size-4 rounded-full bg-white shadow transition-transform" :class="model ? 'translate-x-4.5' : 'translate-x-0.5'"></span>
    </button>
</template>
