<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, watch } from 'vue'
import AppLayout from '../../layouts/AppLayout.vue'
import CarPhoto from '../../components/CarPhoto.vue'
import Cijfer from '../../components/Cijfer.vue'
import KleurBadge from '../../components/KleurBadge.vue'
import MultiSelect from '../../components/MultiSelect.vue'
import Pagination from '../../components/Pagination.vue'
import UitrustingBadge from '../../components/UitrustingBadge.vue'
import { date, euro, km, num } from '../../lib/format'
import { kleurLabel } from '../../lib/kleur'
import { comfortLabel } from '../../lib/uitrusting'
import { usePollWhileRunning } from '../../lib/usePollWhileRunning'
import { chipClass, cijferText, cijferTextClass, vakKort, vakLabel, vakOnbekend, vakken } from '../../lib/wensen'

const props = defineProps({ day: String, ranking: Object, gewichten: Object, filters: Object, standaard: Object, options: Object, bijwerken: Boolean })

// After saving the wensen the queue computes the cijfers again; reload until it is done.
usePollWhileRunning(() => props.bijwerken, ['day', 'ranking', 'gewichten', 'options', 'bijwerken'])

// Only the vakken that count; the heaviest first.
const shownVakken = computed(() => vakken.filter((v) => props.gewichten[v] > 0).sort((a, b) => props.gewichten[b] - props.gewichten[a]))

// ---------- The filters: stored with the wensen, the list follows a moment after the last change ----------
const copy = (f) => ({ ...f, ...Object.fromEntries(['merken', 'modellen', 'bouwjaren', 'km', 'luxe', 'kleuren'].map((k) => [k, [...f[k]]])) })
const f = reactive(copy(props.filters))

// An empty number field is no bound.
const payload = () => ({ ...f, ...Object.fromEntries(['prijs_min', 'prijs_max', 'pk_min', 'pk_max'].map((k) => [k, f[k] === '' || f[k] == null ? null : f[k]])) })

let timer = null
watch(() => JSON.stringify(payload()), () => {
    clearTimeout(timer)
    timer = setTimeout(() => router.put('/voor-jou/filters', payload(), { preserveState: true, preserveScroll: true, only: ['ranking', 'filters', 'options'] }), 400)
})

watch(() => f.merken, (chosen) => {
    f.modellen = f.modellen.filter((v) => chosen.includes(v.split('|')[0]))
})

const isFiltered = computed(() => JSON.stringify(payload()) !== JSON.stringify(copy(props.standaard)))
const reset = () => Object.assign(f, copy(props.standaard))

// A chosen value that no car has today stays visible, so it can be unchecked.
const withChosen = (options, chosen, label) => [
    ...options,
    ...chosen.filter((c) => !options.some((o) => o.value === c)).map((c) => ({ value: c, label: label(c), n: 0 })),
].sort((a, b) => (a.value > b.value ? 1 : -1))

const merkOptions = computed(() => withChosen(props.options.merken.map((o) => ({ value: o.merk, label: o.merk, n: o.n })), f.merken, (m) => m))
const modelOptions = computed(() => [...f.merken].sort().flatMap((merk) => {
    const group = f.merken.length > 1 ? merk : undefined
    const known = props.options.merken.find((o) => o.merk === merk)?.modellen ?? []
    const gone = f.modellen.filter((v) => v.startsWith(`${merk}|`) && !known.some((m) => `${merk}|${m.model}` === v)).map((v) => ({ model: v.slice(merk.length + 1), n: 0 }))
    return [...known, ...gone].map((m) => ({ value: `${merk}|${m.model}`, label: m.model, n: m.n, group }))
}))
const kmLabel = (v) => `${num(v)} – ${num(v + 9999)} km`
const jaarOptions = computed(() => withChosen(props.options.bouwjaren.map((o) => ({ value: o.value, label: String(o.value), n: o.n })), f.bouwjaren, String).reverse())
const kmOptions = computed(() => withChosen(props.options.km.map((o) => ({ value: o.value, label: kmLabel(o.value), n: o.n })), f.km, kmLabel))
const luxeOptions = computed(() => [3, 2, 1].map((v) => ({ value: v, label: comfortLabel[v], n: props.options.luxe.find((o) => o.value === v)?.n ?? 0 })))
const kleurOptions = computed(() => withChosen(props.options.kleuren.map((o) => ({ value: o.value, label: kleurLabel(o.value), n: o.n })), f.kleuren, kleurLabel))

const range = 'tabular w-24 rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm'
</script>

<template>
    <Head title="Voor jou" />
    <AppLayout>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Voor jou</h1>
                <p v-if="day" class="mt-1 text-sm text-stone-500">
                    De auto's die door je filters komen, op rapportcijfer; bij een gelijk cijfer de goedkoopste eerst. Cijfers van {{ date(day) }}.
                </p>
            </div>
            <Link href="/wensen" class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm hover:bg-stone-50">Mijn rapportcijfer aanpassen</Link>
        </div>

        <p v-if="bijwerken" class="mt-4 flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-4 py-2.5 text-sm text-teal-900" role="status">
            <span class="size-3 animate-spin rounded-full border-2 border-teal-300 border-t-teal-700" aria-hidden="true"></span>
            Cijfers worden bijgewerkt… De lijst hieronder heeft nog de cijfers van vóór je laatste wijziging.
        </p>

        <p v-if="!day" class="mt-6 rounded-lg border border-dashed border-stone-300 px-4 py-8 text-center text-sm text-stone-500">
            Nog geen cijfers. Ze worden elke nacht na de dagelijkse run berekend, of meteen als je je wensen opslaat.
        </p>

        <template v-else>
            <section class="mt-6 rounded-lg border border-stone-200 bg-white p-4" aria-label="Filters">
                <div class="flex flex-wrap items-end gap-3">
                    <MultiSelect v-model="f.merken" label="Merk" :options="merkOptions" placeholder="Alle merken" />
                    <MultiSelect v-model="f.modellen" label="Model" :options="modelOptions" :disabled="!f.merken.length"
                                 :placeholder="f.merken.length ? 'Alle modellen' : 'Kies eerst een merk'" />
                    <fieldset class="text-sm">
                        <legend>Prijs (€)</legend>
                        <div class="mt-1 flex items-center gap-1.5">
                            <input id="f-prijs-min" v-model.number="f.prijs_min" type="number" min="0" step="1000" placeholder="van" :class="range" aria-label="Prijs vanaf" />
                            <span class="text-stone-400">–</span>
                            <input id="f-prijs-max" v-model.number="f.prijs_max" type="number" min="0" step="1000" placeholder="tot" :class="range" aria-label="Prijs tot en met" />
                        </div>
                    </fieldset>
                    <MultiSelect v-model="f.bouwjaren" label="Bouwjaar" :options="jaarOptions" placeholder="Alle bouwjaren" />
                    <MultiSelect v-model="f.km" label="Kilometers" :options="kmOptions" placeholder="Alle kilometerstanden" />
                    <MultiSelect v-model="f.luxe" label="Uitrusting" :options="luxeOptions" placeholder="Alle uitvoeringen" />
                    <fieldset class="text-sm">
                        <legend>Vermogen (pk)</legend>
                        <div class="mt-1 flex items-center gap-1.5">
                            <input id="f-pk-min" v-model.number="f.pk_min" type="number" min="0" step="10" placeholder="van" :class="range" aria-label="Vermogen vanaf" />
                            <span class="text-stone-400">–</span>
                            <input id="f-pk-max" v-model.number="f.pk_max" type="number" min="0" step="10" placeholder="tot" :class="range" aria-label="Vermogen tot en met" />
                        </div>
                    </fieldset>
                    <MultiSelect v-model="f.kleuren" label="Kleur" :options="kleurOptions" placeholder="Alle kleuren" />
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    <label class="flex items-center gap-2"><input id="f-sport" v-model="f.sport" type="checkbox" class="accent-teal-700" /> Sportief</label>
                    <label class="flex items-center gap-2"><input id="f-merkdealer" v-model="f.alleen_merkdealer" type="checkbox" class="accent-teal-700" /> Alleen merkdealer of merkgarantie</label>
                    <label v-if="f.kleuren.length" class="flex items-center gap-2"><input id="f-onbekende-kleur" v-model="f.onbekende_kleur" type="checkbox" class="accent-teal-700" /> Ook auto's zonder bekende kleur</label>
                    <button v-if="isFiltered" type="button" class="text-teal-700 hover:underline" @click="reset">Wis filters</button>
                    <p class="ml-auto text-stone-500">{{ num(ranking.total) }} auto's</p>
                </div>
            </section>

            <ol class="mt-3 flex flex-col gap-2">
                <li v-for="r in ranking.data" :key="r.car.id" class="grid gap-3 rounded-lg border bg-white p-3 sm:grid-cols-[3rem_8rem_1fr_auto]"
                    :class="r.rang <= 10 ? 'border-teal-200' : 'border-stone-200'">
                    <div class="tabular sm:text-right">
                        <span class="text-lg font-semibold text-stone-400">#{{ r.rang }}</span>
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
                        <dl class="tabular mt-2 flex flex-wrap gap-x-3 gap-y-0.5 text-sm">
                            <div v-for="v in shownVakken" :key="v" class="flex gap-1" :title="r.cijfers[v] == null ? vakOnbekend[v] : `${vakLabel[v]}: ${cijferText(r.cijfers[v])}`">
                                <dt class="text-stone-500">{{ vakKort[v] }}</dt>
                                <dd class="font-medium" :class="cijferTextClass(r.cijfers[v])">{{ cijferText(r.cijfers[v]) }}</dd>
                            </div>
                        </dl>
                        <ul v-if="r.chips.length" class="mt-2 flex flex-wrap gap-1.5">
                            <li v-for="c in r.chips" :key="c.tekst" class="rounded-full px-2 py-0.5 text-xs ring-1" :class="chipClass[c.toon]">{{ c.tekst }}</li>
                        </ul>
                    </div>
                    <div class="tabular flex items-center gap-3 sm:flex-col sm:items-end sm:gap-1">
                        <Cijfer :value="r.cijfer" />
                        <p class="font-semibold">{{ euro(r.car.prijs) }}</p>
                    </div>
                </li>
            </ol>
            <p v-if="!ranking.data.length" class="mt-6 rounded-lg border border-dashed border-stone-300 px-4 py-8 text-center text-sm text-stone-500">
                Geen enkele auto komt door je filters.
            </p>
            <Pagination :paginator="ranking" />
        </template>
    </AppLayout>
</template>
