// The vakken of the rapportcijfer, in the order the pages show them.
export const vakken = ['deal', 'km', 'bouwjaar', 'luxe', 'vermogen']

export const vakLabel = {
    deal: 'Deal',
    km: 'Kilometers',
    bouwjaar: 'Bouwjaar',
    luxe: 'Luxe',
    vermogen: 'Vermogen',
}

// Short, for the row of grades in a list.
export const vakKort = { deal: 'Deal', km: 'Km', bouwjaar: 'Jaar', luxe: 'Luxe', vermogen: 'Pk' }

// Why a vak has no grade for a car.
export const vakOnbekend = {
    deal: "Te weinig vergelijkbare auto's, of verdacht goedkoop (Check dit): telt niet mee.",
    km: 'Kilometerstand onbekend: telt niet mee.',
    bouwjaar: 'Bouwjaar onbekend: telt niet mee.',
    luxe: 'Uitvoeringslijn niet herkend: telt niet mee.',
    vermogen: 'Vermogen onbekend: telt niet mee.',
}

// Weight per importance button.
export const belangLabel = { 0: 'Niet', 1: 'Beetje', 3: 'Belangrijk', 5: 'Heel belangrijk' }

export const sportLabel = { maakt_niet_uit: 'Maakt niet uit', ja: 'Alleen sportief', nee: 'Niet sportief' }

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

const nl = new Intl.NumberFormat('nl-NL', { minimumFractionDigits: 1, maximumFractionDigits: 1 })

/** 7.8 → "7,8"; no grade → "?" */
export const cijferText = (c) => (c == null ? '?' : nl.format(c))

// A rapportcijfer reads like a school report: below 5,5 is onvoldoende.
export const cijferBadgeClass = (c) =>
    c == null ? 'bg-stone-100 text-stone-400'
        : c >= 8 ? 'bg-emerald-700 text-white'
            : c >= 7 ? 'bg-emerald-100 text-emerald-900'
                : c >= 5.5 ? 'bg-stone-100 text-stone-800'
                    : 'bg-red-100 text-red-800'

export const cijferTextClass = (c) => (c == null ? 'text-stone-400' : c >= 8 ? 'text-emerald-700' : c >= 5.5 ? 'text-stone-800' : 'text-red-700')
