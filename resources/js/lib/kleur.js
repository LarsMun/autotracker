// Gaspedaal's colours ("ZWART") as a word and a swatch; an unknown one shows its own name.
const swatches = {
    ZWART: '#1c1917',
    GRIJS: '#78716c',
    ANTRACIET: '#3f3f46',
    ZILVER: '#c4c8cc',
    WIT: '#ffffff',
    BLAUW: '#1d4ed8',
    GROEN: '#15803d',
    ROOD: '#b91c1c',
    BORDEAUX: '#7f1d1d',
    BRUIN: '#78350f',
    BEIGE: '#d6c7a1',
    GOUD: '#b8860b',
    ORANJE: '#ea580c',
    GEEL: '#eab308',
    PAARS: '#7e22ce',
    ROZE: '#ec4899',
}

export const kleurLabel = (k) => (k ? k.charAt(0) + k.slice(1).toLowerCase() : null)
export const kleurSwatch = (k) => swatches[k] ?? '#a8a29e'
