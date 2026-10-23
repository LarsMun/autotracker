<script setup>
import { Link } from '@inertiajs/vue3'
import StatusBadge from './StatusBadge.vue'
import { dateTime, duration, num } from '../lib/format'

defineProps({ runs: Array, showSource: { type: Boolean, default: true } })
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-stone-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-stone-200 bg-stone-50 text-left text-xs text-stone-500">
                <tr>
                    <th class="px-3 py-2 font-medium">Gestart</th>
                    <th v-if="showSource" class="px-3 py-2 font-medium">Bron</th>
                    <th class="px-3 py-2 font-medium">Status</th>
                    <th class="px-3 py-2 text-right font-medium">Pagina's</th>
                    <th class="px-3 py-2 text-right font-medium">Gezien / totaal</th>
                    <th class="px-3 py-2 text-right font-medium">Nieuw</th>
                    <th class="px-3 py-2 text-right font-medium">Verdwenen</th>
                    <th class="px-3 py-2 font-medium">Duur</th>
                </tr>
            </thead>
            <tbody class="tabular divide-y divide-stone-100">
                <tr v-for="run in runs" :key="run.id" class="hover:bg-stone-50">
                    <td class="px-3 py-2 whitespace-nowrap">
                        <Link :href="`/runs/${run.id}`" class="text-teal-800 hover:underline">{{ dateTime(run.started_at) }}</Link>
                        <span v-if="run.trigger === 'manual'" class="ml-1 text-xs text-stone-400">handmatig</span>
                    </td>
                    <td v-if="showSource" class="px-3 py-2">{{ run.source }}</td>
                    <td class="px-3 py-2">
                        <StatusBadge :status="run.status" />
                        <p v-if="run.error" class="mt-0.5 max-w-sm truncate text-xs text-red-700" :title="run.error">{{ run.error }}</p>
                        <p v-else-if="run.warnings" class="mt-0.5 max-w-sm truncate text-xs text-amber-700" :title="run.warnings">{{ run.warnings }}</p>
                    </td>
                    <td class="px-3 py-2 text-right">{{ run.pages_fetched }}/{{ run.pages_expected ?? '?' }}</td>
                    <td class="px-3 py-2 text-right">{{ num(run.listings_seen) }} / {{ num(run.total_reported) }}</td>
                    <td class="px-3 py-2 text-right">{{ num(run.new_count) }}</td>
                    <td class="px-3 py-2 text-right">{{ run.status === 'success' ? num(run.gone_count) : '–' }}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-stone-500">{{ duration(run.duration) }}</td>
                </tr>
                <tr v-if="!runs.length">
                    <td colspan="8" class="px-3 py-6 text-center text-stone-400">Nog geen runs.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
