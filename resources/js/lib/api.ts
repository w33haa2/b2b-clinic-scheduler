import { http } from '@inertiajs/vue3';
import type { Method } from '@inertiajs/core';
import type { Paginated } from '@/types';

/**
 * A failed API call. `errors` holds Laravel's validation errors on a 422.
 */
export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }

    /** The first validation error, or the general message. */
    get firstMessage(): string {
        return Object.values(this.errors)[0]?.[0] ?? this.message;
    }
}

/**
 * Call the JSON API (routes/api.php) with the browser session. Inertia's
 * HTTP client sends the XSRF token, so Sanctum authenticates the request
 * like any other page request. Use `useHttp` for forms that show field
 * errors; use this for reads and one-off actions.
 */
export async function apiRequest<T>(
    method: Method,
    url: string,
    data?: unknown,
): Promise<T> {
    try {
        const response = await http.getClient().request({
            method,
            url,
            data,
            headers: { Accept: 'application/json' },
        });

        return (response.data ? JSON.parse(response.data) : null) as T;
    } catch (error) {
        const response = (
            error as { response?: { status: number; data: string } }
        ).response;

        if (!response) {
            throw new ApiError(
                'Network error. Check your connection and try again.',
                0,
            );
        }

        let body: { message?: string; errors?: Record<string, string[]> } = {};

        try {
            body = JSON.parse(response.data);
        } catch {
            // Non-JSON error page: fall back to the status text below.
        }

        throw new ApiError(
            body.message ?? `Request failed (${response.status}).`,
            response.status,
            body.errors,
        );
    }
}

export const api = {
    get: <T>(url: string) => apiRequest<T>('get', url),
    post: <T>(url: string, data?: unknown) =>
        apiRequest<T>('post', url, data ?? {}),
    put: <T>(url: string, data?: unknown) =>
        apiRequest<T>('put', url, data ?? {}),
    patch: <T>(url: string, data?: unknown) =>
        apiRequest<T>('patch', url, data ?? {}),
    delete: <T = null>(url: string) => apiRequest<T>('delete', url),
};

/**
 * Load every page of a paginated list endpoint (lists cap `per_page` at 100).
 * `pageUrl` builds the URL for a given page number.
 */
export async function fetchAllPages<T>(
    pageUrl: (page: number) => string,
): Promise<T[]> {
    const first = await api.get<Paginated<T>>(pageUrl(1));
    const rest = await Promise.all(
        Array.from({ length: first.meta.last_page - 1 }, (_, i) =>
            api.get<Paginated<T>>(pageUrl(i + 2)),
        ),
    );

    return [first, ...rest].flatMap((page) => page.data);
}
