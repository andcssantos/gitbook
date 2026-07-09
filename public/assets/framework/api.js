export class ApiError extends Error {
    constructor(message, response, payload = null) {
        super(message);
        this.name = 'ApiError';
        this.response = response;
        this.payload = payload;
        this.status = response?.status ?? 0;
    }
}

export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_csrf_token"]')?.value
        || '';
}

export function idempotencyKey() {
    return crypto?.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export async function apiFetch(url, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = new Headers(options.headers || {});

    headers.set('Accept', 'application/json');

    if (options.body && !(options.body instanceof FormData) && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }

    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
        const token = csrfToken();
        if (token) headers.set('X-CSRF-Token', token);
        if (!headers.has('Idempotency-Key')) headers.set('Idempotency-Key', idempotencyKey());
    }

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        method,
        headers,
        body: options.body && !(options.body instanceof FormData) && typeof options.body !== 'string'
            ? JSON.stringify(options.body)
            : options.body,
    });

    const contentType = response.headers.get('content-type') || '';
    const payload = contentType.includes('application/json') ? await response.json() : await response.text();

    if (!response.ok) {
        throw new ApiError(payload?.message || response.statusText, response, payload);
    }

    return payload;
}
