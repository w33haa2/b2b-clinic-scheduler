<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import ActivityFilters from '@/components/activity/ActivityFilters.vue';
import ActivityLogList from '@/components/activity/ActivityLogList.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Card } from '@/components/ui/card';
import { useActivityLog } from '@/composables/useActivityLog';
import { activity } from '@/routes/admin/internal';
import { index } from '@/routes/internal/activity';

setLayoutProps({ breadcrumbs: [{ title: 'Activity log', href: activity() }] });

const { filters, page, perPage, data, loading } = useActivityLog((query) =>
    index.url({ query }),
);
</script>

<template>
    <Head title="Activity log" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Activity log"
            description="Platform events (sign-ins, accounts, API tokens) and every clinic's changes in one place."
        />

        <ActivityFilters
            v-model="filters"
            :categories="data?.meta.filters.categories ?? []"
            :clinics="data?.meta.filters.clinics"
            show-level
        />

        <Card class="gap-0 overflow-hidden py-0">
            <ActivityLogList
                :logs="data?.data ?? null"
                :loading="loading"
                show-clinic
            />
            <EmptyState
                v-if="data && data.data.length === 0"
                title="No matching activity"
            />
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
            :per-page-options="[30, 50, 100]"
        />
    </div>
</template>
