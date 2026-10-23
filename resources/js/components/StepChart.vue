<script setup>
import { computed, ref } from 'vue'
import { date } from '../lib/format'

// One series over time, drawn as steps: a price holds until the next day it changes.
const props = defineProps({
    points: Array, // [{ date: 'YYYY-MM-DD', value: number|null }]
    format: Function,
    label: String,
})

const W = 640
const H = 180
const pad = { top: 12, right: 12, bottom: 24, left: 72 }

const data = computed(() => props.points.filter((p) => p.value != null).map((p) => ({ ...p, t: new Date(`${p.date}T12:00:00`).getTime() })))

const scales = computed(() => {
    const d = data.value
    const t0 = d[0]?.t ?? 0
    const t1 = Math.max(d[d.length - 1]?.t ?? 1, t0 + 86400000)
    let v0 = Math.min(...d.map((p) => p.value))
    let v1 = Math.max(...d.map((p) => p.value))
    const span = v1 - v0 || Math.max(Math.abs(v1) * 0.05, 1)
    v0 -= span * 0.15
    v1 += span * 0.15
    return {
        x: (t) => pad.left + ((t - t0) / (t1 - t0)) * (W - pad.left - pad.right),
        y: (v) => pad.top + (1 - (v - v0) / (v1 - v0)) * (H - pad.top - pad.bottom),
        v0,
        v1,
        t0,
        t1,
    }
})

const path = computed(() => {
    const { x, y } = scales.value
    return data.value
        .map((p, i) => (i === 0 ? `M${x(p.t)},${y(p.value)}` : `H${x(p.t)}V${y(p.value)}`))
        .join('')
})

const changes = computed(() => data.value.filter((p, i, a) => i === 0 || p.value !== a[i - 1].value))

const ticks = computed(() => {
    const { v0, v1 } = scales.value
    return [0.15, 0.5, 0.85].map((f) => v0 + (v1 - v0) * f)
})

const hover = ref(null)
const svg = ref(null)

function move(e) {
    const rect = svg.value.getBoundingClientRect()
    const px = ((e.clientX - rect.left) / rect.width) * W
    const { x } = scales.value
    let best = null
    for (const p of data.value) if (x(p.t) <= px + 0.5) best = p
    hover.value = best ?? data.value[0]
}
</script>

<template>
    <figure>
        <figcaption class="mb-1 text-sm font-medium">{{ label }}</figcaption>
        <div v-if="data.length < 2" class="flex h-24 items-center justify-center rounded-md bg-stone-50 text-sm text-stone-400">
            Nog te weinig dagen voor een grafiek.
        </div>
        <div v-else class="relative">
            <svg ref="svg" :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" :aria-label="label" @mousemove="move" @mouseleave="hover = null">
                <g class="text-stone-400" font-size="11">
                    <template v-for="v in ticks" :key="v">
                        <line :x1="pad.left" :x2="W - pad.right" :y1="scales.y(v)" :y2="scales.y(v)" stroke="currentColor" stroke-opacity="0.25" />
                        <text :x="pad.left - 8" :y="scales.y(v) + 4" text-anchor="end" fill="#78716c" class="tabular">{{ format(Math.round(v)) }}</text>
                    </template>
                    <text :x="pad.left" :y="H - 6" fill="#78716c">{{ date(data[0].date) }}</text>
                    <text :x="W - pad.right" :y="H - 6" text-anchor="end" fill="#78716c">{{ date(data[data.length - 1].date) }}</text>
                </g>
                <path :d="path" fill="none" stroke="#0f766e" stroke-width="2" stroke-linejoin="round" />
                <circle v-for="p in changes" :key="p.date" :cx="scales.x(p.t)" :cy="scales.y(p.value)" r="4" fill="#0f766e" stroke="white" stroke-width="2" />
                <g v-if="hover">
                    <line :x1="scales.x(hover.t)" :x2="scales.x(hover.t)" :y1="pad.top" :y2="H - pad.bottom" stroke="#a8a29e" stroke-dasharray="3 3" />
                    <circle :cx="scales.x(hover.t)" :cy="scales.y(hover.value)" r="5" fill="#0f766e" stroke="white" stroke-width="2" />
                </g>
                <rect :x="pad.left" :y="0" :width="W - pad.left - pad.right" :height="H" fill="transparent" />
            </svg>
            <div v-if="hover" class="pointer-events-none absolute top-0 rounded-md border border-stone-200 bg-white px-2 py-1 text-xs shadow-sm"
                 :style="{ left: `${(scales.x(hover.t) / W) * 100}%`, transform: scales.x(hover.t) > W / 2 ? 'translateX(calc(-100% - 8px))' : 'translateX(8px)' }">
                <p class="text-stone-500">{{ date(hover.date) }}</p>
                <p class="tabular font-semibold text-stone-900">{{ format(hover.value) }}</p>
            </div>
        </div>
    </figure>
</template>
