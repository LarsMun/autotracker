// The reference categorical palette, in its validated order (light mode). A saved filter keeps
// its slot, so its colour never depends on which others are shown.
export const SLOTS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948']

export const slotColor = (slot) => SLOTS[(slot - 1) % SLOTS.length]
