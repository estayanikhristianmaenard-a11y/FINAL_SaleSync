// Same-origin client; no secrets or prices supplied by the frontend are trusted.
const endpoint = new URL('../../api/index.php', import.meta.url);
let csrfToken;

export class ApiError extends Error {
    constructor(message, status, fields = {}) {
        super(message);
        this.status = status;
        this.fields = fields;
    }
}

export async function api(path, { method = 'GET', body, idempotencyKey, query = {} } = {}) {
    if (method !== 'GET' && !csrfToken) await api('/session');
    const url = new URL(endpoint);
    url.searchParams.set('route', path);
    for (const [key, value] of Object.entries(query)) {
        if (value !== undefined && value !== '') url.searchParams.set(key, String(value));
    }
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') headers['X-CSRF-Token'] = csrfToken;
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;
    const response = await fetch(url, {
        method, credentials: 'same-origin', headers,
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const result = await response.json();
    if (!response.ok) {
        if (response.status === 419) csrfToken = undefined;
        throw new ApiError(result.error?.message || 'Request failed.', response.status, result.error?.fields);
    }
    if (result.data?.csrf_token) csrfToken = result.data.csrf_token;
    return result.data;
}

export const formatMoney = centavos => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(centavos / 100);
