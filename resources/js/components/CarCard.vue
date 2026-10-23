<script setup>
import CarPhoto from './CarPhoto.vue'
import { euro, km } from '../lib/format'

defineProps({ car: Object })
</script>

<template>
    <div class="flex gap-3 rounded-lg border border-stone-200 bg-white p-2">
        <CarPhoto :src="car.foto" :alt="car.title" class="h-20 w-28 shrink-0" />
        <div class="min-w-0 flex-1 text-sm">
            <p class="truncate font-medium">{{ car.title }}</p>
            <p class="truncate text-xs text-stone-500" :title="car.uitvoering">{{ car.uitvoering }}</p>
            <p class="tabular mt-1 text-xs text-stone-600">
                {{ car.bouwjaar }} · {{ km(car.kilometerstand) }}<template v-if="car.pk"> · {{ car.pk }} pk</template> · {{ car.plaats }}
            </p>
            <p class="tabular mt-0.5 font-semibold">{{ euro(car.prijs) }}</p>
        </div>
    </div>
</template>
