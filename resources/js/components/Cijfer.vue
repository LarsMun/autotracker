<script setup>
import { cijferBadgeClass, cijferText } from '../lib/wensen'

// The rapportcijfer as a badge; size lg on the detail page.
defineProps({ value: Number, size: { type: String, default: 'md' } })
</script>

<template>
    <span class="tabular inline-flex items-center justify-center rounded-lg font-semibold" :class="[cijferBadgeClass(value), size === 'lg' ? 'min-w-16 px-3 py-2 text-3xl' : 'min-w-12 px-2 py-1 text-xl']"
          :title="value == null ? 'Geen cijfer: geen enkel vak telt mee' : `Rapportcijfer ${cijferText(value)}`">{{ cijferText(value) }}</span>
</template>
