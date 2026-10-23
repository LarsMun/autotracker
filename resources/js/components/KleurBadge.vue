<script setup>
import { kleurLabel, kleurSwatch } from '../lib/kleur'

defineProps({ kleur: String })
</script>

<template>
    <span v-if="kleur" class="inline-flex items-center gap-1 text-[11px] leading-none text-stone-600" :title="`Kleur: ${kleurLabel(kleur)}`">
        <span class="inline-block size-2.5 rounded-full ring-1 ring-stone-300" :style="{ backgroundColor: kleurSwatch(kleur) }" aria-hidden="true"></span>
        {{ kleurLabel(kleur) }}
    </span>
</template>
