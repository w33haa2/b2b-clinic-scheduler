<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import {
    CalendarCheck,
    CheckCircle2,
    MoreHorizontal,
    Repeat,
    Search,
    Undo2,
} from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import PrepayDialog from '@/components/clinic/PrepayDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { usePagination } from '@/composables/usePagination';
import { api, ApiError } from '@/lib/api';
import {
    formatDateTime,
    formatMoney,
    formatTime,
    patientName,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { payments } from '@/routes/admin/clinics';
import { index } from '@/routes/clinic/payments';
import { update as paymentUpdate } from '@/routes/clinic/sessions/members/payment';
import type { Paginated, Payment, PaymentSummary } from '@/types';

const { clinicId, can } = useClinic();

setLayoutProps({
    breadcrumbs: [{ title: 'Payments', href: payments(clinicId.value) }],
});

type StatusFilter = 'ALL' | 'PENDING' | 'PAST_DUE' | 'DONE' | 'REFUNDED';

const status = ref<StatusFilter>('ALL');
const search = ref('');
const debouncedSearch = refDebounced(search, 300);
const { page, perPage, query } = usePagination(25, [status, debouncedSearch]);

const { data, loading, refresh } = useApiQuery<
    Paginated<Payment> & {
        meta: Paginated<Payment>['meta'] & { summary: PaymentSummary };
    }
>(() =>
    index.url(clinicId.value, {
        query: {
            status: status.value === 'ALL' ? undefined : status.value,
            search: debouncedSearch.value || undefined,
            ...query.value,
        },
    }),
);

const cards = computed(() => {
    const summary = data.value?.meta.summary;

    return [
        {
            key: 'PENDING' as const,
            label: 'Unpaid',
            hint: 'Not paid yet',
            value: summary?.outstanding,
            tone: 'text-amber-700 dark:text-amber-300',
        },
        {
            key: 'PAST_DUE' as const,
            label: 'Past due',
            hint: 'Session over, not paid',
            value: summary?.past_due,
            tone: 'text-rose-700 dark:text-rose-300',
        },
        {
            key: 'DONE' as const,
            label: 'Paid',
            hint: 'Collected',
            value: summary?.paid,
            tone: 'text-emerald-700 dark:text-emerald-300',
        },
        {
            key: 'REFUNDED' as const,
            label: 'Refunded',
            hint: 'Given back',
            value: summary?.refunded,
            tone: 'text-muted-foreground',
        },
    ];
});

// Recording payments and refunds
const prepayOpen = ref(false);
const confirming = ref<{ payment: Payment; to: 'DONE' | 'REFUNDED' } | null>(
    null,
);
const busy = ref(false);

const canRecord = computed(() => can('clinic.sessions.members.payment.update'));

function canPay(payment: Payment): boolean {
    return (
        payment.status === 'PENDING' &&
        payment.session?.status !== 'CANCELLED' &&
        (payment.line_items?.length ?? 0) > 0
    );
}

async function record(): Promise<void> {
    const target = confirming.value;

    if (!target?.payment.session) {
        return;
    }

    busy.value = true;

    try {
        await api.put(
            paymentUpdate.url({
                clinic: clinicId.value,
                clinicSession: target.payment.session.id,
                sessionMember: target.payment.session_member_id,
            }),
            { payment_status: target.to },
        );
        toast.success(
            target.to === 'DONE' ? 'Payment recorded.' : 'Refund recorded.',
        );
        confirming.value = null;
        await refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not record the payment.',
        );
    } finally {
        busy.value = false;
    }
}

function sessionTitle(payment: Payment): string {
    const session = payment.session;

    return (
        session?.daily_session?.name ??
        session?.name ??
        patientName(payment.patient)
    );
}
</script>

<template>
    <Head title="Payments" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Payments"
            description="What each patient owes per session. Record payments and refunds here or on the schedule; each one is written to transactions."
        >
            <template #actions>
                <Button
                    v-if="can('clinic.payments.prepay.store')"
                    @click="prepayOpen = true"
                >
                    <CalendarCheck />
                    Prepay daily sessions
                </Button>
            </template>
        </PageHeader>

        <!-- Totals per status (for the current search); click to filter. -->
        <div
            class="animate__animated animate__fadeInUp grid grid-cols-2 gap-3 lg:grid-cols-4"
            style="--animate-duration: 0.4s"
        >
            <button
                v-for="card in cards"
                :key="card.key"
                type="button"
                :aria-pressed="status === card.key"
                :class="
                    cn(
                        'rounded-xl border bg-card p-4 text-left shadow-sm transition-colors hover:bg-accent/50',
                        status === card.key && 'ring-2 ring-teal-600',
                    )
                "
                @click="status = status === card.key ? 'ALL' : card.key"
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
            <Tabs v-model="status">
                <TabsList>
                    <TabsTrigger value="ALL">All</TabsTrigger>
                    <TabsTrigger value="PENDING">Unpaid</TabsTrigger>
                    <TabsTrigger value="PAST_DUE">Past due</TabsTrigger>
                    <TabsTrigger value="DONE">Paid</TabsTrigger>
                    <TabsTrigger value="REFUNDED">Refunded</TabsTrigger>
                </TabsList>
            </Tabs>
            <div class="relative ml-auto w-full sm:w-64">
                <Search
                    class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    placeholder="Search patients"
                    class="pl-8"
                />
            </div>
        </div>

        <Card
            class="animate__animated animate__fadeInUp overflow-hidden py-0"
            style="--animate-duration: 0.5s"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Patient</TableHead>
                        <TableHead>Session</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Held by</TableHead
                        >
                        <TableHead>Status</TableHead>
                        <TableHead class="text-right">Amount</TableHead>
                        <TableHead class="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading && !data">
                        <TableRow v-for="row in 5" :key="row">
                            <TableCell colspan="6"
                                ><Skeleton class="h-6 w-full"
                            /></TableCell>
                        </TableRow>
                    </template>
                    <TableRow
                        v-for="payment in data?.data ?? []"
                        :key="payment.id"
                        :class="
                            payment.is_past_due &&
                            'bg-rose-50/50 dark:bg-rose-500/5'
                        "
                    >
                        <TableCell class="font-medium">{{
                            patientName(payment.patient)
                        }}</TableCell>
                        <TableCell>
                            <div class="flex items-center gap-1.5">
                                <Repeat
                                    v-if="payment.session?.daily_session"
                                    class="size-3.5 shrink-0 text-teal-600"
                                    aria-label="Daily session"
                                />
                                <span class="truncate">{{
                                    sessionTitle(payment)
                                }}</span>
                            </div>
                            <div
                                v-if="payment.session"
                                class="text-xs text-muted-foreground"
                            >
                                {{ formatDateTime(payment.session.starts_at) }}
                                –
                                {{ formatTime(payment.session.ends_at) }}
                                <template
                                    v-if="
                                        payment.session.status === 'CANCELLED'
                                    "
                                    >· cancelled</template
                                >
                            </div>
                        </TableCell>
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                            >{{
                                payment.session?.clinic_user?.user?.name ?? '—'
                            }}</TableCell
                        >
                        <TableCell>
                            <StatusBadge
                                kind="payment"
                                :status="payment.status"
                                :past-due="payment.is_past_due"
                            />
                            <div
                                v-if="payment.paid_at"
                                class="mt-0.5 text-xs text-muted-foreground"
                            >
                                {{
                                    payment.refunded_at
                                        ? `Refunded ${formatDateTime(payment.refunded_at)}`
                                        : `Paid ${formatDateTime(payment.paid_at)}`
                                }}
                            </div>
                        </TableCell>
                        <TableCell
                            class="text-right font-medium tabular-nums"
                            >{{
                                payment.amount
                                    ? formatMoney(payment.amount)
                                    : '—'
                            }}</TableCell
                        >
                        <TableCell>
                            <DropdownMenu
                                v-if="
                                    canRecord &&
                                    (canPay(payment) ||
                                        payment.status === 'DONE')
                                "
                            >
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="`Actions for ${patientName(payment.patient)}`"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-if="canPay(payment)"
                                        @select="
                                            confirming = { payment, to: 'DONE' }
                                        "
                                    >
                                        <CheckCircle2 />
                                        Mark paid
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="payment.status === 'DONE'"
                                        @select="
                                            confirming = {
                                                payment,
                                                to: 'REFUNDED',
                                            }
                                        "
                                    >
                                        <Undo2 />
                                        Refund
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState
                v-if="data && data.data.length === 0"
                :title="
                    status === 'PAST_DUE'
                        ? 'Nothing past due'
                        : 'No payments here'
                "
                description="Every patient added to a session (or enrolled in a daily session) gets a payment here."
            />
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>

    <ConfirmDialog
        :open="confirming !== null"
        :title="
            confirming?.to === 'DONE'
                ? 'Record this payment?'
                : 'Refund this payment?'
        "
        :description="
            confirming
                ? `${confirming.to === 'DONE' ? 'A payment' : 'A refund'} of ${formatMoney(confirming.payment.amount ?? 0)} will be recorded for ${patientName(confirming.payment.patient)}. This cannot be undone.`
                : ''
        "
        :confirm-label="confirming?.to === 'DONE' ? 'Mark paid' : 'Refund'"
        :processing="busy"
        @update:open="(value) => !value && (confirming = null)"
        @confirm="record"
    />
    <PrepayDialog v-model:open="prepayOpen" @saved="refresh" />
</template>
