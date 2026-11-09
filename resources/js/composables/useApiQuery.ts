import type { Ref } from 'vue';
import { onMounted, ref, shallowRef, watch } from 'vue';
import { api, ApiError } from '@/lib/api';

export type UseApiQueryReturn<T> = {
    data: Ref<T | null>;
    loading: Ref<boolean>;
    error: Ref<ApiError | null>;
    refresh: () => Promise<void>;
};

/**
 * Load JSON from the API and reload whenever the URL changes. Loading starts
 * on mount, so nothing is fetched during server-side rendering; pages show
 * skeletons until the data arrives.
 */
export function useApiQuery<T>(url: () => string | null): UseApiQueryReturn<T> {
    const data = shallowRef<T | null>(null) as Ref<T | null>;
    const loading = ref(true);
    const error = shallowRef<ApiError | null>(null);
    let latestRequest = 0;

    async function refresh(): Promise<void> {
        const target = url();

        if (target === null) {
            return;
        }

        const request = ++latestRequest;
        loading.value = true;

        try {
            const result = await api.get<T>(target);

            if (request === latestRequest) {
                data.value = result;
                error.value = null;
            }
        } catch (caught) {
            if (request === latestRequest) {
                error.value =
                    caught instanceof ApiError
                        ? caught
                        : new ApiError(String(caught), 0);
            }
        } finally {
            if (request === latestRequest) {
                loading.value = false;
            }
        }
    }

    onMounted(() => {
        void refresh();
        watch(url, () => void refresh());
    });

    return { data, loading, error, refresh };
}
