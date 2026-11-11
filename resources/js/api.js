/**
 * Cliente HTTP del visor. Mantiene el formato de errores del paquete.
 */

export class ApiError extends Error {
    constructor(message, options = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = options.status ?? 0;
        this.errors = options.errors ?? [];
        this.data = options.data ?? null;
    }
}

export class Api {
    constructor(root) {
        this.dataUrl = root.dataset.dataUrl;
        this.saveUrl = root.dataset.saveUrl;
        this.importUrl = root.dataset.importUrl;
        this.contextUrl = root.dataset.contextUrl;
        this.mermaidUrl = root.dataset.mermaidUrl;
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    }

    async load(options = {}) {
        return this.request(this.dataUrl, {
            method: 'GET',
            signal: options.signal,
        });
    }

    async save(document, hash, force = false) {
        return this.request(this.saveUrl, {
            method: 'PUT',
            body: JSON.stringify({
                document,
                hash,
                force,
            }),
        });
    }

    async importMigrations(options = {}) {
        return this.request(this.importUrl, {
            method: 'POST',
            body: JSON.stringify(options),
        });
    }

    async contextText() {
        const response = await fetch(this.contextUrl, {
            headers: {
                Accept: 'text/markdown',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new ApiError('The AI context could not be generated.', {
                status: response.status,
            });
        }

        return response.text();
    }

    async request(url, options = {}) {
        const headers = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers ?? {}),
        };

        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        if (this.csrfToken !== '') {
            headers['X-CSRF-TOKEN'] = this.csrfToken;
        }

        let response;

        try {
            response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                ...options,
                headers,
            });
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                throw error;
            }

            throw new ApiError('The server could not be reached.');
        }

        const text = await response.text();
        let payload = null;

        if (text !== '') {
            try {
                payload = JSON.parse(text);
            } catch {
                payload = null;
            }
        }

        if (!response.ok || payload?.status === 'error') {
            throw new ApiError(
                payload?.message ?? `The server returned HTTP ${response.status}.`,
                {
                    status: response.status,
                    errors: Array.isArray(payload?.errors) ? payload.errors : [],
                    data: payload?.data ?? null,
                },
            );
        }

        if (payload === null || payload.status !== 'success') {
            throw new ApiError('The server returned an unexpected response.', {
                status: response.status,
            });
        }

        return payload.data;
    }
}
