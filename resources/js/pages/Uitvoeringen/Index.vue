<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '../../layouts/AppLayout.vue'
import { num } from '../../lib/format'

const props = defineProps({ models: Array, per_model: Number })

const withGaps = computed(() => props.models.filter((m) => m.niet_herkend > 0))
const recognised = computed(() => props.models.filter((m) => m.niet_herkend === 0))
const pct = (m) => Math.round((m.niet_herkend / m.totaal) * 100)
</script>

<template>
    <Head title="Niet herkende uitvoeringen" />
    <AppLayout>
        <p class="text-sm"><Link href="/analyse" class="text-teal-700 hover:underline">← Analyse</Link></p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight">Niet herkende uitvoeringen</h1>
        <p class="mt-1 max-w-3xl text-sm text-stone-500">
            Titels waarin geen uitvoeringslijn uit <code class="rounded bg-stone-100 px-1">config/uitrusting.php</code> voorkomt. Hun comfortniveau komt dan alleen uit de optiepunten.
            Ter informatie: aanvullen gaat in dat bestand, daarna <code class="rounded bg-stone-100 px-1">php artisan auto:uitrusting</code>.
        </p>

        <div class="mt-6 flex flex-col gap-6">
            <section v-for="m in withGaps" :key="m.merk + m.model" class="rounded-lg border border-stone-200 bg-white">
                <header class="flex flex-wrap items-baseline justify-between gap-2 border-b border-stone-100 px-4 py-3">
                    <h2 class="font-semibold">{{ m.merk }} {{ m.model }}</h2>
                    <p class="tabular text-sm text-stone-500">{{ num(m.niet_herkend) }} van {{ num(m.totaal) }} niet herkend ({{ pct(m) }}%)</p>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-stone-100">
                            <tr v-for="t in m.titels" :key="t.uitvoering">
                                <td class="tabular w-12 px-4 py-1.5 text-right text-stone-500">{{ t.aantal }}×</td>
                                <td class="px-2 py-1.5">{{ t.uitvoering || '(leeg)' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="m.niet_herkend > m.titels.reduce((s, t) => s + t.aantal, 0)" class="px-4 py-2 text-xs text-stone-400">De {{ per_model }} meest voorkomende titels.</p>
            </section>
            <p v-if="!withGaps.length" class="rounded-lg border border-dashed border-stone-300 px-4 py-6 text-center text-sm text-stone-500">Bij elke auto is een uitvoeringslijn herkend.</p>
        </div>

        <p v-if="recognised.length" class="mt-6 text-sm text-stone-500">
            Volledig herkend: <span v-for="(m, i) in recognised" :key="m.merk + m.model">{{ i ? ', ' : '' }}{{ m.merk }} {{ m.model }} ({{ m.totaal }})</span>.
        </p>
    </AppLayout>
</template>
