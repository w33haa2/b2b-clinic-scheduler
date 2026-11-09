import { refDebounced } from '@vueuse/core';
import type { Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import type { ActivityFilterState } from '@/components/activity/ActivityFilters.vue';
import { useApiQuery } from '@/composables/useApiQuery';
import type { ActivityLogPage } from '@/types';

/**
 * Loads an activity log page for the given filters. `buildUrl` receives the
 * API query (dates converted from local days to UTC instants).
 */
export function useActivityLog(
    buildUrl: (query: Record<string, string | number | undefined>) => string,
) {
    const filters = ref<ActivityFilterState>({
        level: 'all',
        clinic_id: 'all',
        category: 'all',
        user_id: 'all',
        from: '',
        to: '',
        search: '',
    }) as Ref<ActivityFilterState>;
    const page = ref(1);
    const perPage = ref(30);
    const search = refDebounced(
        computed(() => filters.value.search),
        300,
    );

    const startOfDay = (day: string, addDays = 0) => {
        const date = new Date(`${day}T00:00:00`);
        date.setDate(date.getDate() + addDays);

        return date.toISOString();
    };

    const query = computed(() => {
        const value = filters.value;

        return {
            page: page.value,
            per_page: perPage.value,
            level: value.level === 'all' ? undefined : value.level,
            clinic_id:
                value.clinic_id === 'all' || value.level === 'platform'
                    ? undefined
                    : value.clinic_id,
            category: value.category === 'all' ? undefined : value.category,
            user_id: value.user_id === 'all' ? undefined : value.user_id,
            from: value.from ? startOfDay(value.from) : undefined,
            // "To" is inclusive: everything before the start of the next day.
            to: value.to ? startOfDay(value.to, 1) : undefined,
            search: search.value || undefined,
        };
    });

    watch(
        () => ({ ...filters.value, search: search.value }),
        () => (page.value = 1),
        { deep: true },
    );

    const result = useApiQuery<ActivityLogPage>(() => buildUrl(query.value));

    return { filters, page, perPage, ...result };
}
