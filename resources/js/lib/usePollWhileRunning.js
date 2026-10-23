import { router } from '@inertiajs/vue3'
import { onMounted, onUnmounted, watchEffect } from 'vue'

/**
 * Reloads the page props every few seconds while isRunning() is true, so a run shows progress.
 */
export function usePollWhileRunning(isRunning, only) {
    let timer = null

    const stop = () => {
        clearInterval(timer)
        timer = null
    }

    onMounted(() =>
        watchEffect(() => {
            if (isRunning() && !timer) {
                timer = setInterval(() => router.reload({ only }), 5000)
            } else if (!isRunning() && timer) {
                stop()
            }
        }),
    )

    onUnmounted(stop)
}
