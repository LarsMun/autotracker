<script setup>
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import CarCard from './CarCard.vue'
import { postJson } from '../lib/http'
import { num } from '../lib/format'

const form = useForm({ name: '', url: '', run_now: true })
const preview = ref(null)
const loading = ref(false)
const error = ref(null)

const suggestedName = (url) => {
    try {
        const u = new URL(url)
        const parts = u.pathname.split('/').filter(Boolean).map((p) => p.charAt(0).toUpperCase() + p.slice(1).replace(/-/g, ' '))
        const bmin = u.searchParams.get('bmin')
        return parts.join(' ') + (bmin ? ` ${bmin}+` : '')
    } catch {
        return ''
    }
}

async function loadPreview() {
    if (!form.url) return
    loading.value = true
    error.value = null
    preview.value = null

    try {
        preview.value = await postJson('/sources/preview', { url: form.url })
        form.url = preview.value.url
        if (!form.name) form.name = suggestedName(preview.value.url)
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
}

const modelEntries = computed(() => Object.entries(preview.value?.models ?? {}))
const mixed = computed(() => modelEntries.value.length > 1)

const submit = () => form.post('/sources')
</script>

<template>
    <section>
        <h2 class="text-lg font-semibold">Bron toevoegen</h2>
        <p class="mt-1 text-sm text-stone-500">Plak een zoek-URL van gaspedaal.nl, met de filters die je wilt. Je ziet direct wat pagina 1 oplevert.</p>

        <form class="mt-3 space-y-4 rounded-lg border border-stone-200 bg-white p-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm font-medium" for="url">Zoek-URL</label>
                <div class="mt-1 flex flex-wrap gap-2">
                    <input id="url" v-model="form.url" type="url" required placeholder="https://www.gaspedaal.nl/bmw/3-serie/stationwagen?bmin=2020"
                           class="min-w-0 flex-1 rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none"
                           @blur="!preview && loadPreview()" @keydown.enter.prevent="loadPreview" />
                    <button type="button" class="rounded-md border border-stone-300 px-3 py-2 text-sm hover:bg-stone-50 disabled:opacity-50" :disabled="loading || !form.url" @click="loadPreview">
                        {{ loading ? 'Ophalen…' : 'Voorbeeld' }}
                    </button>
                </div>
                <p v-if="form.errors.url" class="mt-1 text-sm text-red-700">{{ form.errors.url }}</p>
                <p v-if="error" class="mt-1 text-sm text-red-700">{{ error }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium" for="name">Naam</label>
                <input id="name" v-model="form.name" required class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none" />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-700">{{ form.errors.name }}</p>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.run_now" type="checkbox" class="rounded border-stone-300" />
                Meteen een eerste volledige run starten
            </label>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 disabled:opacity-50" :disabled="form.processing || !preview">
                    Bron opslaan
                </button>
                <span v-if="!preview" class="text-xs text-stone-500">Bekijk eerst het voorbeeld.</span>
            </div>
        </form>

        <section v-if="preview" class="mt-6">
            <div v-if="preview.existing" class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                Deze URL bestaat al als bron "{{ preview.existing.name }}".
            </div>

            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                <p class="text-lg font-semibold"><span class="tabular">{{ num(preview.total) }}</span> auto's</p>
                <p class="text-sm text-stone-500">{{ preview.pages }} pagina's van 100 · sortering {{ preview.sort }}</p>
            </div>

            <div class="mt-2 rounded-md border px-4 py-2 text-sm" :class="mixed ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-stone-200 bg-white text-stone-600'">
                <p v-if="mixed" class="font-medium">Pagina 1 bevat meerdere modellen. Gaspedaal maakt een zoekopdracht met een onbekend model stil breder; controleer de URL.</p>
                <p>
                    <span v-for="([model, count], i) in modelEntries" :key="model">{{ i ? ' · ' : '' }}{{ model }}: {{ count }}</span>
                </p>
            </div>

            <p class="mt-4 mb-2 text-sm text-stone-500">Pagina 1 ({{ preview.cars.length }} auto's){{ preview.cars.some((c) => c.known) ? ' — gemarkeerd: al bekend uit een andere bron' : '' }}</p>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <CarCard v-for="car in preview.cars" :key="car.advertentie_id" :car="car" :class="{ 'ring-2 ring-teal-200': car.known }" />
            </div>
        </section>
    </section>
</template>
