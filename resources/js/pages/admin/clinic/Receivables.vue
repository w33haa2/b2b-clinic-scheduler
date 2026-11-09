<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ArrowUpRight, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import DatePicker from '@/components/form/DatePicker.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import { useClinic } from '@/composables/useClinic';
import { usePagination } from '@/composables/usePagination';
import { formatDate, formatMoney, patientName } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    payments as paymentsPage,
    payouts as payoutsPage,
    receivables,
} from '@/routes/admin/clinics';
import { index } from '@/routes/clinic/receivables';
import type { ReceivablePage } from '@/types';

/**
 * Money the clinic paid out before it collected it. Every row is a billed
 * item that went into a payout while the patient's payment was still
 * pending. Rows settle themselves, so the page is read-only. Clinic Admin
 * only.
 */
const { clinicId, isAdmin } = useClinic();

setLayoutProps({
    breadcrumbs: [{ title: 'Receivables', href: receivables(clinicId.value) }],
});

type StateFilter = 'UNSETTLED' | 'SETTLED' | 'ALL';

const state = ref<StateFilter>('UNSETTLED');
const memberId = ref('all');
const from = ref('');
const to = ref('');

const { page, perPage, query } = usePagination(25, [state, memberId, from, to]);

const { data, loading } = useApiQuery<ReceivablePage>(() =>
    index.url(clinicId.value, {
        query: {
            state: state.value,
            clinic_user_id:
                memberId.value === 'all' ? undefined : Number(memberId.value),
            from: from.value || undefined,
            to: to.value || undefined,
            ...query.value,
        },
    }),
);

const cards = computed(() => {
    const summary = data.value?.meta.summary;

    return [
        {
            key: 'UNSETTLED' as const,
            label: 'Outstanding',
            hint: 'Paid out, not collected',
            value: summary?.outstanding,
            tone: 'text-rose-700 dark:text-rose-300',
        },
        {
            key: 'SETTLED' as const,
            label: 'Settled',
            hint: 'Collected since',
            value: summary?.settled,
            tone: 'text-emerald-700 dark:text-emerald-300',
        },
        {
            key: 'ALL' as const,
            label: 'Total advanced',
            hint: 'All time',
            value: summary?.advanced,
            tone: 'text-muted-foreground',
        },
    ];
});

const byMember = computed(() => data.value?.meta.by_member ?? []);

/** The biggest outstanding total, so each bar can be drawn against it. */
const largestMemberTotal = computed(() =>
    byMember.value.reduce(
        (largest, row) => Math.max(largest, Number(row.total)),
        0,
    ),
);

function barWidth(total: string): string {
    return largestMemberTotal.value > 0
        ? `${(Number(total) / largestMemberTotal.value) * 100}%`
        : '0%';
}

/**
 * `by_member` only covers what is outstanding under the current filters, so
 * remember everyone seen: the picked member has to survive its own filter.
 */
const knownMembers = ref(new Map<number, string>());

watch(byMember, (rows) => {
    rows.forEach((row) => knownMembers.value.set(row.clinic_user_id, row.name));
});

const staffOptions = computed(() =>
    [...knownMembers.value.entries()]
        .map(([id, name]) => ({ id, name }))
        .sort((a, b) => a.name.localeCompare(b.name)),
);

const isFiltered = computed(
    () => memberId.value !== 'all' || from.value !== '' || to.value !== '',
);

function resetFilters(): void {
    memberId.value = 'all';
    from.value = '';
    to.value = '';
}

const emptyState = computed(() => {
    if (state.value === 'SETTLED') {
        return {
            title: 'Nothing settled here',
            description:
                'Advanced amounts move here on their own once the patient’s payment is recorded.',
        };
    }

    if (state.value === 'ALL') {
        return {
            title: 'Nothing advanced yet',
            description:
                'Nothing has been paid out ahead of a patient payment.',
        };
    }

    return {
        title: 'Nothing outstanding',
        description: 'Nothing has been paid out ahead of a patient payment.',
    };
});
</script>

<template>
    <Head title="Receivables" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Receivables"
            description="Money the clinic has handed out but not collected. Each row is a billed item that went into a payout while the patient's payment was still pending, so the staff member was paid and the clinic is still owed. A row settles itself the moment that payment is recorded, and opens again if the payment is refunded — there is nothing to settle by hand."
        />

        <!-- Totals for the current filters; click to change the state. -->
        <div
            class="animate__animated animate__fadeInUp grid gap-3 sm:grid-cols-3"
            style="--animate-duration: 0.4s"
        >
            <button
                v-for="card in cards"
                :key="card.key"
                type="button"
                :aria-pressed="state === card.key"
                :class="
                    cn(
                        'rounded-xl border bg-card p-4 text-left shadow-sm transition-colors hover:bg-accent/50',
                        state === card.key && 'ring-2 ring-teal-600',
                    )
                "
                @click="state = state === card.key ? 'ALL' : card.key"
            >
                <div class="text-sm text-muted-foreground">
                    {{ card.label }}
                </div>
                <div
                    :class="
                        cn(
                            'mt-1 text-2xl font-semibold tabular-nums',
                            card.tone,
                        )
                    "
                >
                    <Skeleton v-if="!card.value" class="h-8 w-24" />
                    <template v-else>{{
                        formatMoney(card.value.total)
                    }}</template>
                </div>
                <div class="text-xs text-muted-foreground">
                    <template v-if="card.value"
                        >{{ card.value.count }} ·
                    </template>
                    {{ card.hint }}
                </div>
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Tabs v-model="state">
                <TabsList>
                    <TabsTrigger value="UNSETTLED">Outstanding</TabsTrigger>
                    <TabsTrigger value="SETTLED">Settled</TabsTrigger>
                    <TabsTrigger value="ALL">All</TabsTrigger>
                </TabsList>
            </Tabs>

            <Select v-model="memberId">
                <SelectTrigger class="w-56" aria-label="Staff member">
                    <SelectValue placeholder="All staff" />
                </SelectTrigger>
                <SelectContent class="max-h-72">
                    <SelectItem value="all">All staff</SelectItem>
                    <SelectItem
                        v-for="option in staffOptions"
                        :key="option.id"
                        :value="String(option.id)"
                        >{{ option.name }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <!-- Dates match the payout's period, not the session. -->
            <div class="flex items-center gap-2">
                <DatePicker
                    v-model="from"
                    placeholder="Period from"
                    clearable
                    class="w-40"
                />
                <span class="text-muted-foreground">–</span>
                <DatePicker
                    v-model="to"
                    placeholder="Period to"
                    clearable
                    class="w-40"
                />
            </div>

            <Button
                v-if="isFiltered"
                variant="ghost"
                size="sm"
                @click="resetFilters"
            >
                <X />
                Clear filters
            </Button>
        </div>

        <Card
            v-if="byMember.length"
            class="animate__animated animate__fadeInUp gap-4"
            style="--animate-duration: 0.5s"
        >
            <CardHeader>
                <CardTitle>By staff member</CardTitle>
                <CardDescription
                    >Who the outstanding money went to. Click a name to filter
                    the list.</CardDescription
                >
            </CardHeader>
            <ul class="grid gap-1 px-6">
                <li v-for="row in byMember" :key="row.clinic_user_id">
                    <button
                        type="button"
                        :aria-pressed="memberId === String(row.clinic_user_id)"
                        :class="
                            cn(
                                'w-full rounded-lg px-2 py-2 text-left transition-colors hover:bg-accent/50',
                                memberId === String(row.clinic_user_id) &&
                                    'bg-accent',
                            )
                        "
                        @click="
                            memberId =
                                memberId === String(row.clinic_user_id)
                                    ? 'all'
                                    : String(row.clinic_user_id)
                        "
                    >
                        <div class="flex items-baseline gap-3">
                            <span class="truncate text-sm font-medium">{{
                                row.name
                            }}</span>
                            <span class="text-xs text-muted-foreground"
                                >{{ row.count }}
                                {{ row.count === 1 ? 'item' : 'items' }}</span
                            >
                            <span
                                class="ml-auto text-sm font-medium tabular-nums"
                                >{{ formatMoney(row.total) }}</span
                            >
                        </div>
                        <div
                            class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted"
                            aria-hidden="true"
                        >
                            <div
                                class="h-full rounded-full bg-teal-600"
                                :style="{ width: barWidth(row.total) }"
                            />
                        </div>
                    </button>
                </li>
            </ul>
        </Card>

        <Card
            class="animate__animated animate__fadeInUp overflow-hidden py-0"
            style="--animate-duration: 0.5s"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Session</TableHead>
                        <TableHead>Patient</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Service</TableHead
                        >
                        <TableHead class="hidden sm:table-cell"
                            >Payout</TableHead
                        >
                        <TableHead class="text-right">Advanced</TableHead>
                        <TableHead>Payment</TableHead>
                        <TableHead>State</TableHead>
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
                        v-for="row in data?.data ?? []"
                        :key="row.id"
                        :class="
                            row.is_unsettled &&
                            'bg-amber-50/50 dark:bg-amber-500/5'
                        "
                    >
                        <TableCell class="whitespace-nowrap">
                            <div class="font-medium">
                                {{ formatDate(row.session?.starts_at) }}
                            </div>
                            <div
                                v-if="row.session"
                                class="max-w-48 truncate text-xs text-muted-foreground"
                            >
                                {{ row.session.label }}
                            </div>
                        </TableCell>
                        <TableCell>{{
                            patientName(row.patient ?? undefined)
                        }}</TableCell>
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                        >
                            {{ row.service ?? '—' }}
                            <template v-if="(row.quantity ?? 1) > 1"
                                >× {{ row.quantity }}</template
                            >
                        </TableCell>
                        <TableCell class="hidden sm:table-cell">
                            <Link
                                :href="payoutsPage(clinicId)"
                                class="text-muted-foreground tabular-nums underline-offset-4 hover:text-foreground hover:underline"
                                >#{{ row.payout_id
                                }}<ArrowUpRight
                                    class="ml-0.5 inline size-3.5 align-text-top"
                            /></Link>
                        </TableCell>
                        <TableCell
                            class="text-right font-medium tabular-nums"
                            >{{ formatMoney(row.worker_amount) }}</TableCell
                        >
                        <TableCell>
                            <StatusBadge
                                v-if="row.payment_status"
                                kind="payment"
                                :status="row.payment_status"
                            />
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell>
                            <Badge
                                v-if="row.is_unsettled"
                                variant="secondary"
                                class="border-transparent bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"
                                >Outstanding</Badge
                            >
                            <template v-else>
                                <Badge
                                    variant="secondary"
                                    class="border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300"
                                    >Settled</Badge
                                >
                                <div
                                    class="mt-0.5 text-xs text-muted-foreground"
                                >
                                    {{ formatDate(row.settled_at) }}
                                </div>
                            </template>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState
                v-if="data && data.data.length === 0"
                :title="emptyState.title"
                :description="emptyState.description"
            />
        </Card>

        <p v-if="isAdmin" class="-mt-3 text-xs text-muted-foreground">
            There is nothing to do on a row. An outstanding item settles itself
            the moment the patient's payment is recorded on
            <Link
                :href="paymentsPage(clinicId)"
                class="underline-offset-4 hover:text-foreground hover:underline"
                >Payments</Link
            >
            or the schedule, and opens again if that payment is refunded.
        </p>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>
</template>
