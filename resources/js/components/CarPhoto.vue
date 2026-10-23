<script setup>
import { ref } from 'vue'

defineProps({ src: String, alt: String })
const failed = ref(false)
</script>

<template>
    <div class="overflow-hidden rounded-md bg-stone-200">
        <img v-if="src && !failed" :src="src" :alt="alt" loading="lazy" referrerpolicy="no-referrer" class="size-full object-cover" @error="failed = true" />
        <div v-else class="flex size-full items-center justify-center text-xs text-stone-400">geen foto</div>
    </div>
</template>
