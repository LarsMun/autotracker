export const comfortLabel = { 1: 'basic', 2: 'redelijk', 3: 'luxe' }
export const comfortDots = (n) => (n ? '●'.repeat(n) + '○'.repeat(3 - n) : '')
export const optieLabel = {
    pano: 'Panoramadak',
    leer: 'Leer',
    hud: 'Head-up display',
    camera360: '360°-camera',
    acc: 'Adaptieve cruise',
    audio: 'Premium audio',
    comfortstoelen: 'Comfortstoelen',
    trekhaak: 'Trekhaak',
    matrix: 'Matrix/laser-licht',
}

// "1,3" <-> [1, 3]
export const parseLevels = (v) => (v ? String(v).split(',').map(Number).filter((n) => n >= 1 && n <= 3) : [])
export const toggleLevel = (v, n) => {
    const levels = new Set(parseLevels(v))
    levels.has(n) ? levels.delete(n) : levels.add(n)
    return [...levels].sort().join(',')
}
