<script setup>
import { Head } from '@inertiajs/vue3'
import AppLayout from '../../layouts/AppLayout.vue'
import Pagination from '../../components/Pagination.vue'
import RunsTable from '../../components/RunsTable.vue'

defineProps({ runs: Object })
</script>

<template>
    <Head title="Runs" />
    <AppLayout>
        <h1 class="mb-4 text-2xl font-semibold tracking-tight">Runs</h1>
        <RunsTable :runs="runs.data" />
        <Pagination :paginator="runs" />
    </AppLayout>
</template>
