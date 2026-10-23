<script setup>
import { Head, useForm } from '@inertiajs/vue3'

const form = useForm({ name: '', password: '', remember: true })
const submit = () => form.post('/login', { onFinish: () => form.reset('password') })
</script>

<template>
    <Head title="Inloggen" />
    <div class="flex min-h-screen items-center justify-center px-4">
        <form class="w-full max-w-sm space-y-4 rounded-lg border border-stone-200 bg-white p-6" @submit.prevent="submit">
            <p class="text-lg font-semibold tracking-tight">auto<span class="text-teal-700">.munne.me</span></p>

            <div>
                <label class="block text-sm font-medium" for="name">Naam</label>
                <input id="name" v-model="form.name" autocomplete="username" required autofocus
                       class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none" />
            </div>

            <div>
                <label class="block text-sm font-medium" for="password">Wachtwoord</label>
                <input id="password" v-model="form.password" type="password" autocomplete="current-password" required
                       class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none" />
            </div>

            <p v-if="form.errors.name || form.errors.password" class="text-sm text-red-700">{{ form.errors.name || form.errors.password }}</p>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.remember" type="checkbox" class="rounded border-stone-300" />
                Ingelogd blijven
            </label>

            <button type="submit" class="w-full rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 disabled:opacity-50" :disabled="form.processing">
                Inloggen
            </button>
        </form>
    </div>
</template>
