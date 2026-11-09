import type { Ref, WatchSource } from 'vue';
import { computed, ref, watch } from 'vue';

export type UsePaginationReturn = {
    page: Ref<number>;
    perPage: Ref<number>;
    /** `{ page, per_page }` for the API query. */
    query: Readonly<Ref<{ page: number; per_page: number }>>;
};

/**
 * Page and page size for a paginated API list. Pass the filters that should
 * send the user back to page 1 when they change (search, category, …).
 * Bind to `<DataPagination v-model:page="page" v-model:per-page="perPage" />`.
 */
export function usePagination(
    defaultPerPage = 25,
    resetOn: WatchSource[] = [],
): UsePaginationReturn {
    const page = ref(1);
    const perPage = ref(defaultPerPage);

    if (resetOn.length) {
        watch(resetOn, () => (page.value = 1), { deep: true });
    }

    return {
        page,
        perPage,
        query: computed(() => ({ page: page.value, per_page: perPage.value })),
    };
}
