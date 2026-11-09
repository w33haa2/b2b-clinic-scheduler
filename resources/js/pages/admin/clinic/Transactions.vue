<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowUpRight } from '@lucide/vue';
import { ref, watch } from 'vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useApiQuery } from '@/composables/useApiQuery';
import { usePagination } from '@/composables/usePagination';
import { useClinic } from '@/composables/useClinic';
import { formatDateTime, formatMoney, patientName } from '@/lib/format';
import { transactions } from '@/routes/admin/clinics';
import { index } from '@/routes/clinic/transactions';
import type { Paginated, Transaction, TransactionType } from '@/types';

const { clinicId } = useClinic();

setLayoutProps({
    breadcrumbs: [
        { title: 'Transactions', href: transactions(clinicId.value) },
    ],
});

const type = ref<TransactionType | 'ALL'>('ALL');
const { page, perPage, query } = usePagination(25, [type]);

const { data, loading } = useApiQuery<Paginated<Transaction>>(() =>
    index.url(clinicId.value, {
        query: {
            type: type.value === 'ALL' ? undefined : type.value,
            ...query.value,
        },
    }),
);
</script>

<template>
    <Head title="Transactions" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Transactions"
            description="Every payment and refund, newest first. Recorded automatically when a payment is marked paid or refunded (on the schedule or the Payments page) and never edited."
        >
            <template #actions>
                <Tabs v-model="type">
                    <TabsList>
                        <TabsTrigger value="ALL">All</TabsTrigger>
                        <TabsTrigger value="INBOUND">Payments</TabsTrigger>
                        <TabsTrigger value="REVERSED">Refunds</TabsTrigger>
                    </TabsList>
                </Tabs>
            </template>
        </PageHeader>

        <Card
            class="animate__animated animate__fadeInUp overflow-hidden py-0"
            style="--animate-duration: 0.5s"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-10" />
                        <TableHead>When</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Patient</TableHead>
                        <TableHead class="hidden sm:table-cell"
                            >Service</TableHead
                        >
                        <TableHead class="hidden md:table-cell"
                            >Recorded by</TableHead
                        >
                        <TableHead class="text-right">Amount</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading && !data">
                        <TableRow v-for="row in 5" :key="row">
                            <TableCell colspan="7"
                                ><Skeleton class="h-6 w-full"
                            /></TableCell>
                        </TableRow>
                    </template>
                    <TableRow
                        v-for="transaction in data?.data ?? []"
                        :key="transaction.id"
                    >
                        <TableCell>
                            <div
                                class="flex size-7 items-center justify-center rounded-full"
                                :class="
                                    transaction.type === 'INBOUND'
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                                        : 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'
                                "
                            >
                                <ArrowDownLeft
                                    v-if="transaction.type === 'INBOUND'"
                                    class="size-4"
                                />
                                <ArrowUpRight v-else class="size-4" />
                            </div>
                        </TableCell>
                        <TableCell class="whitespace-nowrap">{{
                            formatDateTime(transaction.created_at)
                        }}</TableCell>
                        <TableCell
                            ><StatusBadge
                                kind="transaction"
                                :status="transaction.type"
                        /></TableCell>
                        <TableCell class="font-medium">{{
                            patientName(transaction.patient)
                        }}</TableCell>
                        <TableCell class="hidden sm:table-cell">
                            {{ transaction.session_line_item?.line_item?.name }}
                            <span class="text-muted-foreground"
                                >×
                                {{
                                    transaction.session_line_item?.quantity
                                }}</span
                            >
                        </TableCell>
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                        >
                            {{ transaction.recorded_by?.user?.name ?? '—' }}
                        </TableCell>
                        <TableCell
                            class="text-right font-medium tabular-nums"
                            :class="
                                transaction.type === 'INBOUND'
                                    ? 'text-emerald-700 dark:text-emerald-300'
                                    : 'text-rose-700 dark:text-rose-300'
                            "
                        >
                            {{ transaction.type === 'INBOUND' ? '+' : '−'
                            }}{{ formatMoney(transaction.amount) }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState
                v-if="data && data.data.length === 0"
                title="No transactions yet"
                description="They appear here when a payment is marked paid or refunded."
            />
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>
</template>
