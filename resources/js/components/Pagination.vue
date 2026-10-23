<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({ paginator: Object })
</script>

<template>
    <nav v-if="paginator.last_page > 1" class="mt-4 flex flex-wrap items-center gap-1 text-sm">
        <template v-for="(link, i) in paginator.links" :key="i">
            <Link v-if="link.url" :href="link.url" preserve-scroll
                  class="rounded-md px-3 py-1"
                  :class="link.active ? 'bg-teal-700 text-white' : 'border border-stone-200 bg-white hover:bg-stone-50'"
                  v-html="link.label" />
            <span v-else class="px-2 py-1 text-stone-300" v-html="link.label" />
        </template>
    </nav>
</template>
