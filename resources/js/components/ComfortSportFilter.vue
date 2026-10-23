<script setup>
import { comfortDots, comfortLabel, parseLevels, toggleLevel } from '../lib/uitrusting'

// v-model:comfort ("1,3") and v-model:sport ("ja" / "nee" / "").
const comfort = defineModel('comfort', { type: String, default: '' })
const sport = defineModel('sport', { type: String, default: '' })
</script>

<template>
    <div class="flex flex-wrap items-end gap-3">
        <fieldset class="text-xs text-stone-500">
            <legend>Comfortniveau</legend>
            <div class="mt-1 flex rounded-md border border-stone-300 bg-white p-0.5">
                <button v-for="n in [1, 2, 3]" :key="n" type="button" class="rounded px-2 py-1 text-sm"
                        :class="parseLevels(comfort).includes(n) ? 'bg-teal-700 text-white' : 'text-stone-700 hover:bg-stone-100'"
                        :aria-pressed="parseLevels(comfort).includes(n)" :title="`${n}: ${comfortLabel[n]}`"
                        @click="comfort = toggleLevel(comfort, n)">
                    <span class="text-xs tracking-tight">{{ comfortDots(n) }}</span>
                </button>
            </div>
        </fieldset>
        <label class="text-xs text-stone-500">Sport
            <select id="f-sport" v-model="sport" class="mt-1 block w-full rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm text-stone-900">
                <option value="">Alle</option>
                <option value="ja">Ja</option>
                <option value="nee">Nee</option>
            </select>
        </label>
    </div>
</template>
