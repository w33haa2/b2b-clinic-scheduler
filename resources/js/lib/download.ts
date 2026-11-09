import { ApiError } from '@/lib/api';

/**
 * Download a file from the API with the browser session (e.g. an invoice PDF)
 * and save it under the name the server suggests. Throws ApiError on failure,
 * so callers can show the reason in a toast.
 */
export async function downloadFile(
    url: string,
    fallbackName: string,
): Promise<void> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf, application/json' },
    });

    if (!response.ok) {
        let message = `Download failed (${response.status}).`;

        try {
            message =
                ((await response.json()) as { message?: string }).message ??
                message;
        } catch {
            // Not JSON: keep the generic message.
        }

        throw new ApiError(message, response.status);
    }

    const name =
        /filename="([^"]+)"/.exec(
            response.headers.get('Content-Disposition') ?? '',
        )?.[1] ?? fallbackName;
    const objectUrl = URL.createObjectURL(await response.blob());
    const link = Object.assign(document.createElement('a'), {
        href: objectUrl,
        download: name,
    });

    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
}
