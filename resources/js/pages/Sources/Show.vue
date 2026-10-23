<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import AppLayout from '../../layouts/AppLayout.vue'
import CarPhoto from '../../components/CarPhoto.vue'
import KleurBadge from '../../components/KleurBadge.vue'
import Pagination from '../../components/Pagination.vue'
import RunsTable from '../../components/RunsTable.vue'
import SellerBadge from '../../components/SellerBadge.vue'
import UitrustingBadge from '../../components/UitrustingBadge.vue'
import ComfortSportFilter from '../../components/ComfortSportFilter.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { date, dateTime, euro, km, num } from '../../lib/format'
import { usePollWhileRunning } from '../../lib/usePollWhileRunning'

const props = defineProps({ source: Object, listings: Object, runs: Array, filters: Object })

usePollWhileRunning(() => props.source.last_run?.status === 'running', ['source', 'runs', 'listings', 'problems'])

const editing = ref(false)
const confirmDelete = ref(false)
const form = useForm({ name: props.source.name, url: props.source.url })

const save = () => form.patch(`/sources/${props.source.id}`, { preserveScroll: true, onSuccess: () => (editing.value = false) })
const toggle = () => router.patch(`/sources/${props.source.id}`, { active: !props.source.active }, { preserveScroll: true })
const run = () => router.post(`/sources/${props.source.id}/run`, {}, { preserveScroll: true })
const destroy = () => router.delete(`/sources/${props.source.id}`)

const search = ref(props.filters.q)
const comfort = ref(props.filters.comfort)
const sport = ref(props.filters.sport)
watch([comfort, sport], () => visit({ comfort: comfort.value || undefined, sport: sport.value || undefined }))
const visit = (changes) =>
    router.get(`/sources/${props.source.id}`, { ...props.filters, ...changes, page: undefined }, { preserveState: true, preserveScroll: true, replace: true })

const tabs = [
    ['current', 'Nu online'],
    ['new', 'Nieuw'],
    ['gone_today', 'Net verdwenen'],
    ['gone', 'Alle verdwenen'],
    ['all', 'Alles'],
]
const sorts = [
    ['prijs', 'Prijs ↑'],
    ['-prijs', 'Prijs ↓'],
    ['km', 'Km ↑'],
    ['-km', 'Km ↓'],
    ['-bouwjaar', 'Bouwjaar nieuw–oud'],
    ['bouwjaar', 'Bouwjaar oud–nieuw'],
    ['-first_seen', 'Eerst gezien nieuw–oud'],
    ['first_seen', 'Eerst gezien oud–nieuw'],
]
</script>

<template>
    <Head :title="source.name" />
    <AppLayout>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ source.name }}
                    <span v-if="!source.active" class="ml-2 rounded bg-stone-200 px-2 py-0.5 align-middle text-xs font-normal">uitgeschakeld</span>
                </h1>
                <a :href="source.url" target="_blank" rel="noopener noreferrer" class="block truncate text-sm text-teal-700 hover:underline">{{ source.url }}</a>
            </div>
            <div class="flex flex-wrap gap-2 text-sm">
                <button class="rounded-md bg-teal-700 px-3 py-1.5 font-medium text-white hover:bg-teal-800 disabled:opacity-50" :disabled="source.last_run?.status === 'running'" @click="run">Nu draaien</button>
                <button class="rounded-md border border-stone-300 bg-white px-3 py-1.5 hover:bg-stone-50" @click="toggle">{{ source.active ? 'Uitzetten' : 'Aanzetten' }}</button>
                <button class="rounded-md border border-stone-300 bg-white px-3 py-1.5 hover:bg-stone-50" @click="editing = !editing">Bewerken</button>
                <button class="rounded-md border border-red-200 bg-white px-3 py-1.5 text-red-700 hover:bg-red-50" @click="confirmDelete = true">Verwijderen</button>
            </div>
        </div>

        <div v-if="confirmDelete" class="mt-4 flex flex-wrap items-center gap-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <span>Bron "{{ source.name }}" verwijderen? De auto's, prijshistorie en ruwe pagina's blijven bewaard; alleen de koppeling met deze bron en haar runs verdwijnen.</span>
            <button class="rounded-md bg-red-700 px-3 py-1 font-medium text-white hover:bg-red-800" @click="destroy">Ja, verwijderen</button>
            <button class="rounded-md border border-red-200 bg-white px-3 py-1 hover:bg-red-100" @click="confirmDelete = false">Annuleren</button>
        </div>

        <form v-if="editing" class="mt-4 grid gap-3 rounded-lg border border-stone-200 bg-white p-4 sm:grid-cols-[1fr_2fr_auto]" @submit.prevent="save">
            <input v-model="form.name" class="rounded-md border border-stone-300 px-3 py-2 text-sm" placeholder="Naam" />
            <input v-model="form.url" class="rounded-md border border-stone-300 px-3 py-2 text-sm" placeholder="URL" />
            <button class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white">Opslaan</button>
            <p v-if="form.errors.url || form.errors.name" class="text-sm text-red-700 sm:col-span-3">{{ form.errors.url || form.errors.name }}</p>
            <p class="text-xs text-stone-500 sm:col-span-3">Een andere URL geldt vanaf de volgende run; de bestaande auto's blijven aan deze bron gekoppeld.</p>
        </form>

        <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <dt class="text-xs text-stone-500">Nu online</dt>
                <dd class="tabular mt-1 text-2xl font-semibold">{{ num(source.current) }}</dd>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <dt class="text-xs text-stone-500">Nieuw (laatste run)</dt>
                <dd class="tabular mt-1 text-2xl font-semibold text-emerald-700">+{{ num(source.new) }}</dd>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <dt class="text-xs text-stone-500">Verdwenen (laatste geslaagde run)</dt>
                <dd class="tabular mt-1 text-2xl font-semibold text-red-700">−{{ num(source.gone) }}</dd>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <dt class="text-xs text-stone-500">Laatste run</dt>
                <dd class="mt-1"><StatusBadge :status="source.last_run?.status" /></dd>
                <dd v-if="source.last_run" class="mt-1 text-xs text-stone-500">
                    {{ dateTime(source.last_run.started_at) }} ·
                    <template v-if="source.last_run.status === 'running'">pagina {{ source.last_run.pages_fetched }}/{{ source.last_run.pages_expected ?? '?' }}</template>
                    <template v-else>{{ num(source.last_run.listings_seen) }}/{{ num(source.last_run.total_reported) }}</template>
                </dd>
            </div>
        </dl>

        <div class="mt-8 flex flex-wrap items-end gap-2">
            <div class="flex flex-wrap gap-1 rounded-lg border border-stone-200 bg-white p-1 text-sm">
                <button v-for="[key, label] in tabs" :key="key" class="rounded-md px-3 py-1"
                        :class="filters.filter === key ? 'bg-stone-900 text-white' : 'hover:bg-stone-100'" @click="visit({ filter: key })">{{ label }}</button>
            </div>
            <select :value="filters.sort" class="rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm" @change="visit({ sort: $event.target.value })">
                <option v-for="[key, label] in sorts" :key="key" :value="key">{{ label }}</option>
            </select>
            <ComfortSportFilter v-model:comfort="comfort" v-model:sport="sport" />
            <form class="flex-1 self-end" @submit.prevent="visit({ q: search })">
                <input v-model="search" type="search" placeholder="Zoek in uitvoering, plaats, ID…" class="w-full min-w-48 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm" />
            </form>
            <span class="tabular text-sm text-stone-500">{{ num(listings.total) }} auto's</span>
        </div>

        <div class="mt-3 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left text-xs text-stone-500">
                    <tr>
                        <th class="px-3 py-2 font-medium" colspan="2">Auto</th>
                        <th class="px-3 py-2 text-right font-medium">Bouwjaar</th>
                        <th class="px-3 py-2 text-right font-medium">Km</th>
                        <th class="px-3 py-2 text-right font-medium">Pk</th>
                        <th class="px-3 py-2 text-right font-medium">Prijs</th>
                        <th class="px-3 py-2 font-medium">Plaats</th>
                        <th class="px-3 py-2 font-medium">Gezien</th>
                    </tr>
                </thead>
                <tbody class="tabular divide-y divide-stone-100">
                    <tr v-for="l in listings.data" :key="l.id" class="hover:bg-stone-50">
                        <td class="w-20 py-1.5 pl-2"><CarPhoto :src="l.foto" :alt="l.title" class="h-12 w-18" /></td>
                        <td class="max-w-xs px-3 py-1.5">
                            <Link :href="`/listings/${l.id}`" class="font-medium text-teal-800 hover:underline">{{ l.merk }} {{ l.model }}</Link>
                            <p class="truncate text-xs text-stone-500" :title="l.uitvoering">{{ l.uitvoering }}</p>
                            <span class="mt-0.5 flex flex-wrap items-center gap-1.5"><UitrustingBadge :niveau="l.comfort_niveau" :sport="l.sport" /><KleurBadge :kleur="l.kleur" /><SellerBadge :type="l.aanbieder_type" :garantie="l.garantie" /></span>
                        </td>
                        <td class="px-3 py-1.5 text-right">{{ l.bouwjaar }}</td>
                        <td class="px-3 py-1.5 text-right whitespace-nowrap">{{ km(l.kilometerstand) }}</td>
                        <td class="px-3 py-1.5 text-right">{{ l.pk ?? '–' }}</td>
                        <td class="px-3 py-1.5 text-right font-medium whitespace-nowrap">{{ euro(l.prijs) }}</td>
                        <td class="px-3 py-1.5">{{ l.plaats }}</td>
                        <td class="px-3 py-1.5 text-xs whitespace-nowrap text-stone-500">
                            {{ date(l.source_first_seen) }}
                            <template v-if="l.source_gone_at"> → <span class="text-red-700">weg {{ date(l.source_gone_at) }}</span></template>
                        </td>
                    </tr>
                    <tr v-if="!listings.data.length">
                        <td colspan="8" class="px-3 py-6 text-center text-stone-400">Geen auto's in deze selectie.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="listings" />

        <h2 class="mt-8 mb-3 text-lg font-semibold">Runs van deze bron</h2>
        <RunsTable :runs="runs" :show-source="false" />
    </AppLayout>
</template>
