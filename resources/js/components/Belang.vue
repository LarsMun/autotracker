<script setup>
import { belangLabel } from '../lib/wensen'

defineProps({ label: String, disabled: Boolean })
const model = defineModel({ type: Number })
</script>

<template>
    <div role="radiogroup" :aria-label="`Belang ${label}`" class="inline-flex rounded-md border border-stone-300 bg-white p-0.5 text-xs" :class="{ 'opacity-50': disabled }">
        <button v-for="(text, weight) in belangLabel" :key="weight" type="button" role="radio" :aria-checked="model === Number(weight)" :disabled="disabled"
                class="whitespace-nowrap rounded px-2.5 py-1.5 transition-colors disabled:cursor-not-allowed"
                :class="model === Number(weight) ? 'bg-teal-700 font-medium text-white' : 'text-stone-600 enabled:hover:bg-stone-100'"
                @click="model = Number(weight)">
            {{ text }}
        </button>
    </div>
</template>
