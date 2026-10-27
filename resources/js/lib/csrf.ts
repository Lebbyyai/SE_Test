/** Read the current session cookie after an Inertia login or session renewal. */
export function csrfHeaders(json = false): Record<string, string> {
    const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (cookie) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.substring('XSRF-TOKEN='.length));
    else headers['X-CSRF-TOKEN'] = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    if (json) headers['Content-Type'] = 'application/json';
    return headers;
}
