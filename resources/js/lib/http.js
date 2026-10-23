// A JSON POST with Laravel's CSRF cookie, for calls that are not page visits.
export async function postJson(url, body) {
    const token = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '')
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
        body: JSON.stringify(body),
    })
    const data = await response.json().catch(() => ({}))

    if (!response.ok) {
        const message = data.errors ? Object.values(data.errors).flat().join(' ') : data.message
        throw new Error(message || `HTTP ${response.status}`)
    }

    return data
}
