const nf = new Intl.NumberFormat('nl-NL')

export const euro = (v) => (v == null ? '–' : `€ ${nf.format(v)}`)
export const km = (v) => (v == null ? '–' : `${nf.format(v)} km`)
export const num = (v) => (v == null ? '–' : nf.format(v))

export const signed = (v, unit = '') => {
    if (v == null || v === 0) return ''
    return `${v > 0 ? '+' : '−'}${unit}${nf.format(Math.abs(v))}`
}

export const date = (v) =>
    v ? new Date(v.length === 10 ? `${v}T12:00:00` : v).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' }) : '–'

export const dateTime = (v) =>
    v ? new Date(v).toLocaleString('nl-NL', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '–'

export const duration = (s) => (s == null ? '' : s < 60 ? `${s} s` : `${Math.floor(s / 60)} min ${s % 60} s`)

export const statusLabel = {
    running: 'Bezig',
    success: 'Geslaagd',
    incomplete: 'Onvolledig',
    failed: 'Mislukt',
}
