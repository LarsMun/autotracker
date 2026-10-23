<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AppLayout from '../../layouts/AppLayout.vue'
import Belang from '../../components/Belang.vue'
import CarPhoto from '../../components/CarPhoto.vue'
import InfoTip from '../../components/InfoTip.vue'
import Switch from '../../components/Switch.vue'
import { postJson } from '../../lib/http'
import { euro, km, num } from '../../lib/format'
import { dealW, wensUitleg } from '../../lib/wensen'
import { kleurLabel, kleurSwatch } from '../../lib/kleur'

const props = defineProps({ wensen: Object, modellen: Array, kleuren: Array, standaard: Object })

const form = useForm({
    eisen: { ...props.wensen.eisen, uitsluiten: [...props.wensen.eisen.uitsluiten], kleuren_uitsluiten: [...props.wensen.eisen.kleuren_uitsluiten] },
    gewichten: { ...props.wensen.gewichten },
    comfort_doel: props.wensen.comfort_doel,
    sport: props.wensen.sport,
    pk_min: props.wensen.pk_min,
    // The full order of every model; saved as the preference list.
    modellen: props.modellen.map((m) => m.key),
    kleuren: [...props.wensen.kleuren],
    w: props.wensen.w,
})

// The limits that pass, with "iets ruimer": 10% more km, one year older.
const kmGrens = computed(() => (form.eisen.km_ruimer ? Math.round((form.eisen.km_max || 0) * 1.1) : form.eisen.km_max || 0))
const jaarGrens = computed(() => (form.eisen.bouwjaar_min || 0) - (form.eisen.bouwjaar_ruimer ? 1 : 0))

// "Goede deal" is the match/deal split, shown as an importance.
const dealBelang = computed({
    get: () => Number(Object.keys(dealW).reduce((best, k) => (Math.abs(dealW[k] - form.w) < Math.abs(dealW[best] - form.w) ? k : best), 3)),
    set: (v) => (form.w = dealW[v]),
})

// Models: drag, or the arrows on a phone.
const byKey = Object.fromEntries(props.modellen.map((m) => [m.key, m]))
const ranked = computed(() => form.modellen.filter((k) => !form.eisen.uitsluiten.includes(k)))
const dragging = ref(null)

function move(key, by) {
    const list = [...form.modellen]
    const i = list.indexOf(key)
    const j = i + by
    if (j < 0 || j >= list.length) return
    ;[list[i], list[j]] = [list[j], list[i]]
    form.modellen = list
}
function startDrag(event, key) {
    dragging.value = key
    // Firefox only drags with data set.
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', key)
}
function dragOver(key) {
    if (!dragging.value || dragging.value === key) return
    const list = form.modellen.filter((k) => k !== dragging.value)
    list.splice(form.modellen.indexOf(key), 0, dragging.value)
    form.modellen = list
}
const excluded = (key) => form.eisen.uitsluiten.includes(key)
function setExcluded(key, on) {
    form.eisen.uitsluiten = on ? [...form.eisen.uitsluiten, key] : form.eisen.uitsluiten.filter((k) => k !== key)
}

// Every colour online now, plus chosen ones no car has today.
const kleurRows = computed(() => [
    ...props.kleuren,
    ...[...new Set([...props.wensen.kleuren, ...props.wensen.eisen.kleuren_uitsluiten])].filter((k) => !props.kleuren.some((c) => c.kleur === k)).map((k) => ({ kleur: k, n: 0 })),
])
// Preferred and excluded rule each other out.
function toggleKleur(kleur, list) {
    const [own, other] = list === 'kleuren' ? ['kleuren', 'kleuren_uitsluiten'] : ['kleuren_uitsluiten', 'kleuren']
    const get = (l) => (l === 'kleuren' ? form.kleuren : form.eisen.kleuren_uitsluiten)
    const set = (l, v) => (l === 'kleuren' ? (form.kleuren = v) : (form.eisen.kleuren_uitsluiten = v))
    const on = !get(own).includes(kleur)
    set(own, on ? [...get(own), kleur] : get(own).filter((k) => k !== kleur))
    if (on) set(other, get(other).filter((k) => k !== kleur))
}

const payload = () => ({ ...form.data(), modellen: ranked.value })

// The live top 5: a moment after the last change, the newest answer wins.
const preview = ref(null)
const previewError = ref(null)
const loading = ref(false)
let timer = null
let asked = 0

async function loadPreview() {
    const ask = ++asked
    loading.value = true
    try {
        const result = await postJson('/wensen/voorbeeld', payload())
        if (ask !== asked) return
        preview.value = result
        previewError.value = null
    } catch (e) {
        if (ask === asked) previewError.value = e.message
    } finally {
        if (ask === asked) loading.value = false
    }
}
watch(() => JSON.stringify(payload()), () => {
    clearTimeout(timer)
    timer = setTimeout(loadPreview, 350)
})
onMounted(loadPreview)
onBeforeUnmount(() => clearTimeout(timer))

const submit = () => form.transform(payload).put('/wensen', { preserveScroll: true })
const restore = () => form.reset()
const useDefaults = () => {
    const s = props.standaard
    Object.assign(form, {
        eisen: { ...s.eisen, uitsluiten: [], kleuren_uitsluiten: [] },
        gewichten: { ...s.gewichten },
        comfort_doel: s.comfort_doel, sport: s.sport, pk_min: s.pk_min,
        kleuren: [], w: s.w,
    })
}

const shift = (v) => (v === null ? { text: 'nieuw', cls: 'bg-teal-50 text-teal-800' } : v > 0 ? { text: `↑${v}`, cls: 'text-emerald-700' } : v < 0 ? { text: `↓${-v}`, cls: 'text-red-700' } : { text: '=', cls: 'text-stone-400' })

const input = 'rounded-md border border-stone-300 bg-white px-2 py-1 text-sm focus:border-teal-600 focus:outline-none'
</script>

<template>
    <Head title="Mijn wensen" />
    <AppLayout>
        <form class="pb-24" @submit.prevent="submit">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Mijn wensen</h1>
                <p class="mt-1 text-sm text-stone-500">Hiermee bepaal je welke auto's je ziet bij "Voor jou" en in welke volgorde.</p>
            </div>
            <p v-if="Object.keys(form.errors).length" class="mt-3 text-sm text-red-700">{{ Object.values(form.errors).join(' ') }}</p>

            <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_20rem] lg:items-start">
                <div class="flex min-w-0 flex-col gap-6">
                    <!-- 1. Hard requirements -->
                    <section class="rounded-lg border border-stone-200 bg-white p-4">
                        <h2 class="font-semibold">Waar moet hij aan voldoen?</h2>
                        <p class="mt-0.5 text-sm text-stone-500">Auto's die hier niet aan voldoen, zie je niet.</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <label class="text-sm">Maximaal budget (€)
                                <input id="w-prijs" v-model.number="form.eisen.prijs_max" type="number" step="500" min="0" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2" />
                            </label>
                            <div class="text-sm">
                                <label>Maximaal aantal km
                                    <input id="w-km" v-model.number="form.eisen.km_max" type="number" step="1000" min="0" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2" />
                                </label>
                                <label class="mt-1.5 flex items-center gap-2"><input id="w-km-ruimer" v-model="form.eisen.km_ruimer" type="checkbox" class="accent-teal-700" /> Iets ruimer</label>
                                <p class="tabular text-xs text-stone-500">tot {{ km(kmGrens) }}</p>
                            </div>
                            <div class="text-sm">
                                <label>Bouwjaar vanaf
                                    <input id="w-bouwjaar" v-model.number="form.eisen.bouwjaar_min" type="number" step="1" min="0" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2" />
                                </label>
                                <label class="mt-1.5 flex items-center gap-2"><input id="w-bouwjaar-ruimer" v-model="form.eisen.bouwjaar_ruimer" type="checkbox" class="accent-teal-700" /> Iets ruimer</label>
                                <p class="tabular text-xs text-stone-500">vanaf {{ jaarGrens }}</p>
                            </div>
                        </div>
                        <div class="mt-4 flex items-center gap-3 text-sm">
                            <Switch id="w-merkdealer" v-model="form.eisen.alleen_merkdealer" label="Alleen merkdealer of merkgarantie" />
                            <span>Alleen merkdealer of merkgarantie</span>
                        </div>
                        <div v-if="kleurRows.length" class="mt-4 text-sm">
                            <p>Deze kleuren wil ik niet:</p>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                <button v-for="c in kleurRows" :key="c.kleur" type="button" class="flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                                        :class="form.eisen.kleuren_uitsluiten.includes(c.kleur) ? 'border-red-300 bg-red-50 text-red-800 line-through' : 'border-stone-300 text-stone-600 hover:bg-stone-50'"
                                        :aria-pressed="form.eisen.kleuren_uitsluiten.includes(c.kleur)" @click="toggleKleur(c.kleur, 'uitsluiten')">
                                    <span class="inline-block size-2.5 rounded-full ring-1 ring-stone-300" :style="{ backgroundColor: kleurSwatch(c.kleur) }" aria-hidden="true"></span>{{ kleurLabel(c.kleur) }}
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- 2. Soft wishes -->
                    <section class="rounded-lg border border-stone-200 bg-white p-4">
                        <h2 class="font-semibold">Wat vind je belangrijk?</h2>
                        <p class="mt-0.5 text-sm text-stone-500">Hoe belangrijker, hoe meer een wens de volgorde bepaalt.</p>
                        <ul class="mt-2 flex flex-col divide-y divide-stone-100 text-sm">
                            <li class="wens">
                                <div><p class="font-medium">Lage prijs <InfoTip :text="wensUitleg.prijs" /></p><p class="text-stone-600">Zo goedkoop mogelijk binnen mijn budget.</p></div>
                                <Belang v-model="form.gewichten.prijs" label="lage prijs" />
                            </li>
                            <li class="wens">
                                <div><p class="font-medium">Goede deal <InfoTip :text="wensUitleg.deal" /></p><p class="text-stone-600">Goedkoop vergeleken met vergelijkbare auto's.</p></div>
                                <Belang v-model="dealBelang" label="goede deal" />
                            </li>
                            <li class="wens">
                                <div><p class="font-medium">Uitrusting <InfoTip :text="wensUitleg.comfort" /></p>
                                    <p class="text-stone-600">Liefst
                                        <select id="w-comfort" v-model.number="form.comfort_doel" :class="input" aria-label="Gewenste uitrusting">
                                            <option :value="1">Basic</option><option :value="2">Redelijk</option><option :value="3">Luxe</option>
                                        </select>.</p></div>
                                <Belang v-model="form.gewichten.comfort" label="uitrusting" />
                            </li>
                            <li class="wens">
                                <div><p class="font-medium">Sportief <InfoTip :text="wensUitleg.sport" /></p>
                                    <p class="text-stone-600">Liefst
                                        <select id="w-sport" v-model="form.sport" :class="input" aria-label="Sportief of niet">
                                            <option value="ja">sportief</option><option value="nee">niet sportief</option>
                                        </select>.</p></div>
                                <Belang v-model="form.gewichten.sport" label="sportief" />
                            </li>
                            <li class="wens">
                                <div><p class="font-medium">Vermogen <InfoTip :text="wensUitleg.pk" /></p>
                                    <p class="text-stone-600">Minimaal <input id="w-pk" v-model.number="form.pk_min" type="number" min="0" step="1" :class="input" class="w-20" aria-label="Minimaal vermogen" /> pk.</p></div>
                                <Belang v-model="form.gewichten.pk" label="vermogen" />
                            </li>
                            <li class="wens" :class="{ 'text-stone-400': form.eisen.alleen_merkdealer }">
                                <div><p class="font-medium">Merkdealer of merkgarantie <InfoTip :text="wensUitleg.merkdealer" /></p>
                                    <p :class="form.eisen.alleen_merkdealer ? 'text-stone-400' : 'text-stone-600'">{{ form.eisen.alleen_merkdealer ? "Al vereist bij 'Waar moet hij aan voldoen?'" : 'Liefst bij een merkdealer of met merkgarantie.' }}</p></div>
                                <Belang :model-value="form.eisen.alleen_merkdealer ? 0 : form.gewichten.merkdealer" :disabled="form.eisen.alleen_merkdealer" label="merkdealer"
                                        @update:model-value="form.gewichten.merkdealer = $event" />
                            </li>
                            <li class="wens">
                                <div><p class="font-medium">Model <InfoTip :text="wensUitleg.model" /></p><p class="text-stone-600">Mijn voorkeursvolgorde (zie hieronder).</p></div>
                                <Belang v-model="form.gewichten.model" label="model" />
                            </li>
                            <li v-if="kleurRows.length" class="wens">
                                <div class="min-w-0"><p class="font-medium">Kleur <InfoTip :text="wensUitleg.kleur" /></p>
                                    <p class="text-stone-600">Liefst in een van deze kleuren:</p>
                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                        <button v-for="c in kleurRows" :key="c.kleur" type="button" class="flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs disabled:opacity-40"
                                                :class="form.kleuren.includes(c.kleur) ? 'border-teal-600 bg-teal-50 text-teal-900' : 'border-stone-300 text-stone-600 enabled:hover:bg-stone-50'"
                                                :disabled="form.eisen.kleuren_uitsluiten.includes(c.kleur)" :aria-pressed="form.kleuren.includes(c.kleur)" @click="toggleKleur(c.kleur, 'kleuren')">
                                            <span class="inline-block size-2.5 rounded-full ring-1 ring-stone-300" :style="{ backgroundColor: kleurSwatch(c.kleur) }" aria-hidden="true"></span>{{ kleurLabel(c.kleur) }}
                                        </button>
                                    </div></div>
                                <Belang v-model="form.gewichten.kleur" label="kleur" />
                            </li>
                        </ul>
                    </section>

                    <!-- 3. Models -->
                    <section class="rounded-lg border border-stone-200 bg-white p-4">
                        <h2 class="font-semibold">Mijn modellen</h2>
                        <p class="mt-0.5 text-sm text-stone-500">Sleep je favoriet naar boven. Een uitgesloten model zie je niet.</p>
                        <ol class="mt-3 flex flex-col gap-1">
                            <li v-for="key in form.modellen" :key="key" draggable="true"
                                class="flex items-center gap-2 rounded-md border px-2 py-1.5 text-sm"
                                :class="[dragging === key ? 'border-teal-600 bg-teal-50' : 'border-stone-200 bg-white', excluded(key) ? 'opacity-50' : '']"
                                @dragstart="startDrag($event, key)" @dragenter.prevent="dragOver(key)" @dragover.prevent @dragend="dragging = null" @drop.prevent="dragging = null">
                                <span class="cursor-grab px-1 text-stone-400 select-none" aria-hidden="true">⠿</span>
                                <span class="min-w-0 flex-1 truncate" :class="{ 'line-through': excluded(key) }">{{ byKey[key].naam }} <span class="text-xs text-stone-400">{{ num(byKey[key].n) }} online</span></span>
                                <button type="button" class="rounded px-2 py-1 hover:bg-stone-100" :aria-label="`${byKey[key].naam} omhoog`" @click="move(key, -1)">↑</button>
                                <button type="button" class="rounded px-2 py-1 hover:bg-stone-100" :aria-label="`${byKey[key].naam} omlaag`" @click="move(key, 1)">↓</button>
                                <span class="flex items-center gap-1.5 text-xs text-stone-600">
                                    <Switch :id="`x-${key}`" :model-value="excluded(key)" :label="`${byKey[key].naam} uitsluiten`" @update:model-value="setExcluded(key, $event)" />
                                    <span class="hidden sm:inline">Uitsluiten</span>
                                </span>
                            </li>
                        </ol>
                    </section>
                </div>

                <!-- Live preview -->
                <aside class="rounded-lg border border-stone-200 bg-white p-4 lg:sticky lg:top-4">
                    <div class="flex items-baseline justify-between gap-2">
                        <h2 class="font-semibold">Jouw top 5 met deze instellingen</h2>
                        <span v-if="loading" class="text-xs text-stone-400">bijwerken…</span>
                    </div>
                    <p v-if="preview" class="mt-0.5 text-sm text-stone-500">Nog <span class="tabular font-medium text-stone-900">{{ num(preview.voldoen) }}</span> auto's voldoen aan je eisen.</p>
                    <p v-if="previewError" class="mt-2 text-sm text-red-700">{{ previewError }}</p>
                    <ol v-if="preview?.top.length" class="mt-3 flex flex-col gap-2" :class="{ 'opacity-60': loading }">
                        <li v-for="r in preview.top" :key="r.id">
                            <Link :href="`/listings/${r.id}`" class="flex gap-2 rounded-md p-1 hover:bg-stone-50">
                                <CarPhoto :src="r.car.foto" :alt="r.car.title" class="h-14 w-20 shrink-0" />
                                <div class="min-w-0 flex-1 text-xs">
                                    <p class="truncate text-sm font-medium">{{ r.rang }}. {{ r.car.title }}</p>
                                    <p class="tabular text-stone-500">{{ r.car.bouwjaar }} · {{ km(r.car.kilometerstand) }}</p>
                                    <p class="tabular font-semibold">{{ euro(r.car.prijs) }}</p>
                                </div>
                                <div class="tabular flex flex-col items-end text-xs">
                                    <span class="text-base font-semibold">{{ Math.round(r.totaalscore) }}</span>
                                    <span class="rounded px-1 font-medium" :class="shift(r.verschuiving).cls" :title="r.verschuiving === null ? 'Staat nu niet in je ranglijst' : 'Ten opzichte van je opgeslagen wensen'">{{ shift(r.verschuiving).text }}</span>
                                </div>
                            </Link>
                        </li>
                    </ol>
                    <p v-else-if="preview" class="mt-3 text-sm text-stone-500">Geen enkele auto voldoet aan je eisen.</p>
                </aside>
            </div>

            <div class="fixed inset-x-0 bottom-0 z-20 border-t border-stone-200 bg-white/95 backdrop-blur">
                <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                    <span class="text-sm text-stone-500">{{ form.isDirty ? 'Je hebt niet-opgeslagen wijzigingen.' : 'Alles is opgeslagen.' }}</span>
                    <button type="button" class="text-sm text-stone-500 underline hover:text-stone-900" @click="useDefaults">Standaardwaarden</button>
                    <div class="ml-auto flex gap-2">
                        <button type="button" class="rounded-md border border-stone-300 bg-white px-3 py-2 text-sm enabled:hover:bg-stone-50 disabled:opacity-40" :disabled="!form.isDirty || form.processing" @click="restore">Terug naar opgeslagen</button>
                        <button class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white enabled:hover:bg-teal-800 disabled:opacity-40" :disabled="!form.isDirty || form.processing">Opslaan</button>
                    </div>
                </div>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
@reference "../../../css/app.css";

.wens {
    @apply flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-6;
}
</style>
