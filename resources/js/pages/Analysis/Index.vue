<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import AppLayout from '../../layouts/AppLayout.vue'
import CarPhoto from '../../components/CarPhoto.vue'
import LineChart from '../../components/LineChart.vue'
import Pagination from '../../components/Pagination.vue'
import SortHeader from '../../components/SortHeader.vue'
import { comfortDots, comfortLabel } from '../../lib/uitrusting'
import { date, euro, km, num, signed } from '../../lib/format'
import { describe, sameFilters } from '../../lib/filters'
import { slotColor } from '../../lib/series'
import { merkdealerUitleg } from '../../lib/seller'
import SellerBadge from '../../components/SellerBadge.vue'
import UitrustingBadge from '../../components/UitrustingBadge.vue'
import ComfortSportFilter from '../../components/ComfortSportFilter.vue'
import KleurBadge from '../../components/KleurBadge.vue'
import MultiSelect from '../../components/MultiSelect.vue'
import { kleurLabel } from '../../lib/kleur'

const props = defineProps({
    filters: Object,
    sort: String,
    options: Object,
    now: Object,
    daily: Array,
    listings: Object,
    saved: Array,
    compare: Array,
})

// ---------- Filters: every change goes to the URL (bookmarkable) ----------
const empty = {
    merk: '', model: '', bron: '', bouwjaar_min: '', bouwjaar_max: '', pk_min: '', pk_max: '',
    km_min: '', km_max: '', prijs_min: '', prijs_max: '', carrosserie: '', transmissie: '', kleur: '', uitvoering: '',
    aanbieder: '', garantie: '', btw: '', comfort: '', sport: '',
}
const form = reactive({ ...empty, ...props.filters })
const sort = ref(props.sort)
const compared = ref([...props.compare])

const activeFilters = () => Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v != null))

function visit(only) {
    router.get(
        '/analyse',
        { ...activeFilters(), sort: sort.value === 'prijs' ? undefined : sort.value, vergelijk: compared.value.length ? compared.value.join(',') : undefined },
        { preserveState: true, preserveScroll: true, replace: true, only },
    )
}

let timer = null
watch(form, () => {
    clearTimeout(timer)
    timer = setTimeout(() => visit(['filters', 'now', 'daily', 'listings']), 350)
})
watch(sort, () => visit(['sort', 'listings']))
watch(() => form.merk, (merk, old) => {
    if (old !== undefined && form.model && !(props.options.modellen[merk] ?? []).includes(form.model)) form.model = ''
})

const models = computed(() => (form.merk ? props.options.modellen[form.merk] ?? [] : Object.values(props.options.modellen).flat()))
// Kept as "GRIJS,ZWART", like the URL.
const kleuren = computed({
    get: () => (form.kleur ? form.kleur.split(',') : []),
    set: (list) => (form.kleur = [...list].sort().join(',')),
})
const kleurOptions = computed(() => props.options.kleuren.map((k) => ({ value: k, label: kleurLabel(k) })))
const hasFilters = computed(() => Object.keys(activeFilters()).length > 0)
const reset = () => Object.assign(form, empty)
const apply = (filters) => Object.assign(form, empty, filters)

// ---------- Saved filters ----------
const saveForm = useForm({ name: '', filters: {} })
const save = () => {
    saveForm.filters = activeFilters()
    saveForm.post('/analyse/filters', { preserveScroll: true, preserveState: true, onSuccess: () => saveForm.reset('name') })
}
const matching = computed(() => props.saved.find((s) => sameFilters(s.filters, activeFilters())))

const renaming = ref(null)
const renameTo = ref('')
const startRename = (s) => {
    renaming.value = s.id
    renameTo.value = s.name
}
const rename = (s) =>
    router.patch(`/analyse/filters/${s.id}`, { name: renameTo.value }, { preserveScroll: true, preserveState: true, onSuccess: () => (renaming.value = null) })
const overwrite = (s) => router.patch(`/analyse/filters/${s.id}`, { filters: activeFilters() }, { preserveScroll: true, preserveState: true })
const confirmDelete = ref(null)
const remove = (s) => {
    compared.value = compared.value.filter((id) => id !== s.id)
    router.delete(`/analyse/filters/${s.id}`, { preserveScroll: true, preserveState: true, onSuccess: () => (confirmDelete.value = null) })
}

function toggleCompare(id) {
    compared.value = compared.value.includes(id) ? compared.value.filter((x) => x !== id) : [...compared.value, id]
    visit(['saved', 'compare'])
}

// ---------- Charts ----------
const metric = ref('median')
const selectionSeries = computed(() => [
    { key: 'avg', label: 'Gemiddelde', color: '#2a78d6', points: props.daily.map((d) => ({ date: d.date, value: d.avg, n: d.n })) },
    { key: 'median', label: 'Mediaan', color: '#eb6834', points: props.daily.map((d) => ({ date: d.date, value: d.median, n: d.n })) },
])
const counts = computed(() => props.daily.map((d) => ({ date: d.date, n: d.n })))
const compareSeries = computed(() =>
    props.saved
        .filter((s) => compared.value.includes(s.id) && s.daily)
        .map((s) => ({ key: s.id, label: s.name, color: slotColor(s.color_slot), points: s.daily.map((d) => ({ date: d.date, value: d[metric.value], n: d.n })) })),
)
const showDaily = ref(false)

const daysOnline = (iso) => (iso ? Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 86400000)) : null)
const pctClass = (v) => (v == null || v === 0 ? 'text-stone-500' : v < 0 ? 'text-emerald-700' : 'text-red-700')
</script>

<template>
    <Head title="Analyse" />
    <AppLayout>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="text-2xl font-semibold tracking-tight">Analyse</h1>
            <Link href="/uitvoeringen" class="text-sm text-teal-700 hover:underline">Niet herkende uitvoeringen</Link>
        </div>
        <p class="mt-1 text-sm text-stone-500">Stel een selectie samen. Kerncijfers gaan over de auto's die nu online zijn; de prijsontwikkeling over de auto's die op elke dag online waren en aan het filter voldeden.</p>

        <!-- Filters -->
        <section class="mt-6 rounded-lg border border-stone-200 bg-white p-4">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <label class="text-xs text-stone-500">Merk
                    <select id="f-merk" v-model="form.merk" class="mt-1 w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm text-stone-900">
                        <option value="">Alle</option>
                        <option v-for="m in options.merken" :key="m" :value="m">{{ m }}</option>
                    </select>
                </label>
                <label class="text-xs text-stone-500">Model
                    <select id="f-model" v-model="form.model" class="mt-1 w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm text-stone-900">
                        <option value="">Alle</option>
                        <option v-for="m in models" :key="m" :value="m">{{ m }}</option>
                    </select>
                </label>
                <label class="text-xs text-stone-500">Bron
                    <select id="f-bron" v-model="form.bron" class="mt-1 w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm text-stone-900">
                        <option value="">Alle</option>
                        <option v-for="b in options.bronnen" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </label>
                <label class="text-xs text-stone-500">Carrosserie
                    <select id="f-carrosserie" v-model="form.carrosserie" class="mt-1 w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm text-stone-900">
                        <option value="">Alle</option>
                        <option v-for="c in options.carrosserieen" :key="c" :value="c">{{ c.toLowerCase() }}</option>
                    </select>
                </label>
                <label class="text-xs text-stone-500">Transmissie
                    <select id="f-transmissie" v-model="form.transmissie" class="mt-1 w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm text-stone-900">
                        <option value="">Alle</option>
                        <option v-for="t in options.transmissies" :key="t" :value="t">{{ t.toLowerCase() }}</option>
                    </select>
                </label>
                <MultiSelect v-model="kleuren" label="Kleur" :options="kleurOptions" placeholder="Alle" />
                <label class="text-xs text-stone-500">Uitvoering bevat
                    <input id="f-uitvoering" v-model="form.uitvoering" type="search" placeholder="bijv. M Sport" class="mt-1 w-full rounded-md border border-stone-300 px-2 py-1.5 text-sm text-stone-900" />
                </label>

                <label class="text-xs text-stone-500">Aanbieder
                    <select id="f-aanbieder" v-model="form.aanbieder" class="mt-1 w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm text-stone-900" :title="merkdealerUitleg">
                        <option value="">Alle</option>
                        <option value="merkdealer">Merkdealer</option>
                        <option value="handelaar">Handelaar</option>
                        <option value="particulier">Particulier</option>
                    </select>
                </label>
                <label class="text-xs text-stone-500">Garantie
                    <select id="f-garantie" v-model="form.garantie" class="mt-1 w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm text-stone-900">
                        <option value="">Alle</option>
                        <option value="merkgarantie">Merkgarantie</option>
                        <option value="bovag">BOVAG</option>
                        <option value="geen">Geen garantie</option>
                    </select>
                </label>
                <label class="text-xs text-stone-500">Btw
                    <select id="f-btw" v-model="form.btw" class="mt-1 w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm text-stone-900">
                        <option value="">Alle</option>
                        <option value="verrekenbaar">Btw verrekenbaar</option>
                        <option value="marge">Marge</option>
                    </select>
                </label>

                <ComfortSportFilter v-model:comfort="form.comfort" v-model:sport="form.sport" class="col-span-2" />

                <fieldset v-for="[key, label, step] in [['bouwjaar', 'Bouwjaar', 1], ['pk', 'Pk', 10], ['km', 'Km', 5000], ['prijs', 'Prijs (€)', 1000]]" :key="key" class="col-span-1 text-xs text-stone-500 lg:col-span-1">
                    <legend>{{ label }} van–tot</legend>
                    <div class="mt-1 flex items-center gap-1">
                        <input :id="`f-${key}-min`" v-model="form[`${key}_min`]" type="number" :step="step" min="0" class="w-full min-w-0 rounded-md border border-stone-300 px-2 py-1.5 text-sm text-stone-900" />
                        <span>–</span>
                        <input :id="`f-${key}-max`" v-model="form[`${key}_max`]" type="number" :step="step" min="0" class="w-full min-w-0 rounded-md border border-stone-300 px-2 py-1.5 text-sm text-stone-900" />
                    </div>
                </fieldset>

                <div class="col-span-2 flex items-end gap-2">
                    <form class="flex min-w-0 flex-1 gap-2" @submit.prevent="save">
                        <input id="save-name" v-model="saveForm.name" required maxlength="100" placeholder="Naam voor dit filter" class="min-w-0 flex-1 rounded-md border border-stone-300 px-2 py-1.5 text-sm" />
                        <button class="shrink-0 rounded-md bg-teal-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-teal-800 disabled:opacity-50" :disabled="saveForm.processing">Opslaan</button>
                    </form>
                    <button v-if="hasFilters" class="shrink-0 rounded-md border border-stone-300 px-3 py-1.5 text-sm hover:bg-stone-50" @click="reset">Wissen</button>
                </div>
            </div>
            <p v-if="saveForm.errors.name" class="mt-2 text-sm text-red-700">{{ saveForm.errors.name }}</p>
            <p v-if="form.aanbieder === 'merkdealer'" class="mt-2 text-xs text-stone-500">{{ merkdealerUitleg }}</p>
            <p v-if="matching" class="mt-2 text-xs text-stone-500">Dit is opgeslagen filter "{{ matching.name }}".</p>
        </section>

        <!-- Saved filters -->
        <section class="mt-8">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold">Opgeslagen filters</h2>
                <p v-if="saved.length" class="text-xs text-stone-500">Vink filters aan om ze in één grafiek te vergelijken.</p>
            </div>
            <p v-if="!saved.length" class="rounded-lg border border-dashed border-stone-300 px-4 py-6 text-center text-sm text-stone-500">
                Nog geen opgeslagen filters. Stel hierboven een selectie samen en sla die op onder een naam.
            </p>
            <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <article v-for="s in saved" :key="s.id" class="flex flex-col gap-2 rounded-lg border bg-white p-3"
                         :class="matching?.id === s.id ? 'border-teal-600 ring-1 ring-teal-600' : 'border-stone-200'">
                    <div class="flex items-start gap-2">
                        <input :id="`cmp-${s.id}`" type="checkbox" class="mt-1 rounded border-stone-300" :checked="compared.includes(s.id)" :style="{ accentColor: slotColor(s.color_slot) }" @change="toggleCompare(s.id)" />
                        <div class="min-w-0 flex-1">
                            <form v-if="renaming === s.id" class="flex gap-1" @submit.prevent="rename(s)">
                                <input :id="`rename-${s.id}`" v-model="renameTo" class="min-w-0 flex-1 rounded border border-stone-300 px-1.5 py-0.5 text-sm" />
                                <button class="text-xs text-teal-700">Opslaan</button>
                                <button type="button" class="text-xs text-stone-500" @click="renaming = null">Annuleer</button>
                            </form>
                            <label v-else :for="`cmp-${s.id}`" class="flex items-center gap-1.5 font-medium">
                                <span class="inline-block size-2.5 shrink-0 rounded-full" :style="{ background: slotColor(s.color_slot) }"></span>
                                <span class="truncate">{{ s.name }}</span>
                            </label>
                            <p class="mt-0.5 text-xs text-stone-500">{{ describe(s.filters, options) }}</p>
                        </div>
                    </div>

                    <dl class="tabular grid grid-cols-3 gap-2 border-t border-stone-100 pt-2 text-sm">
                        <div><dt class="text-xs text-stone-500">Online</dt><dd class="font-semibold">{{ num(s.now.count) }}</dd></div>
                        <div><dt class="text-xs text-stone-500">Gem.</dt><dd class="font-semibold">{{ euro(s.now.avg) }}</dd></div>
                        <div><dt class="text-xs text-stone-500">Mediaan</dt><dd class="font-semibold">{{ euro(s.now.median) }}</dd></div>
                    </dl>
                    <p class="tabular text-xs">
                        <template v-if="s.change">
                            Mediaan <span :class="pctClass(s.change.median)">{{ signed(s.change.median, '€ ') || '± € 0' }} ({{ s.change.median_pct > 0 ? '+' : '' }}{{ s.change.median_pct }}%)</span>
                            <span class="text-stone-500">sinds {{ date(s.change.since) }}</span>
                        </template>
                        <span v-else class="text-stone-400">Verandering zichtbaar vanaf de tweede dag met data.</span>
                    </p>

                    <div class="mt-auto flex flex-wrap gap-x-3 gap-y-1 text-xs">
                        <button class="text-teal-700 hover:underline" @click="apply(s.filters)">Toon selectie</button>
                        <button v-if="hasFilters && matching?.id !== s.id" class="text-teal-700 hover:underline" @click="overwrite(s)">Vervang door huidig filter</button>
                        <button class="text-stone-600 hover:underline" @click="startRename(s)">Hernoem</button>
                        <template v-if="confirmDelete === s.id">
                            <button class="font-medium text-red-700 hover:underline" @click="remove(s)">Ja, verwijder</button>
                            <button class="text-stone-500 hover:underline" @click="confirmDelete = null">Nee</button>
                        </template>
                        <button v-else class="text-red-700 hover:underline" @click="confirmDelete = s.id">Verwijder</button>
                    </div>
                </article>
            </div>

            <div v-if="compared.length" class="mt-4 rounded-lg border border-stone-200 bg-white p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-medium">Vergelijking: {{ metric === 'median' ? 'mediaanprijs' : 'gemiddelde prijs' }} per dag</h3>
                    <div class="flex rounded-md border border-stone-200 p-0.5 text-xs">
                        <button v-for="[m, l] in [['median', 'Mediaan'], ['avg', 'Gemiddelde']]" :key="m" class="rounded px-2 py-1"
                                :class="metric === m ? 'bg-stone-900 text-white' : 'hover:bg-stone-100'" @click="metric = m">{{ l }}</button>
                    </div>
                </div>
                <LineChart :series="compareSeries" :format="euro" />
            </div>
        </section>

        <!-- Current selection -->
        <section class="mt-8">
            <h2 class="mb-3 text-lg font-semibold">Selectie nu</h2>
            <dl class="tabular grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-stone-200 bg-stone-200 sm:grid-cols-4 lg:grid-cols-7">
                <div class="bg-white p-3"><dt class="text-xs text-stone-500">Online</dt><dd class="text-xl font-semibold">{{ num(now.count) }}</dd></div>
                <div class="bg-white p-3"><dt class="text-xs text-stone-500">Gemiddelde prijs</dt><dd class="text-xl font-semibold">{{ euro(now.avg) }}</dd></div>
                <div class="bg-white p-3"><dt class="text-xs text-stone-500">Mediaanprijs</dt><dd class="text-xl font-semibold">{{ euro(now.median) }}</dd></div>
                <div class="bg-white p-3"><dt class="text-xs text-stone-500">Laagste</dt><dd class="text-xl font-semibold">{{ euro(now.min) }}</dd></div>
                <div class="bg-white p-3"><dt class="text-xs text-stone-500">Hoogste</dt><dd class="text-xl font-semibold">{{ euro(now.max) }}</dd></div>
                <div class="bg-white p-3"><dt class="text-xs text-stone-500">Gemiddelde km</dt><dd class="text-xl font-semibold">{{ km(now.avg_km) }}</dd></div>
                <div class="bg-white p-3"><dt class="text-xs text-stone-500">Gem. dagen online</dt><dd class="text-xl font-semibold">{{ num(now.avg_days_online) }}</dd></div>
            </dl>
            <p class="mt-1 text-xs text-stone-500">Dagen online volgens Gaspedaal's "online sinds", ook van vóór de start van de tracker.</p>
        </section>

        <!-- Price per day -->
        <section class="mt-8 rounded-lg border border-stone-200 bg-white p-4">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold">Prijsontwikkeling</h2>
                <button class="text-sm text-teal-700 hover:underline" @click="showDaily = !showDaily">{{ showDaily ? 'Verberg' : 'Toon' }} tabel per dag</button>
            </div>
            <LineChart :series="selectionSeries" :format="euro" :counts="counts" />
            <p class="mt-2 text-xs text-stone-500">Onder de grafiek: het aantal auto's (n) waarop elke dag berust. Verschuivingen kunnen ook komen doordat er andere auto's in de selectie zitten.</p>

            <div v-if="showDaily" class="mt-4 max-h-96 overflow-auto rounded-md border border-stone-200">
                <table class="tabular w-full text-sm">
                    <thead class="sticky top-0 bg-stone-50 text-left text-xs text-stone-500">
                        <tr><th class="px-3 py-2 font-medium">Dag</th><th class="px-3 py-2 text-right font-medium">n</th><th class="px-3 py-2 text-right font-medium">Gemiddelde</th><th class="px-3 py-2 text-right font-medium">Mediaan</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <tr v-for="d in [...daily].reverse()" :key="d.date">
                            <td class="px-3 py-1.5">{{ date(d.date) }}</td>
                            <td class="px-3 py-1.5 text-right">{{ num(d.n) }}</td>
                            <td class="px-3 py-1.5 text-right">{{ euro(d.avg) }}</td>
                            <td class="px-3 py-1.5 text-right">{{ euro(d.median) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Cars -->
        <section class="mt-8">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">Auto's in de selectie <span class="tabular text-sm font-normal text-stone-500">({{ num(listings.total) }})</span></h2>
            </div>
            <div class="overflow-x-auto rounded-lg border border-stone-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="w-20"><span class="sr-only">Foto</span></th>
                            <SortHeader v-model="sort" column="auto" label="Auto" />
                            <SortHeader v-model="sort" column="comfort" label="Comfort" />
                            <SortHeader v-model="sort" column="bouwjaar" label="Bouwjaar" align="right" />
                            <SortHeader v-model="sort" column="km" label="Km" align="right" />
                            <SortHeader v-model="sort" column="pk" label="Pk" align="right" />
                            <SortHeader v-model="sort" column="prijs" label="Prijs" align="right" />
                            <SortHeader v-model="sort" column="online" label="Dagen online" align="right" />
                            <SortHeader v-model="sort" column="plaats" label="Plaats" />
                        </tr>
                    </thead>
                    <tbody class="tabular divide-y divide-stone-100">
                        <tr v-for="l in listings.data" :key="l.id" class="hover:bg-stone-50">
                            <td class="w-20 py-1.5 pl-2"><CarPhoto :src="l.foto" :alt="l.title" class="h-12 w-18" /></td>
                            <td class="max-w-xs px-3 py-1.5">
                                <Link :href="`/listings/${l.id}`" class="font-medium text-teal-800 hover:underline">{{ l.merk }} {{ l.model }}</Link>
                                <p class="truncate text-xs text-stone-500" :title="l.uitvoering">{{ l.uitvoering }}</p>
                                <span class="mt-0.5 flex flex-wrap items-center gap-1.5"><UitrustingBadge :sport="l.sport" /><KleurBadge :kleur="l.kleur" /><SellerBadge :type="l.aanbieder_type" :garantie="l.garantie" /></span>
                            </td>
                            <td class="px-3 py-1.5 text-xs tracking-tight whitespace-nowrap text-teal-700" :title="l.comfort_niveau ? `Comfortniveau ${l.comfort_niveau} van 3 (${comfortLabel[l.comfort_niveau]})` : null">{{ comfortDots(l.comfort_niveau) }}</td>
                            <td class="px-3 py-1.5 text-right">{{ l.bouwjaar }}</td>
                            <td class="px-3 py-1.5 text-right whitespace-nowrap">{{ km(l.kilometerstand) }}</td>
                            <td class="px-3 py-1.5 text-right">{{ l.pk ?? '–' }}</td>
                            <td class="px-3 py-1.5 text-right font-medium whitespace-nowrap">{{ euro(l.prijs) }}<p v-if="l.btw_verrekenbaar" class="text-[11px] font-normal text-stone-500">btw verrekenbaar</p></td>
                            <td class="px-3 py-1.5 text-right">{{ daysOnline(l.datum_geplaatst) ?? '–' }}</td>
                            <td class="px-3 py-1.5">{{ l.plaats }}</td>
                        </tr>
                        <tr v-if="!listings.data.length">
                            <td colspan="9" class="px-3 py-6 text-center text-stone-400">Geen auto's in deze selectie.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="listings" />
        </section>
    </AppLayout>
</template>
