<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '../../layouts/AppLayout.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { dateTime, duration, num } from '../../lib/format'
import { usePollWhileRunning } from '../../lib/usePollWhileRunning'

const props = defineProps({ run: Object, pages: Array })

usePollWhileRunning(() => props.run.status === 'running', ['run', 'pages', 'problems'])
</script>

<template>
    <Head :title="`Run ${run.id}`" />
    <AppLayout>
        <p class="text-sm text-stone-500"><Link :href="`/sources/${run.source_id}`" class="text-teal-700 hover:underline">{{ run.source }}</Link></p>
        <h1 class="flex items-center gap-3 text-2xl font-semibold tracking-tight">Run {{ run.id }} <StatusBadge :status="run.status" /></h1>
        <p class="mt-1 text-sm text-stone-500">
            {{ dateTime(run.started_at) }} · {{ run.trigger === 'manual' ? 'handmatig' : 'dagelijks' }} · {{ duration(run.duration) }}
        </p>
        <p class="mt-1 truncate text-xs text-stone-400">{{ run.url }}</p>

        <div v-if="run.error" class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-900">{{ run.error }}</div>
        <div v-if="run.warnings" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm whitespace-pre-line text-amber-900">{{ run.warnings }}</div>

        <dl class="tabular mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
            <div class="rounded-lg border border-stone-200 bg-white p-3"><dt class="text-xs text-stone-500">Pagina's</dt><dd class="text-lg font-semibold">{{ run.pages_fetched }}/{{ run.pages_expected ?? '?' }}</dd></div>
            <div class="rounded-lg border border-stone-200 bg-white p-3"><dt class="text-xs text-stone-500">Gemeld door Gaspedaal</dt><dd class="text-lg font-semibold">{{ num(run.total_reported) }}</dd></div>
            <div class="rounded-lg border border-stone-200 bg-white p-3"><dt class="text-xs text-stone-500">Uniek gezien</dt><dd class="text-lg font-semibold">{{ num(run.listings_seen) }}</dd></div>
            <div class="rounded-lg border border-stone-200 bg-white p-3"><dt class="text-xs text-stone-500">Nieuw</dt><dd class="text-lg font-semibold">{{ num(run.new_count) }}</dd></div>
            <div class="rounded-lg border border-stone-200 bg-white p-3"><dt class="text-xs text-stone-500">Verdwenen</dt><dd class="text-lg font-semibold">{{ run.status === 'success' ? num(run.gone_count) : '–' }}</dd></div>
        </dl>

        <h2 class="mt-8 mb-3 text-lg font-semibold">Pagina's</h2>
        <div class="overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left text-xs text-stone-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">Pagina</th>
                        <th class="px-3 py-2 font-medium">Filter</th>
                        <th class="px-3 py-2 font-medium">HTTP</th>
                        <th class="px-3 py-2 text-right font-medium">Auto's</th>
                        <th class="px-3 py-2 font-medium">ld+json</th>
                        <th class="px-3 py-2 text-right font-medium">Pogingen</th>
                        <th class="px-3 py-2 text-right font-medium">Grootte</th>
                        <th class="px-3 py-2 font-medium">Opgeslagen als</th>
                    </tr>
                </thead>
                <tbody class="tabular divide-y divide-stone-100">
                    <tr v-for="(p, i) in pages" :key="i">
                        <td class="px-3 py-2">{{ p.page_number }}</td>
                        <td class="px-3 py-2 text-xs text-stone-500">{{ p.facet ?? 'zoekopdracht' }}</td>
                        <td class="px-3 py-2">{{ p.http_status ?? '–' }}</td>
                        <td class="px-3 py-2 text-right">{{ p.occasions_count ?? '–' }}</td>
                        <td class="px-3 py-2">{{ p.ld_matches === null ? '–' : p.ld_matches ? 'klopt' : 'wijkt af' }}</td>
                        <td class="px-3 py-2 text-right">{{ p.attempts }}</td>
                        <td class="px-3 py-2 text-right">{{ p.bytes ? `${Math.round(p.bytes / 1024)} kB` : '–' }}</td>
                        <td class="px-3 py-2 text-xs text-stone-500">
                            {{ p.path }}
                            <p v-if="p.error" class="text-red-700">{{ p.error }}</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
