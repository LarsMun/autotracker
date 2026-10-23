import { euro, km, num } from './format'
import { kleurLabel } from './kleur'

// How a filter reads in a sentence, for the saved-filter cards.
export function describe(filters, options) {
    const f = filters ?? {}
    const parts = []
    const range = (min, max, fmt, unit = '') => {
        if (min != null && max != null) return `${fmt(min)}–${fmt(max)}${unit}`
        if (min != null) return `vanaf ${fmt(min)}${unit}`
        if (max != null) return `tot ${fmt(max)}${unit}`
        return null
    }

    if (f.merk || f.model) parts.push([f.merk, f.model].filter(Boolean).join(' '))
    if (f.bron) parts.push(`bron: ${options.bronnen.find((b) => b.id === Number(f.bron))?.name ?? f.bron}`)
    const year = range(f.bouwjaar_min, f.bouwjaar_max, String)
    if (year) parts.push(`bouwjaar ${year}`)
    const pk = range(f.pk_min, f.pk_max, num, ' pk')
    if (pk) parts.push(pk)
    const kms = range(f.km_min, f.km_max, km)
    if (kms) parts.push(kms)
    const price = range(f.prijs_min, f.prijs_max, euro)
    if (price) parts.push(price)
    if (f.carrosserie) parts.push(f.carrosserie.toLowerCase())
    if (f.transmissie) parts.push(f.transmissie.toLowerCase())
    if (f.kleur) parts.push(String(f.kleur).split(',').map((k) => kleurLabel(k).toLowerCase()).join('/'))
    if (f.uitvoering) parts.push(`uitvoering bevat "${f.uitvoering}"`)
    if (f.aanbieder) parts.push(f.aanbieder)
    if (f.garantie) parts.push({ merkgarantie: 'met merkgarantie', bovag: 'met BOVAG-garantie', geen: 'zonder garantie' }[f.garantie])
    if (f.comfort) parts.push(`comfort ${String(f.comfort).split(',').join('/')}`)
    if (f.sport) parts.push(f.sport === 'ja' ? 'sport' : 'niet sport')
    if (f.btw) parts.push(f.btw === 'verrekenbaar' ? 'btw verrekenbaar' : 'marge')

    return parts.length ? parts.join(' · ') : 'alle auto\'s'
}

// The same selection, regardless of key order or empty fields.
export function sameFilters(a, b) {
    const clean = (o) => JSON.stringify(Object.entries(o ?? {}).filter(([, v]) => v !== '' && v != null).map(([k, v]) => [k, String(v)]).sort())
    return clean(a) === clean(b)
}
