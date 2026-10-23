<script setup>
import { computed, ref } from 'vue'
import { date } from '../lib/format'

// Several series over the same days, one y-axis. Optionally a separate panel below with the
// number of cars (n) per day, on its own scale: the count a point rests on.
const props = defineProps({
    series: Array, // [{ key, label, color, dash?, points: [{ date, value, n? }] }]
    format: Function,
    counts: { type: Array, default: null }, // [{ date, n }] for the n panel
    height: { type: Number, default: 240 },
})

const W = 760
const pad = { top: 14, right: 16, bottom: 26, left: 78 }
const countsH = 56
const H = computed(() => props.height + (props.counts ? countsH + 10 : 0))
const plotBottom = computed(() => props.height - pad.bottom)

const toT = (d) => new Date(`${d}T12:00:00`).getTime()
const DAY = 86400000

const drawn = computed(() =>
    props.series.map((s) => ({ ...s, points: s.points.filter((p) => p.value != null).map((p) => ({ ...p, t: toT(p.date) })) })).filter((s) => s.points.length),
)

const dates = computed(() => {
    const all = new Set()
    drawn.value.forEach((s) => s.points.forEach((p) => all.add(p.date)))
    props.counts?.forEach((c) => all.add(c.date))
    return [...all].sort()
})

const scale = computed(() => {
    const ts = dates.value.map(toT)
    let t0 = Math.min(...ts)
    let t1 = Math.max(...ts)
    if (t0 === t1) {
        t0 -= DAY
        t1 += DAY
    }
    const values = drawn.value.flatMap((s) => s.points.map((p) => p.value))
    let v0 = Math.min(...values)
    let v1 = Math.max(...values)
    const span = v1 - v0 || Math.max(Math.abs(v1) * 0.05, 1)
    v0 -= span * 0.12
    v1 += span * 0.12
    const nMax = Math.max(1, ...(props.counts ?? []).map((c) => c.n))
    const cTop = props.height + 4
    return {
        x: (t) => pad.left + ((t - t0) / (t1 - t0)) * (W - pad.left - pad.right),
        y: (v) => pad.top + (1 - (v - v0) / (v1 - v0)) * (plotBottom.value - pad.top),
        cy: (n) => cTop + countsH - (n / nMax) * countsH,
        cTop,
        nMax,
        v0,
        v1,
    }
})

// Five round tick values inside the range.
const ticks = computed(() => {
    const { v0, v1 } = scale.value
    const raw = (v1 - v0) / 4
    const mag = 10 ** Math.floor(Math.log10(raw))
    const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw)
    const out = []
    for (let v = Math.ceil(v0 / step) * step; v <= v1; v += step) out.push(v)
    return out
})

const path = (points) => points.map((p, i) => `${i ? 'L' : 'M'}${scale.value.x(p.t).toFixed(1)},${scale.value.y(p.value).toFixed(1)}`).join('')

const barW = computed(() => Math.max(2, Math.min(14, ((W - pad.left - pad.right) / Math.max(dates.value.length, 1)) * 0.6)))

const hover = ref(null)
const svg = ref(null)

function move(e) {
    const rect = svg.value.getBoundingClientRect()
    const px = ((e.clientX - rect.left) / rect.width) * W
    let best = null
    let dist = Infinity
    for (const d of dates.value) {
        const dd = Math.abs(scale.value.x(toT(d)) - px)
        if (dd < dist) {
            dist = dd
            best = d
        }
    }
    hover.value = best
}

const hoverRows = computed(() => {
    if (!hover.value) return []
    return drawn.value
        .map((s) => ({ key: s.key, label: s.label, color: s.color, dash: s.dash, point: s.points.find((p) => p.date === hover.value) }))
        .filter((r) => r.point)
})
const hoverCount = computed(() => props.counts?.find((c) => c.date === hover.value)?.n)
const hoverX = computed(() => (hover.value ? scale.value.x(toT(hover.value)) : 0))

// Direct labels at the line ends for up to four series, nudged apart when they collide.
const endLabels = computed(() => {
    if (drawn.value.length < 2 || drawn.value.length > 4) return []
    const labels = drawn.value
        .map((s) => {
            const p = s.points[s.points.length - 1]
            return { key: s.key, label: s.label, x: scale.value.x(p.t), y: scale.value.y(p.value) }
        })
        .sort((a, b) => a.y - b.y)
    for (let i = 1; i < labels.length; i++) if (labels[i].y - labels[i - 1].y < 13) labels[i].y = labels[i - 1].y + 13
    return labels
})
</script>

<template>
    <div>
        <ul v-if="drawn.length >= 2" class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-stone-600">
            <li v-for="s in drawn" :key="s.key" class="flex items-center gap-1.5">
                <svg width="18" height="8" aria-hidden="true"><line x1="0" y1="4" x2="18" y2="4" :stroke="s.color" stroke-width="2" :stroke-dasharray="s.dash ? '4 3' : null" /></svg>
                {{ s.label }}
            </li>
        </ul>

        <div v-if="!drawn.length" class="flex h-32 items-center justify-center rounded-md bg-stone-50 text-sm text-stone-400">Geen waarnemingen voor deze selectie.</div>

        <div v-else class="relative">
            <svg ref="svg" :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" aria-label="Prijs per dag" @mousemove="move" @mouseleave="hover = null">
                <g font-size="11" fill="#78716c">
                    <template v-for="v in ticks" :key="v">
                        <line :x1="pad.left" :x2="W - pad.right" :y1="scale.y(v)" :y2="scale.y(v)" stroke="#e7e5e4" />
                        <text :x="pad.left - 8" :y="scale.y(v) + 4" text-anchor="end" class="tabular">{{ format(v) }}</text>
                    </template>
                    <text :x="scale.x(toT(dates[0]))" :y="plotBottom + 18" :text-anchor="dates.length > 1 ? 'start' : 'middle'">{{ date(dates[0]) }}</text>
                    <text v-if="dates.length > 1" :x="scale.x(toT(dates[dates.length - 1]))" :y="plotBottom + 18" text-anchor="end">{{ date(dates[dates.length - 1]) }}</text>
                </g>

                <g v-for="s in drawn" :key="s.key">
                    <path v-if="s.points.length > 1" :d="path(s.points)" fill="none" :stroke="s.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" :stroke-dasharray="s.dash ? '5 4' : null" />
                    <template v-if="s.points.length === 1 || dates.length <= 31">
                        <circle v-for="p in s.points" :key="p.date" :cx="scale.x(p.t)" :cy="scale.y(p.value)" :r="s.points.length === 1 ? 4.5 : 2.5" :fill="s.color" stroke="white" stroke-width="1.5" />
                    </template>
                </g>

                <g font-size="11" fill="#44403c">
                    <text v-for="l in endLabels" :key="l.key" :x="Math.min(l.x + 6, W - pad.right)" :y="l.y + 4" :text-anchor="l.x + 80 > W ? 'end' : 'start'" :dx="l.x + 80 > W ? -10 : 0">{{ l.label }}</text>
                </g>

                <g v-if="counts">
                    <text :x="pad.left - 8" :y="scale.cTop + 10" text-anchor="end" font-size="11" fill="#78716c">n</text>
                    <text :x="pad.left - 8" :y="scale.cTop + countsH" text-anchor="end" font-size="10" fill="#a8a29e" class="tabular">max {{ scale.nMax }}</text>
                    <line :x1="pad.left" :x2="W - pad.right" :y1="scale.cTop + countsH" :y2="scale.cTop + countsH" stroke="#d6d3d1" />
                    <rect v-for="c in counts" :key="c.date" :x="scale.x(toT(c.date)) - barW / 2" :y="scale.cy(c.n)" :width="barW" :height="scale.cTop + countsH - scale.cy(c.n)" rx="1.5"
                          :fill="hover === c.date ? '#57534e' : '#a8a29e'" />
                </g>

                <g v-if="hover">
                    <line :x1="hoverX" :x2="hoverX" :y1="pad.top" :y2="counts ? scale.cTop + countsH : plotBottom" stroke="#a8a29e" stroke-dasharray="3 3" />
                    <circle v-for="r in hoverRows" :key="r.key" :cx="hoverX" :cy="scale.y(r.point.value)" r="4.5" :fill="r.color" stroke="white" stroke-width="2" />
                </g>
                <rect :x="pad.left" y="0" :width="W - pad.left - pad.right" :height="H" fill="transparent" />
            </svg>

            <div v-if="hover" class="pointer-events-none absolute top-1 z-10 min-w-40 rounded-md border border-stone-200 bg-white px-2.5 py-1.5 text-xs shadow-sm"
                 :style="{ left: `${(hoverX / W) * 100}%`, transform: hoverX > W / 2 ? 'translateX(calc(-100% - 10px))' : 'translateX(10px)' }">
                <p class="mb-1 text-stone-500">{{ date(hover) }}</p>
                <p v-for="r in hoverRows" :key="r.key" class="tabular flex items-center justify-between gap-3">
                    <span class="flex items-center gap-1.5 text-stone-600"><span class="inline-block h-0.5 w-3" :style="{ background: r.color }"></span>{{ r.label }}</span>
                    <span class="font-semibold text-stone-900">{{ format(r.point.value) }}<span v-if="r.point.n != null" class="ml-1 font-normal text-stone-500">n={{ r.point.n }}</span></span>
                </p>
                <p v-if="hoverCount != null" class="tabular mt-0.5 text-stone-500">{{ hoverCount }} auto's</p>
            </div>
        </div>
    </div>
</template>
