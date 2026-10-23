<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AppLayout from '../../layouts/AppLayout.vue'
import CarPhoto from '../../components/CarPhoto.vue'
import Cijfer from '../../components/Cijfer.vue'
import StepChart from '../../components/StepChart.vue'
import { comfortDots, comfortLabel, optieLabel } from '../../lib/uitrusting'
import { kleurLabel } from '../../lib/kleur'
import { belangLabel, cijferText, cijferTextClass, vakLabel, vakOnbekend, verruimingLabel } from '../../lib/wensen'
import { garantieLabel, merkdealerUitleg, sellerLabel } from '../../lib/seller'
import { date, dateTime, euro, km, num, signed } from '../../lib/format'

const props = defineProps({ listing: Object, sources: Array, history: Array, changes: Array, portalen: Array, raw: Object, snapshot_count: Number, score: Object })
const showComparables = ref(false)

const priceSeries = computed(() => props.history.map((h) => ({ date: h.date, value: h.prijs })))
const kmSeries = computed(() => props.history.map((h) => ({ date: h.date, value: h.kilometerstand })))
const showRaw = ref(false)

const first = computed(() => props.history.find((h) => h.prijs != null))
const priceChange = computed(() => (first.value && props.listing.prijs != null ? props.listing.prijs - first.value.prijs : null))

const specs = computed(() => [
    ['Bouwjaar', props.listing.bouwjaar],
    ['Kilometerstand', km(props.listing.kilometerstand)],
    ['Vermogen', props.listing.vermogen_kw ? `${props.listing.pk} pk (${props.listing.vermogen_kw} kW)` : '–'],
    ['Motorinhoud', props.listing.motorinhoud ? `${num(props.listing.motorinhoud)} cc` : '–'],
    ['Brandstof', props.listing.brandstof],
    ['Transmissie', props.listing.transmissie],
    ['Carrosserie', props.listing.carrosserie],
    ['Kleur', kleurLabel(props.listing.kleur)],
    ['Deuren', props.listing.deuren],
    ['Aanbieder', [props.listing.aanbieder, props.listing.plaats, props.listing.provincie].filter(Boolean).join(', ')],
    ['Soort aanbieder', sellerLabel[props.listing.aanbieder_type]],
    ['Garantie', props.listing.kenmerken_op ? garantieLabel[props.listing.garantie] ?? 'Geen' : null],
    ['Btw', props.listing.btw_verrekenbaar == null ? null : props.listing.btw_verrekenbaar ? 'Verrekenbaar' : 'Marge'],
    ['Importeursportaal', props.listing.importeur_portaal],
    ['Uitvoeringslijn', props.listing.uitvoeringslijn ?? 'niet herkend'],
    ['Opties uit de titel', props.listing.uitrusting_opties.map((o) => optieLabel[o] ?? o).join(', ') || 'geen'],
    ['Online sinds', dateTime(props.listing.datum_geplaatst)],
    ['Advertentie-ID', props.listing.advertentie_id],
])
</script>

<template>
    <Head :title="listing.title" />
    <AppLayout>
        <div class="grid gap-6 lg:grid-cols-[2fr_3fr]">
            <CarPhoto :src="listing.foto" :alt="listing.title" class="aspect-[4/3] w-full" />

            <div>
                <p class="text-sm text-stone-500">{{ listing.merk }} {{ listing.model }}</p>
                <h1 class="text-xl font-semibold tracking-tight">{{ listing.uitvoering }}</h1>

                <div class="mt-3 flex flex-wrap items-baseline gap-x-4">
                    <p class="tabular text-3xl font-semibold">{{ euro(listing.prijs) }}</p>
                    <p v-if="priceChange" class="tabular text-sm" :class="priceChange < 0 ? 'text-emerald-700' : 'text-red-700'">
                        {{ signed(priceChange, '€ ') }} sinds {{ date(first.date) }}
                    </p>
                </div>

                <p class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                    <span class="rounded bg-teal-50 px-2 py-0.5 text-teal-800" :title="`Comfortniveau ${listing.comfort_niveau} van 3`">{{ comfortDots(listing.comfort_niveau) }} {{ comfortLabel[listing.comfort_niveau] }}</span>
                    <span v-if="listing.sport" class="rounded bg-red-50 px-2 py-0.5 font-medium text-red-800">sport</span>
                </p>

                <p class="mt-2 text-sm">
                    <span v-if="!sources.length" class="rounded bg-stone-200 px-2 py-0.5 text-stone-700" title="Valt niet meer binnen een zoekopdracht; de historie tot dan is bewaard.">Niet meer gevolgd</span>
                    <span v-else-if="listing.gone_at" class="rounded bg-red-100 px-2 py-0.5 text-red-800">Verdwenen op {{ date(listing.gone_at) }}</span>
                    <span v-else class="rounded bg-emerald-100 px-2 py-0.5 text-emerald-800">Online</span>
                    <span class="ml-2 text-stone-500">gezien {{ date(listing.first_seen) }} – {{ date(listing.last_seen) }} ({{ history.length }} dagen)</span>
                </p>

                <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <template v-for="[k, v] in specs" :key="k">
                        <dt class="text-stone-500">{{ k }}</dt>
                        <dd class="tabular" :title="k === 'Soort aanbieder' && listing.aanbieder_type === 'merkdealer' ? merkdealerUitleg : null">{{ v || '–' }}</dd>
                    </template>
                    <template v-if="listing.dealer_slug">
                        <dt class="text-stone-500">Dealer op Gaspedaal</dt>
                        <dd><a :href="`https://www.gaspedaal.nl/autobedrijven/${listing.dealer_slug}/aanbod`" target="_blank" rel="noopener noreferrer" class="text-teal-700 hover:underline">{{ listing.dealer_slug }} ↗</a></dd>
                    </template>
                </dl>
                <p v-if="listing.kenmerken_op" class="mt-1 text-xs text-stone-400">Garantie, btw en soort aanbieder volgens Gaspedaal's filters op {{ date(listing.kenmerken_op) }}.</p>

                <div v-if="portalen.length" class="mt-4 flex flex-wrap gap-2 text-sm">
                    <template v-for="p in portalen" :key="p.naam + p.url">
                        <a v-if="p.url" :href="p.url" target="_blank" rel="noopener noreferrer"
                           class="rounded-md border border-stone-300 bg-white px-3 py-1 hover:bg-stone-50">
                            {{ p.naam }} ↗
                        </a>
                        <span v-else class="rounded-md border border-dashed border-stone-300 px-3 py-1 text-stone-400" title="Geen link bekend">{{ p.naam }}</span>
                    </template>
                </div>

                <p v-if="sources.length" class="mt-4 text-sm text-stone-500">
                    In bron:
                    <template v-for="(s, i) in sources" :key="s.id">
                        {{ i ? ', ' : '' }}<Link :href="`/sources/${s.id}`" class="text-teal-700 hover:underline">{{ s.name }}</Link><span v-if="s.gone_at" class="text-red-700"> (weg {{ date(s.gone_at) }})</span>
                    </template>
                </p>
            </div>
        </div>


        <section v-if="score" class="mt-8 rounded-lg border border-stone-200 bg-white p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold">Waarom dit cijfer</h2>
                <p class="text-xs text-stone-500">Berekend op {{ date(score.date) }} · <Link href="/wensen" class="text-teal-700 hover:underline">mijn wensen</Link></p>
            </div>

            <div v-if="!score.door_eisen" class="mt-3 rounded-md border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700">
                Komt niet door je filters: {{ score.afgewezen.join(', ') }}. Hieronder het cijfer zoals het zou zijn.
            </div>

            <div class="mt-4 flex items-center gap-4">
                <Cijfer :value="score.cijfer" size="lg" />
                <p class="text-sm text-stone-600">
                    <template v-if="score.rang"><span class="font-medium text-stone-900">#{{ score.rang }}</span> bij Voor jou. </template>
                    Het gewogen gemiddelde van de vakken hieronder; een vak met een ? telt niet mee.
                </p>
            </div>

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="text-sm font-semibold">Cijfer per vak</h3>
                    <table v-if="score.vakken.length" class="tabular mt-2 w-full text-sm">
                        <thead class="text-left text-xs text-stone-500"><tr><th class="py-1 font-medium">Vak</th><th class="py-1 font-medium">Deze auto</th><th class="py-1 text-right font-medium">Cijfer</th><th class="py-1 text-right font-medium" title="Hoeveel dit vak meetelt in het cijfer van deze auto">Weegt</th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            <tr v-for="v in score.vakken" :key="v.vak" :class="{ 'text-stone-400': !v.belang }">
                                <td class="py-1.5">{{ vakLabel[v.vak] }}</td>
                                <td class="py-1.5 pr-2">{{ v.waarde ?? '–' }}</td>
                                <td class="py-1.5 text-right whitespace-nowrap" :title="v.cijfer == null ? vakOnbekend[v.vak] : null">
                                    <span class="inline-block h-1.5 w-16 overflow-hidden rounded-full bg-stone-100 align-middle"><span class="block h-full" :class="v.cijfer >= 5.5 ? 'bg-teal-600' : 'bg-red-500'" :style="{ width: `${v.cijfer == null ? 0 : v.cijfer * 10}%` }"></span></span>
                                    <span class="ml-2 inline-block w-8 font-medium" :class="v.belang ? cijferTextClass(v.cijfer) : ''">{{ cijferText(v.cijfer) }}</span>
                                </td>
                                <td class="py-1.5 text-right whitespace-nowrap" :title="belangLabel[v.belang]">{{ v.aandeel == null ? (v.belang ? '–' : 'niet') : `${v.aandeel}%` }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="mt-2 text-sm text-stone-500">Nog geen cijfer berekend voor deze auto.</p>
                </div>

                <div>
                    <h3 class="text-sm font-semibold">De deal</h3>
                    <template v-if="score.deal?.verwachte_prijs">
                        <dl class="tabular mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                            <dt class="text-stone-500">Verwachte prijs</dt><dd>{{ euro(score.deal.verwachte_prijs) }}</dd>
                            <dt class="text-stone-500">Vraagprijs</dt><dd>{{ euro(listing.prijs) }}</dd>
                            <dt class="text-stone-500">Verschil</dt>
                            <dd :class="score.deal.check_dit ? 'text-amber-700' : score.deal.deal_eur >= 0 ? 'text-emerald-700' : 'text-red-700'">
                                {{ score.deal.deal_eur >= 0 ? euro(score.deal.deal_eur) + ' onder' : euro(-score.deal.deal_eur) + ' boven' }} de markt ({{ score.deal.deal_pct > 0 ? '+' : '' }}{{ score.deal.deal_pct.toFixed(1) }}%)
                            </dd>
                            <dt class="text-stone-500">Vergelijkbaar</dt><dd>{{ score.deal.vergelijkbaar_n }} auto's, {{ verruimingLabel[score.deal.verruiming] }}</dd>
                            <dt class="text-stone-500">Correctie</dt><dd>factoren van {{ { model: 'dit model', merk: 'het merk', alle: 'alle auto\'s' }[score.deal.factor_scope] }}</dd>
                        </dl>
                        <p v-if="score.deal.check_dit" class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            <strong>Check dit:</strong> meer dan 15% onder de verwachting. Mogelijk schade, import of een fout in de advertentie; krijgt daarom geen dealcijfer.
                        </p>
                    </template>
                    <p v-else-if="score.deal?.onbetrouwbaar" class="mt-2 text-sm text-stone-500">Onvoldoende betrouwbaar: na correctie voor leeftijd en km valt de verwachte prijs buiten 30–300% van wat de {{ score.deal.vergelijkbaar_n }} vergelijkbare auto's vragen. Geen dealcijfer; de deal telt niet mee.</p>
                    <p v-else class="mt-2 text-sm text-stone-500">Onvoldoende vergelijkingsmateriaal: {{ score.deal?.vergelijkbaar_n ?? 0 }} vergelijkbare auto's, ook na verruimen (minimaal 3 nodig). Geen dealcijfer; de deal telt niet mee.</p>
                    <p v-if="score.online_sinds" class="mt-3 text-xs text-stone-500">
                        Online sinds {{ date(score.online_sinds) }}{{ score.online_sinds_bron === 'first_seen' ? ' (eigen eerste waarneming; Gaspedaal\'s datum was onwaarschijnlijk)' : '' }}.
                    </p>
                </div>
            </div>

            <div v-if="score.vergelijkbaar.length" class="mt-6">
                <button class="text-sm text-teal-700 hover:underline" @click="showComparables = !showComparables">
                    {{ showComparables ? 'Verberg' : 'Toon' }} de {{ score.vergelijkbaar.length }} vergelijkbare auto's
                </button>
                <div v-if="showComparables" class="mt-2 overflow-x-auto rounded-md border border-stone-200">
                    <table class="tabular w-full text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500">
                            <tr>
                                <th class="px-3 py-2 font-medium">Auto</th>
                                <th class="px-3 py-2 text-right font-medium">Bouwjaar</th>
                                <th class="px-3 py-2 text-right font-medium">Km</th>
                                <th class="px-3 py-2 text-right font-medium">Pk</th>
                                <th class="px-3 py-2 text-right font-medium">Vraagprijs</th>
                                <th class="px-3 py-2 text-right font-medium" title="Naar de km en leeftijd van deze auto">Gecorrigeerd</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            <tr v-for="c in score.vergelijkbaar" :key="c.id">
                                <td class="max-w-xs truncate px-3 py-1.5"><Link :href="`/listings/${c.id}`" class="text-teal-800 hover:underline">{{ c.title ?? `#${c.id}` }}</Link></td>
                                <td class="px-3 py-1.5 text-right">{{ c.bouwjaar }}</td>
                                <td class="px-3 py-1.5 text-right whitespace-nowrap">{{ km(c.kilometerstand) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ c.pk ?? '–' }}</td>
                                <td class="px-3 py-1.5 text-right whitespace-nowrap">{{ euro(c.prijs) }}</td>
                                <td class="px-3 py-1.5 text-right font-medium whitespace-nowrap">{{ euro(c.gecorrigeerd) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <div class="mt-8 grid gap-6 rounded-lg border border-stone-200 bg-white p-4 lg:grid-cols-2">
            <StepChart :points="priceSeries" :format="euro" label="Prijs" />
            <StepChart :points="kmSeries" :format="km" label="Kilometerstand" />
        </div>

        <h2 class="mt-8 mb-3 text-lg font-semibold">Wijzigingen</h2>
        <div class="overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left text-xs text-stone-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">Datum</th>
                        <th class="px-3 py-2 text-right font-medium">Prijs</th>
                        <th class="px-3 py-2 text-right font-medium">Verschil</th>
                        <th class="px-3 py-2 text-right font-medium">Km</th>
                        <th class="px-3 py-2 text-right font-medium">Verschil</th>
                    </tr>
                </thead>
                <tbody class="tabular divide-y divide-stone-100">
                    <tr v-for="c in changes" :key="c.date">
                        <td class="px-3 py-2">{{ date(c.date) }}</td>
                        <td class="px-3 py-2 text-right">{{ euro(c.prijs) }}</td>
                        <td class="px-3 py-2 text-right" :class="c.prijs_delta < 0 ? 'text-emerald-700' : 'text-red-700'">{{ signed(c.prijs_delta, '€ ') }}</td>
                        <td class="px-3 py-2 text-right">{{ km(c.kilometerstand) }}</td>
                        <td class="px-3 py-2 text-right text-stone-500">{{ signed(c.km_delta) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-stone-500">Alleen dagen waarop iets veranderde, plus de eerste en laatste dag.</p>

        <div class="mt-8">
            <button class="text-sm text-teal-700 hover:underline" @click="showRaw = !showRaw">
                {{ showRaw ? 'Verberg' : 'Toon' }} ruwe Gaspedaal-JSON (laatste versie; {{ snapshot_count }} versie(s) bewaard)
            </button>
            <pre v-if="showRaw" class="mt-2 max-h-[32rem] overflow-auto rounded-lg bg-stone-900 p-4 text-xs text-stone-100">{{ JSON.stringify(raw, null, 2) }}</pre>
        </div>
    </AppLayout>
</template>
