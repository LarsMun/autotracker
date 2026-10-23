export const wensLabel = {
    prijs: 'Lage prijs',
    km: 'Kilometerstand',
    bouwjaar: 'Bouwjaar',
    comfort: 'Uitrusting',
    sport: 'Sportief',
    merkdealer: 'Merkdealer / merkgarantie',
    pk: 'Vermogen',
    model: 'Model',
    kleur: 'Kleur',
}

// One plain sentence per wish, for the (i) on "Mijn wensen".
export const wensUitleg = {
    prijs: 'Hoe verder onder je budget, hoe hoger de auto scoort.',
    deal: "Hoe goedkoper de auto is dan vergelijkbare auto's, hoe hoger hij scoort.",
    comfort: 'Een auto met jouw uitrusting of beter scoort volledig; één niveau lager nog een beetje.',
    sport: 'Een auto die bij je keuze past scoort volledig, de rest niet.',
    pk: 'Vanaf je minimum scoort de auto volledig; iets minder vermogen kost punten.',
    merkdealer: 'Een auto bij een merkdealer of met merkgarantie scoort volledig, de rest niet.',
    model: 'Hoe hoger het model in je lijst staat, hoe hoger de auto scoort.',
    kleur: 'Een auto in een van je kleuren scoort volledig. Kies je geen kleur, dan telt kleur niet mee.',
}

// Weight per importance button; "Goede deal" sets the match share instead.
export const belangLabel = { 0: 'Niet', 1: 'Beetje', 3: 'Belangrijk', 5: 'Heel belangrijk' }
export const dealW = { 0: 1, 1: 0.85, 3: 0.7, 5: 0.5 }

export const verruimingLabel = [
    'strikt (bouwjaar ±1, km ±15.000, pk ±15%, comfort ±1)',
    'bouwjaar ±2',
    'bouwjaar ±2, km ±25.000',
    'bouwjaar ±2, km ±25.000, pk ±25%',
    'bouwjaar ±2, km ±25.000, pk ±25%, comfort genegeerd',
]

export const chipClass = {
    good: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    bad: 'bg-red-50 text-red-800 ring-red-200',
    warn: 'bg-amber-100 text-amber-900 ring-amber-300 font-medium',
    info: 'bg-stone-50 text-stone-600 ring-stone-200',
}

export const scoreClass = (s) => (s == null ? 'text-stone-400' : s >= 75 ? 'text-emerald-700' : s >= 50 ? 'text-stone-900' : 'text-red-700')
