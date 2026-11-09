<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import ActivityFilters from '@/components/activity/ActivityFilters.vue';
import ActivityLogList from '@/components/activity/ActivityLogList.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Card } from '@/components/ui/card';
import { useActivityLog } from '@/composables/useActivityLog';
import { useClinic } from '@/composables/useClinic';
import { activity } from '@/routes/admin/clinics';
import { index } from '@/routes/clinic/activity';

const { clinicId } = useClinic();

setLayoutProps({
    breadcrumbs: [{ title: 'Activity log', href: activity(clinicId.value) }],
});

const { filters, page, perPage, data, loading } = useActivityLog((query) =>
    index.url(clinicId.value, { query }),
);
</script>

<template>
    <Head title="Activity log" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Activity log"
            description="Everything that changed in this clinic, who changed it and when. Entries can't be edited or deleted."
        />

        <ActivityFilters
            v-model="filters"
            :categories="data?.meta.filters.categories ?? []"
            :actors="data?.meta.filters.actors"
            hide-support-names
        />

        <Card
            class="animate__animated animate__fadeInUp gap-0 overflow-hidden py-0"
            style="--animate-duration: 0.5s"
        >
            <ActivityLogList
                :logs="data?.data ?? null"
                :loading="loading"
                hide-support-names
            />
            <EmptyState
                v-if="data && data.data.length === 0"
                title="Nothing here yet"
                description="Changes to patients, sessions, billing, invoices and staff will appear here."
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
