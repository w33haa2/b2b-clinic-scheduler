<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { Archive, Pencil, Plus, Search } from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import LineItemFormDialog from '@/components/clinic/LineItemFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useApiQuery } from '@/composables/useApiQuery';
import { usePagination } from '@/composables/usePagination';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import { services } from '@/routes/admin/clinics';
import { destroy, index } from '@/routes/clinic/line-items';
import type { LineItem, Paginated } from '@/types';

const { clinicId, can } = useClinic();

setLayoutProps({
    breadcrumbs: [
        { title: 'Services & pricing', href: services(clinicId.value) },
    ],
});

const search = ref('');
const debouncedSearch = refDebounced(search, 300);
const { page, perPage, query } = usePagination(25, [debouncedSearch]);

const { data, loading, refresh } = useApiQuery<Paginated<LineItem>>(() =>
    index.url(clinicId.value, {
        query: { search: debouncedSearch.value || undefined, ...query.value },
    }),
);

const formOpen = ref(false);
const editing = ref<LineItem | null>(null);
const archiving = ref<LineItem | null>(null);
const archiveOpen = ref(false);
const archiveProcessing = ref(false);

function openForm(lineItem: LineItem | null): void {
    editing.value = lineItem;
    formOpen.value = true;
}

async function archive(): Promise<void> {
    if (!archiving.value) {
        return;
    }

    archiveProcessing.value = true;

    try {
        await api.delete(
            destroy.url({
                clinic: clinicId.value,
                lineItem: archiving.value.id,
            }),
        );
        toast.success(`${archiving.value.name} archived.`);
        archiveOpen.value = false;
        void refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not archive the service.',
        );
    } finally {
        archiveProcessing.value = false;
    }
}
</script>

<template>
    <Head title="Services & pricing" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Services & pricing"
            description="The catalog your staff bill patients from."
        >
            <template #actions>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        placeholder="Search code or name"
                        class="w-56 pl-8"
                    />
                </div>
                <Button
                    v-if="can('clinic.line-items.store')"
                    @click="openForm(null)"
                >
                    <Plus />
                    New service
                </Button>
            </template>
        </PageHeader>

        <Card
            class="animate__animated animate__fadeInUp overflow-hidden py-0"
            style="--animate-duration: 0.5s"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-40">Code</TableHead>
                        <TableHead>Service</TableHead>
                        <TableHead class="text-right">Price</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Updated</TableHead
                        >
                        <TableHead class="w-24" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading && !data">
                        <TableRow v-for="row in 4" :key="row">
                            <TableCell colspan="5"
                                ><Skeleton class="h-6 w-full"
                            /></TableCell>
                        </TableRow>
                    </template>
                    <TableRow
                        v-for="item in data?.data ?? []"
                        :key="item.id"
                        class="group"
                    >
                        <TableCell
                            ><Badge variant="secondary">{{
                                item.item_code
                            }}</Badge></TableCell
                        >
                        <TableCell class="font-medium">{{
                            item.name
                        }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{
                            formatMoney(item.price)
                        }}</TableCell>
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                            >{{ formatDate(item.updated_at) }}</TableCell
                        >
                        <TableCell>
                            <div
                                class="flex justify-end gap-1 opacity-70 transition-opacity group-hover:opacity-100"
                            >
                                <Button
                                    v-if="can('clinic.line-items.update')"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Edit service"
                                    @click="openForm(item)"
                                >
                                    <Pencil />
                                </Button>
                                <Button
                                    v-if="can('clinic.line-items.destroy')"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Archive service"
                                    @click="
                                        archiving = item;
                                        archiveOpen = true;
                                    "
                                >
                                    <Archive />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState
                v-if="data && data.data.length === 0"
                title="No services yet"
                description="Add what you charge for, such as consultations and therapy sessions."
            />
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>

    <LineItemFormDialog
        v-model:open="formOpen"
        :line-item="editing"
        @saved="
            (item) => {
                toast.success(`${item.name} saved.`);
                refresh();
            }
        "
    />
    <ConfirmDialog
        v-model:open="archiveOpen"
        title="Archive this service?"
        :description="`${archiving?.name} can no longer be billed. Past bills keep it.`"
        confirm-label="Archive"
        :processing="archiveProcessing"
        @confirm="archive"
    />
</template>
