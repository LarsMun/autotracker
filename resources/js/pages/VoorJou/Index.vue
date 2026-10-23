<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import AppLayout from '../../layouts/AppLayout.vue'
import CarPhoto from '../../components/CarPhoto.vue'
import KleurBadge from '../../components/KleurBadge.vue'
import MultiSelect from '../../components/MultiSelect.vue'
import Pagination from '../../components/Pagination.vue'
import UitrustingBadge from '../../components/UitrustingBadge.vue'
import { date, euro, km, num, signed } from '../../lib/format'
import { usePollWhileRunning } from '../../lib/usePollWhileRunning'
import { chipClass, scoreClass } from '../../lib/wensen'

const props = defineProps({ day: String, ranking: Object, top: Object, wensen: Object, filters: Object, options: Array, bijwerken: Boolean })

// After saving the wensen the queue recomputes the ranking; reload until it is done.
usePollWhileRunning(() => props.bijwerken, ['day', 'ranking', 'top', 'wensen', 'options', 'bijwerken'])

// ---------- Make and model filter: only narrows the list, the ranking stays the same ----------
const split = (v) => (v ? v.split(',') : [])
const merken = ref(split(props.filters.merk))
const modellen = ref(split(props.filters.model))
const filtered = computed(() => props.filters.merk !== '')

// A chosen make or model that is no longer in today's ranking stays visible, so it can be unchecked.
const merkOptions = computed(() => [
    ...props.options.map((o) => ({ value: o.merk, label: o.merk, n: o.n })),
    ...merken.value.filter((m) => !props.options.some((o) => o.merk === m)).map((m) => ({ value: m, label: m, n: 0 })),
])
const modelOptions = computed(() => [...merken.value].sort().flatMap((merk) => {
    const group = merken.value.length > 1 ? merk : undefined
    const known = props.options.find((o) => o.merk === merk)?.modellen ?? []
    const gone = modellen.value.filter((v) => v.startsWith(`${merk}|`) && !known.some((m) => `${merk}|${m.model}` === v)).map((v) => ({ model: v.slice(merk.length + 1), n: 0 }))
    return [...known, ...gone].map((m) => ({ value: `${merk}|${m.model}`, label: m.model, n: m.n, group }))
}))

watch(merken, (chosen) => {
    modellen.value = modellen.value.filter((v) => chosen.includes(v.split('|')[0]))
})

let timer = null
watch([merken, modellen], () => {
    clearTimeout(timer)
    timer = setTimeout(() => router.get(
        '/voor-jou',
        // An empty merk is sent on purpose: it clears the remembered filter.
        { merk: merken.value.join(','), model: modellen.value.join(',') || undefined },
        { preserveState: true, preserveScroll: true, replace: true, only: ['ranking', 'filters'] },
    ), 250)
})
const reset = () => {
    merken.value = []
    modellen.value = []
}

const daysSince = (d, day) => Math.round((new Date(`${day}T12:00:00`) - new Date(`${d}T12:00:00`)) / 86400000)
</script>

<template>
    <Head title="Voor jou" />
    <AppLayout>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Voor jou</h1>
                <p v-if="day" class="mt-1 text-sm text-stone-500">
                    De auto's die aan je harde eisen voldoen, op totaalscore ({{ Math.round(wensen.w * 100) }}% match, {{ Math.round((1 - wensen.w) * 100) }}% deal). Berekend op {{ date(day) }}.
                </p>
            </div>
            <Link href="/wensen" class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm hover:bg-stone-50">Mijn wensen aanpassen</Link>
        </div>

        <p v-if="bijwerken" class="mt-4 flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-4 py-2.5 text-sm text-teal-900" role="status">
            <span class="size-3 animate-spin rounded-full border-2 border-teal-300 border-t-teal-700" aria-hidden="true"></span>
            Scores worden bijgewerkt… De lijst hieronder is nog die van vóór je laatste wijziging.
        </p>

        <p v-if="!day" class="mt-6 rounded-lg border border-dashed border-stone-300 px-4 py-8 text-center text-sm text-stone-500">
            Nog geen scores. Ze worden elke nacht na de dagelijkse run berekend, of meteen als je je wensen opslaat.
        </p>

        <template v-else>
            <section v-if="top.vorige_dag" class="mt-6 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-stone-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Nieuw in de top 10 <span class="font-normal text-stone-500">sinds {{ date(top.vorige_dag) }}</span></h2>
                    <ul v-if="top.nieuw.length" class="mt-2 flex flex-col gap-1 text-sm">
                        <li v-for="c in top.nieuw" :key="c.id" class="flex justify-between gap-2">
                            <Link :href="`/listings/${c.id}`" class="truncate text-teal-800 hover:underline">#{{ c.rang }} {{ c.title }}</Link>
                            <span class="tabular shrink-0">{{ euro(c.prijs) }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-stone-400">Geen.</p>
                </div>
                <div class="rounded-lg border border-stone-200 bg-white p-4">
                    <h2 class="text-sm font-semibold">Uit de top 10</h2>
                    <ul v-if="top.eruit.length" class="mt-2 flex flex-col gap-1 text-sm">
                        <li v-for="c in top.eruit" :key="c.id" class="flex justify-between gap-2">
                            <Link :href="`/listings/${c.id}`" class="truncate text-teal-800 hover:underline">was #{{ c.vorige_rang }} {{ c.title }}</Link>
                            <span class="shrink-0 text-xs text-stone-500">{{ c.rang ? `nu #${c.rang}` : 'niet meer in de lijst' }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-stone-400">Geen.</p>
                </div>
            </section>
            <p v-else class="mt-6 text-sm text-stone-500">Wat er in de top 10 verandert, zie je vanaf de tweede dag met scores.</p>

            <section class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-stone-200 bg-white p-4">
                <MultiSelect v-model="merken" label="Merk" :options="merkOptions" placeholder="Alle merken" />
                <MultiSelect v-model="modellen" label="Model" :options="modelOptions" :disabled="!merken.length"
                             :placeholder="merken.length ? 'Alle modellen' : 'Kies eerst een merk'" />
                <button v-if="filtered" type="button" class="py-1.5 text-sm text-teal-700 hover:underline" @click="reset">Wis filters</button>
                <p class="ml-auto py-1.5 text-sm text-stone-500">
                    {{ ranking.total }} auto's<template v-if="filtered"> met dit filter</template>
                </p>
            </section>

            <ol class="mt-3 flex flex-col gap-2">
                <li v-for="r in ranking.data" :key="r.car.id" class="grid gap-3 rounded-lg border bg-white p-3 sm:grid-cols-[4.5rem_8rem_1fr_auto]"
                    :class="r.rang <= 10 ? 'border-teal-200' : 'border-stone-200'">
                    <div class="tabular sm:text-right">
                        <span class="text-lg font-semibold text-stone-400">#{{ filtered ? r.plek : r.rang }}</span>
                        <span v-if="filtered" class="ml-1 text-xs text-stone-500 sm:ml-0 sm:block" title="Positie in de hele ranglijst"><span class="sm:hidden">· </span>algemeen #{{ r.rang }}</span>
                    </div>
                    <Link :href="`/listings/${r.car.id}`"><CarPhoto :src="r.car.foto" :alt="r.car.title" class="aspect-[4/3] w-full sm:w-32" /></Link>
                    <div class="min-w-0">
                        <Link :href="`/listings/${r.car.id}`" class="font-medium text-teal-800 hover:underline">{{ r.car.merk }} {{ r.car.model }}</Link>
                        <p class="truncate text-sm text-stone-600" :title="r.car.uitvoering">{{ r.car.uitvoering }}</p>
                        <p class="tabular mt-0.5 text-sm text-stone-600">
                            {{ r.car.bouwjaar }} · {{ km(r.car.kilometerstand) }}<template v-if="r.car.pk"> · {{ r.car.pk }} pk</template> · {{ r.car.plaats }}
                            <UitrustingBadge :niveau="r.car.comfort_niveau" :sport="r.car.sport" class="ml-1 align-middle" />
                            <KleurBadge :kleur="r.car.kleur" class="ml-1 align-middle" />
                        </p>
                        <ul class="mt-2 flex flex-wrap gap-1.5">
                            <li v-for="c in r.chips" :key="c.tekst" class="rounded-full px-2 py-0.5 text-xs ring-1" :class="chipClass[c.toon]">{{ c.tekst }}</li>
                            <li v-if="top.sinds[r.car.id] && daysSince(top.sinds[r.car.id], day) > 0" class="rounded-full bg-teal-50 px-2 py-0.5 text-xs text-teal-800 ring-1 ring-teal-200">
                                {{ daysSince(top.sinds[r.car.id], day) + 1 }} dagen in de top 10
                            </li>
                        </ul>
                    </div>
                    <dl class="tabular grid grid-cols-3 gap-x-4 gap-y-0.5 text-right text-sm sm:grid-cols-1 sm:content-start">
                        <div><dt class="text-xs text-stone-500">Prijs</dt><dd class="font-semibold">{{ euro(r.car.prijs) }}</dd></div>
                        <div><dt class="text-xs text-stone-500">Totaal</dt><dd class="text-lg font-semibold" :class="scoreClass(r.totaalscore)">{{ r.totaalscore.toFixed(0) }}</dd></div>
                        <div><dt class="text-xs text-stone-500">Match · deal</dt>
                            <dd><span :class="scoreClass(r.matchscore)">{{ r.matchscore.toFixed(0) }}</span> · <span v-if="r.deal_pct !== null" :class="r.check_dit ? 'text-amber-700' : r.deal_pct >= 0 ? 'text-emerald-700' : 'text-red-700'">{{ r.deal_pct > 0 ? '+' : '' }}{{ r.deal_pct.toFixed(1) }}%</span><span v-else class="text-stone-400">–</span></dd>
                        </div>
                    </dl>
                </li>
            </ol>
            <p v-if="!ranking.data.length" class="mt-6 rounded-lg border border-dashed border-stone-300 px-4 py-8 text-center text-sm text-stone-500">
                {{ filtered ? 'Geen auto van dit merk of model voldoet aan je harde eisen.' : 'Geen enkele auto voldoet aan je harde eisen.' }}
            </p>
            <Pagination :paginator="ranking" />
        </template>
    </AppLayout>
</template>
