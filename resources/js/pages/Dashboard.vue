<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '../layouts/AppLayout.vue'
import RunsTable from '../components/RunsTable.vue'
import SourceForm from '../components/SourceForm.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { dateTime, num } from '../lib/format'
import { usePollWhileRunning } from '../lib/usePollWhileRunning'

const props = defineProps({ sources: Array, totals: Object, runs: Array })

usePollWhileRunning(() => props.sources.some((s) => s.last_run?.status === 'running'), ['sources', 'runs', 'totals', 'problems'])

const runAll = () => router.post('/sources/run', {}, { preserveScroll: true })
</script>

<template>
    <Head title="Bronnen" />
    <AppLayout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Bronnen</h1>
                <p class="mt-1 text-sm text-stone-500">Dagelijks alle auto's van de actieve Gaspedaal-zoekopdrachten.</p>
            </div>
            <button class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm hover:bg-stone-50" @click="runAll">
                Alle actieve bronnen nu draaien
            </button>
        </div>

        <SourceForm class="mt-6" />

        <dl class="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <dt class="text-xs text-stone-500">Auto's nu online</dt>
                <dd class="tabular mt-1 text-2xl font-semibold">{{ num(totals.current) }}</dd>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <dt class="text-xs text-stone-500">Auto's ooit gezien</dt>
                <dd class="tabular mt-1 text-2xl font-semibold">{{ num(totals.listings) }}</dd>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <dt class="text-xs text-stone-500">Waarnemingen</dt>
                <dd class="tabular mt-1 text-2xl font-semibold">{{ num(totals.observations) }}</dd>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <dt class="text-xs text-stone-500">Dagen met data</dt>
                <dd class="tabular mt-1 text-2xl font-semibold">{{ num(totals.days) }}</dd>
            </div>
        </dl>

        <h2 class="mt-8 mb-3 text-lg font-semibold">Bronnen</h2>
        <div class="overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left text-xs text-stone-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">Bron</th>
                        <th class="px-3 py-2 text-right font-medium">Nu online</th>
                        <th class="px-3 py-2 text-right font-medium">Nieuw</th>
                        <th class="px-3 py-2 text-right font-medium">Verdwenen</th>
                        <th class="px-3 py-2 font-medium">Laatste run</th>
                    </tr>
                </thead>
                <tbody class="tabular divide-y divide-stone-100">
                    <tr v-for="s in sources" :key="s.id" class="hover:bg-stone-50" :class="{ 'opacity-50': !s.active }">
                        <td class="px-3 py-2">
                            <Link :href="`/sources/${s.id}`" class="font-medium text-teal-800 hover:underline">{{ s.name }}</Link>
                            <span v-if="!s.active" class="ml-2 rounded bg-stone-200 px-1.5 text-xs">uit</span>
                            <p class="max-w-md truncate text-xs text-stone-400">{{ s.url }}</p>
                        </td>
                        <td class="px-3 py-2 text-right">{{ num(s.current) }}</td>
                        <td class="px-3 py-2 text-right">
                            <Link v-if="s.new" :href="`/sources/${s.id}?filter=new`" class="text-emerald-700 hover:underline">+{{ num(s.new) }}</Link>
                            <span v-else class="text-stone-300">0</span>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <Link v-if="s.gone" :href="`/sources/${s.id}?filter=gone_today`" class="text-red-700 hover:underline">−{{ num(s.gone) }}</Link>
                            <span v-else class="text-stone-300">0</span>
                        </td>
                        <td class="px-3 py-2">
                            <StatusBadge :status="s.last_run?.status" />
                            <span v-if="s.last_run" class="ml-2 text-xs text-stone-500">
                                {{ dateTime(s.last_run.started_at) }}
                                <template v-if="s.last_run.status === 'running'"> · pagina {{ s.last_run.pages_fetched }}/{{ s.last_run.pages_expected ?? '?' }}</template>
                                <template v-else> · {{ num(s.last_run.listings_seen) }}/{{ num(s.last_run.total_reported) }}</template>
                            </span>
                        </td>
                    </tr>
                    <tr v-if="!sources.length">
                        <td colspan="5" class="px-3 py-6 text-center text-stone-400">
                            Nog geen bronnen. Voeg er hierboven een toe.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-stone-500">Nieuw en verdwenen tellen ten opzichte van de vorige run. Verdwenen wordt alleen bepaald na een volledige, geslaagde run.</p>

        <div class="mt-8 mb-3 flex items-baseline justify-between">
            <h2 class="text-lg font-semibold">Recente runs</h2>
            <Link href="/runs" class="text-sm text-teal-700 hover:underline">Alle runs</Link>
        </div>
        <RunsTable :runs="runs" />
    </AppLayout>
</template>
