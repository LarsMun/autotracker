<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AppLayout from '../../layouts/AppLayout.vue'
import Belang from '../../components/Belang.vue'
import CarPhoto from '../../components/CarPhoto.vue'
import Cijfer from '../../components/Cijfer.vue'
import { postJson } from '../../lib/http'
import { euro, km, num } from '../../lib/format'

const props = defineProps({ vakken: Object, standaard: Object })

const copy = (vakken) => ({ vakken: Object.fromEntries(Object.entries(vakken).map(([k, v]) => [k, { ...v }])) })
const form = useForm(copy(props.vakken))

// ---------- The live top 5: a moment after the last change, the newest answer wins ----------
const preview = ref(null)
const previewError = ref(null)
const loading = ref(false)
let timer = null
let asked = 0

async function loadPreview() {
    const ask = ++asked
    loading.value = true
    try {
        const result = await postJson('/wensen/voorbeeld', form.data())
        if (ask !== asked) return
        preview.value = result
        previewError.value = null
    } catch (e) {
        if (ask === asked) previewError.value = e.message
    } finally {
        if (ask === asked) loading.value = false
    }
}
watch(() => JSON.stringify(form.data()), () => {
    clearTimeout(timer)
    timer = setTimeout(loadPreview, 350)
})
onMounted(loadPreview)
onBeforeUnmount(() => clearTimeout(timer))

const submit = () => form.put('/wensen', { preserveScroll: true })
const restore = () => form.reset()
const useDefaults = () => Object.assign(form, copy(props.standaard))

const shift = (v) => (v === null ? { text: 'nieuw', cls: 'bg-teal-50 text-teal-800' } : v > 0 ? { text: `↑${v}`, cls: 'text-emerald-700' } : v < 0 ? { text: `↓${-v}`, cls: 'text-red-700' } : { text: '=', cls: 'text-stone-400' })

const inline = 'tabular rounded-md border border-stone-300 bg-white px-2 py-1 text-sm focus:border-teal-600 focus:outline-none'
</script>

<template>
    <Head title="Mijn wensen" />
    <AppLayout>
        <form class="pb-24" @submit.prevent="submit">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Mijn wensen</h1>
                <p class="mt-1 text-sm text-stone-500">Je rapportcijfer bepaalt de volgorde bij "Voor jou". Welke auto's je daar ziet, kies je met de filters boven de lijst.</p>
            </div>
            <p v-if="Object.keys(form.errors).length" class="mt-3 text-sm text-red-700">{{ Object.values(form.errors).join(' ') }}</p>

            <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_20rem] lg:items-start">
                <div class="flex min-w-0 flex-col gap-6">
                    <!-- 2. The vakken of the rapportcijfer -->
                    <section class="rounded-lg border border-stone-200 bg-white p-4">
                        <h2 class="font-semibold">Je rapportcijfer</h2>
                        <p class="mt-0.5 text-sm text-stone-500">
                            Elk vak krijgt een cijfer van 1 tot 10, op een rechte lijn door de 6 en de 10 die je hier instelt. Het rapportcijfer is het gemiddelde, gewogen naar hoe belangrijk je elk vak vindt.
                        </p>
                        <ul class="mt-2 flex flex-col divide-y divide-stone-100 text-sm">
                            <li class="vak">
                                <div><p class="font-medium">Deal</p>
                                    <p class="text-stone-600">Een 10 bij <input id="w-deal-tien" v-model.number="form.vakken.deal.tien" type="number" min="1" max="50" step="1" :class="inline" class="w-16" aria-label="Procent onder de markt voor een 10" />% onder de prijs van vergelijkbare auto's; de marktprijs is een 6.</p></div>
                                <Belang v-model="form.vakken.deal.belang" label="deal" />
                            </li>
                            <li class="vak">
                                <div><p class="font-medium">Kilometers</p>
                                    <p class="text-stone-600">Een 10 bij <input id="w-km-tien" v-model.number="form.vakken.km.tien" type="number" min="0" step="1000" :class="inline" class="w-24" aria-label="Kilometers voor een 10" /> km of minder, een 6 bij <input id="w-km-zes" v-model.number="form.vakken.km.zes" type="number" min="1" step="1000" :class="inline" class="w-24" aria-label="Kilometers voor een 6" /> km.</p></div>
                                <Belang v-model="form.vakken.km.belang" label="kilometers" />
                            </li>
                            <li class="vak">
                                <div><p class="font-medium">Bouwjaar</p>
                                    <p class="text-stone-600">Een 10 vanaf <input id="w-bouwjaar-tien" v-model.number="form.vakken.bouwjaar.tien" type="number" step="1" :class="inline" class="w-20" aria-label="Bouwjaar voor een 10" />, een 6 bij <input id="w-bouwjaar-zes" v-model.number="form.vakken.bouwjaar.zes" type="number" step="1" :class="inline" class="w-20" aria-label="Bouwjaar voor een 6" />.</p></div>
                                <Belang v-model="form.vakken.bouwjaar.belang" label="bouwjaar" />
                            </li>
                            <li class="vak">
                                <div><p class="font-medium">Luxe</p>
                                    <p class="text-stone-600">Een luxe uitvoering is een 10, redelijk een 7, basic een 4. Een uitvoering die niet herkend wordt, telt niet mee.</p></div>
                                <Belang v-model="form.vakken.luxe.belang" label="luxe" />
                            </li>
                            <li class="vak">
                                <div><p class="font-medium">Vermogen</p>
                                    <p class="text-stone-600">Een 10 vanaf <input id="w-pk-tien" v-model.number="form.vakken.vermogen.tien" type="number" min="1" step="5" :class="inline" class="w-20" aria-label="Vermogen voor een 10" /> pk, een 6 bij <input id="w-pk-min" v-model.number="form.vakken.vermogen.zes" type="number" min="1" step="5" :class="inline" class="w-20" aria-label="Vermogen voor een 6" /> pk.</p></div>
                                <Belang v-model="form.vakken.vermogen.belang" label="vermogen" />
                            </li>
                        </ul>
                        <p class="mt-2 text-xs text-stone-500">Bij een gelijk cijfer staat de goedkoopste auto bovenaan.</p>
                    </section>
                </div>

                <!-- Live preview -->
                <aside class="rounded-lg border border-stone-200 bg-white p-4 lg:sticky lg:top-4">
                    <div class="flex items-baseline justify-between gap-2">
                        <h2 class="font-semibold">Jouw top 5 met deze instellingen</h2>
                        <span v-if="loading" class="text-xs text-stone-400">bijwerken…</span>
                    </div>
                    <p v-if="preview" class="mt-0.5 text-sm text-stone-500">Binnen je filters bij "Voor jou": <span class="tabular font-medium text-stone-900">{{ num(preview.voldoen) }}</span> auto's.</p>
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
                                <div class="tabular flex flex-col items-end gap-1 text-xs">
                                    <Cijfer :value="r.cijfer" />
                                    <span class="rounded px-1 font-medium" :class="shift(r.verschuiving).cls" :title="r.verschuiving === null ? 'Staat nu niet in je ranglijst' : 'Ten opzichte van je opgeslagen wensen'">{{ shift(r.verschuiving).text }}</span>
                                </div>
                            </Link>
                        </li>
                    </ol>
                    <p v-else-if="preview" class="mt-3 text-sm text-stone-500">Geen enkele auto komt door je filters.</p>
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

.vak {
    @apply flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-6;
}
</style>
