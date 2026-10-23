import '../css/app.css'
import { createInertiaApp } from '@inertiajs/vue3'

createInertiaApp({
    title: (title) => (title ? `${title} · Auto` : 'Auto'),
    progress: { color: '#0f766e' },
})
