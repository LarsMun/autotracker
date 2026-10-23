<script setup>
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

const page = usePage()
const problems = computed(() => page.props.problems ?? [])
const flash = ref(page.props.flash)

watch(() => page.props.flash, (v) => (flash.value = v))

const nav = [
    { href: '/voor-jou', label: 'Voor jou', match: (u) => u.startsWith('/voor-jou') },
    { href: '/wensen', label: 'Mijn wensen', match: (u) => u.startsWith('/wensen') },
    { href: '/analyse', label: 'Analyse', match: (u) => u.startsWith('/analyse') },
    { href: '/bronnen', label: 'Bronnen', match: (u) => u.startsWith('/bronnen') || u.startsWith('/sources/') },
    { href: '/runs', label: 'Runs', match: (u) => u.startsWith('/runs') },
]
</script>

<template>
    <div class="min-h-screen">
        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                <Link href="/voor-jou" class="font-semibold tracking-tight">auto<span class="text-teal-700">.munne.me</span></Link>
                <nav class="flex gap-1 text-sm">
                    <Link v-for="item in nav" :key="item.href" :href="item.href"
                          class="rounded-md px-3 py-1.5"
                          :class="item.match(page.url.split('?')[0]) ? 'bg-stone-100 font-medium' : 'text-stone-600 hover:bg-stone-50'">
                        {{ item.label }}
                    </Link>
                </nav>
                <button v-if="page.props.user" class="ml-auto text-sm text-stone-500 hover:text-stone-900" @click="router.post('/logout')">
                    Uitloggen
                </button>
            </div>
        </header>

        <div v-if="problems.length" class="border-b border-red-200 bg-red-50">
            <div class="mx-auto max-w-6xl px-4 py-3 text-sm text-red-900">
                <p class="font-semibold">Let op: de dataverzameling heeft een probleem</p>
                <ul class="mt-1 list-disc pl-5">
                    <li v-for="(p, i) in problems" :key="i">
                        <Link :href="`/sources/${p.source_id}`" class="font-medium underline">{{ p.source }}</Link>: {{ p.problem }}
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="flash" class="mx-auto mt-4 max-w-6xl px-4">
            <div class="flex items-start justify-between rounded-md border border-teal-200 bg-teal-50 px-4 py-2 text-sm text-teal-900">
                <span>{{ flash }}</span>
                <button class="ml-4 text-teal-700" @click="flash = null" aria-label="Sluiten">×</button>
            </div>
        </div>

        <main class="mx-auto max-w-6xl px-4 py-6">
            <slot />
        </main>
    </div>
</template>
