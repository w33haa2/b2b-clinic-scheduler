<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import {
    Ban,
    CheckCircle2,
    Eye,
    HandCoins,
    MoreHorizontal,
    Trash2,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import PayoutGenerateDialog from '@/components/clinic/PayoutGenerateDialog.vue';
import PayoutSheet from '@/components/clinic/PayoutSheet.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import DatePicker from '@/components/form/DatePicker.vue';
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
import { api, ApiError, fetchAllPages } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { payouts, receivables } from '@/routes/admin/clinics';
import * as members from '@/routes/clinic/members';
import { destroy, index } from '@/routes/clinic/payouts';
import { update as payoutStatusUpdate } from '@/routes/clinic/payouts/status';
import type {
    ClinicMember,
    MoneyTotal,
    Payout,
    PayoutPage,
    PayoutStatus,
} from '@/types';

const { clinicId, isAdmin, can } = useClinic();

setLayoutProps({
    breadcrumbs: [{ title: 'Payouts', href: payouts(clinicId.value) }],
});

type StatusFilter = 'ALL' | PayoutStatus;

const status = ref<StatusFilter>('ALL');
const memberId = ref('all');
const from = ref('');
const to = ref('');

const { page, perPage, query } = usePagination(25, [
    status,
    memberId,
    from,
    to,
]);

const { data, loading, refresh } = useApiQuery<PayoutPage>(() =>
    index.url(clinicId.value, {
        query: {
            status: status.value === 'ALL' ? undefined : status.value,
            clinic_user_id:
                memberId.value === 'all' ? undefined : Number(memberId.value),
            from: from.value || undefined,
            to: to.value || undefined,
            ...query.value,
        },
    }),
);

type SummaryCard = {
    key: string;
    label: string;
    hint: string;
    value: MoneyTotal | undefined;
    tone: string;
    /** Status cards filter the list; the advance card is not a status. */
    filter?: PayoutStatus;
    /** The advance card opens the clinic's books instead of filtering. */
    href?: ReturnType<typeof receivables>;
};

const cards = computed<SummaryCard[]>(() => {
    const summary = data.value?.meta.summary;

    return [
        {
            key: 'DRAFT',
            label: 'Draft',
            hint: 'Not paid out yet',
            value: summary?.draft,
            tone: 'text-amber-700 dark:text-amber-300',
            filter: 'DRAFT',
        },
        {
            key: 'PAID',
            label: 'Paid',
            hint: 'Paid to staff',
            value: summary?.paid,
            tone: 'text-emerald-700 dark:text-emerald-300',
            filter: 'PAID',
        },
        {
            key: 'UNSETTLED',
            label: 'Advanced, not collected',
            hint: 'Paid out before the patient paid',
            value: summary?.unsettled,
            tone: 'text-rose-700 dark:text-rose-300',
            href: isAdmin.value ? receivables(clinicId.value) : undefined,
        },
        {
            key: 'CANCELLED',
            label: 'Cancelled',
            hint: 'Items released again',
            value: summary?.cancelled,
            tone: 'text-muted-foreground',
            filter: 'CANCELLED',
        },
    ];
});

function toggleStatus(next: PayoutStatus): void {
    status.value = status.value === next ? 'ALL' : next;
}

// The staff list for the filter and the generate dialog.
const staff = ref<ClinicMember[]>([]);

onMounted(async () => {
    if (!can('clinic.members.index')) {
        return;
    }

    staff.value = await fetchAllPages<ClinicMember>((pageNumber) =>
        members.index.url(clinicId.value, {
            query: { per_page: 100, page: pageNumber },
        }),
    ).catch(() => []);
});

// Viewing one payout
const sheetOpen = ref(false);
const viewing = ref<number | null>(null);

function view(payout: Payout): void {
    viewing.value = payout.id;
    sheetOpen.value = true;
}

// Generating, marking paid, cancelling and deleting
const generateOpen = ref(false);
const busy = ref(false);

type PendingAction = {
    payout: Payout;
    action: 'PAID' | 'CANCELLED' | 'DELETE';
};

const confirming = ref<PendingAction | null>(null);

const canChangeStatus = computed(() => can('clinic.payouts.status.update'));
const canDelete = computed(() => can('clinic.payouts.destroy'));

const confirmCopy: Record<
    PendingAction['action'],
    { title: string; label: string; body: (payout: Payout) => string }
> = {
    PAID: {
        title: 'Mark this payout as paid?',
        label: 'Mark paid',
        body: (payout) =>
            `${formatMoney(payout.worker_amount)} will be recorded as paid to ${memberName(payout)} for ${payout.reference}.`,
    },
    CANCELLED: {
        title: 'Cancel this payout?',
        label: 'Cancel payout',
        body: (payout) =>
            `${payout.reference} stays on record as cancelled, and every item it covers is released so it can go into another payout run.`,
    },
    DELETE: {
        title: 'Delete this payout?',
        label: 'Delete',
        body: (payout) =>
            `${payout.reference} disappears and its items are released for another run. Only a draft can be deleted; this cannot be undone.`,
    },
};

async function perform(): Promise<void> {
    const target = confirming.value;

    if (!target) {
        return;
    }

    busy.value = true;

    try {
        if (target.action === 'DELETE') {
            await api.delete(
                destroy.url({
                    clinic: clinicId.value,
                    payout: target.payout.id,
                }),
            );
            toast.success('Payout deleted.');
        } else {
            await api.put(
                payoutStatusUpdate.url({
                    clinic: clinicId.value,
                    payout: target.payout.id,
                }),
                { status: target.action },
            );
            toast.success(
                target.action === 'PAID'
                    ? 'Payout marked paid.'
                    : 'Payout cancelled.',
            );
        }

        confirming.value = null;
        await refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not update the payout.',
        );
    } finally {
        busy.value = false;
    }
}

const memberName = (payout: Payout): string =>
    payout.member?.user?.name ?? 'Former staff member';
</script>

<template>
    <Head title="Payouts" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Payouts"
            description="What the clinic owes each staff member for the sessions they facilitated. Every billed item is split into the member's cut and the clinic's, and can only ever be on one payout."
        >
            <template #actions>
                <Button
                    v-if="can('clinic.payouts.store')"
                    @click="generateOpen = true"
                >
                    <HandCoins />
                    Generate payout
                </Button>
            </template>
        </PageHeader>

        <!-- Totals per status (for the current filters); click to filter. -->
        <div
            class="animate__animated animate__fadeInUp grid grid-cols-2 gap-3 lg:grid-cols-4"
            style="--animate-duration: 0.4s"
        >
            <component
                :is="card.href ? Link : 'button'"
                v-for="card in cards"
                :key="card.key"
                :type="card.href ? undefined : 'button'"
                :href="card.href"
                :disabled="card.href || card.filter ? undefined : true"
                :aria-pressed="card.filter ? status === card.filter : undefined"
                :class="
                    cn(
                        'rounded-xl border bg-card p-4 text-left shadow-sm transition-colors',
                        (card.href || card.filter) && 'hover:bg-accent/50',
                        card.filter &&
                            status === card.filter &&
                            'ring-2 ring-teal-600',
                    )
                "
                @click="card.filter && toggleStatus(card.filter)"
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
            </component>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Tabs v-model="status">
                <TabsList>
                    <TabsTrigger value="ALL">All</TabsTrigger>
                    <TabsTrigger value="DRAFT">Draft</TabsTrigger>
                    <TabsTrigger value="PAID">Paid</TabsTrigger>
                    <TabsTrigger value="CANCELLED">Cancelled</TabsTrigger>
                </TabsList>
            </Tabs>

            <!-- A member who is not a Clinic Admin only ever sees their own. -->
            <Select v-if="isAdmin" v-model="memberId">
                <SelectTrigger class="w-56" aria-label="Staff member">
                    <SelectValue placeholder="All staff" />
                </SelectTrigger>
                <SelectContent class="max-h-72">
                    <SelectItem value="all">All staff</SelectItem>
                    <SelectItem
                        v-for="member in staff"
                        :key="member.id"
                        :value="String(member.id)"
                        >{{ member.user?.name ?? 'Unknown member' }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <div class="flex items-center gap-2">
                <DatePicker
                    v-model="from"
                    placeholder="From"
                    clearable
                    class="w-40"
                />
                <span class="text-muted-foreground">–</span>
                <DatePicker
                    v-model="to"
                    placeholder="To"
                    clearable
                    class="w-40"
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
                        <TableHead>Reference</TableHead>
                        <TableHead>Staff member</TableHead>
                        <TableHead>Period</TableHead>
                        <TableHead class="hidden sm:table-cell"
                            >Share</TableHead
                        >
                        <TableHead class="text-right">Gross</TableHead>
                        <TableHead class="text-right">Staff payout</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading && !data">
                        <TableRow v-for="row in 5" :key="row">
                            <TableCell colspan="8"
                                ><Skeleton class="h-6 w-full"
                            /></TableCell>
                        </TableRow>
                    </template>
                    <TableRow
                        v-for="payout in data?.data ?? []"
                        :key="payout.id"
                        class="cursor-pointer"
                        @click="view(payout)"
                    >
                        <TableCell class="font-mono font-medium">{{
                            payout.reference
                        }}</TableCell>
                        <TableCell>{{ memberName(payout) }}</TableCell>
                        <TableCell class="whitespace-nowrap">
                            {{ formatDate(payout.period_start) }} –
                            {{ formatDate(payout.period_end) }}
                        </TableCell>
                        <TableCell
                            class="hidden text-muted-foreground tabular-nums sm:table-cell"
                            >{{ payout.share_percentage }}%</TableCell
                        >
                        <TableCell
                            class="text-right text-muted-foreground tabular-nums"
                            >{{ formatMoney(payout.gross_amount) }}</TableCell
                        >
                        <TableCell
                            class="text-right font-medium tabular-nums"
                            >{{ formatMoney(payout.worker_amount) }}</TableCell
                        >
                        <TableCell>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <StatusBadge
                                    kind="payout"
                                    :status="payout.status"
                                />
                                <span
                                    v-if="payout.pending_amount !== '0.00'"
                                    class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"
                                    :title="`${formatMoney(payout.pending_amount)} was paid out before the patients paid.`"
                                    >Advanced</span
                                >
                            </div>
                        </TableCell>
                        <TableCell @click.stop>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="`Actions for ${payout.reference}`"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem @select="view(payout)">
                                        <Eye />
                                        View
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            canChangeStatus &&
                                            payout.status === 'DRAFT'
                                        "
                                        @select="
                                            confirming = {
                                                payout,
                                                action: 'PAID',
                                            }
                                        "
                                    >
                                        <CheckCircle2 />
                                        Mark paid
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            canChangeStatus &&
                                            payout.status !== 'CANCELLED'
                                        "
                                        @select="
                                            confirming = {
                                                payout,
                                                action: 'CANCELLED',
                                            }
                                        "
                                    >
                                        <Ban />
                                        Cancel
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            canDelete &&
                                            payout.status === 'DRAFT'
                                        "
                                        variant="destructive"
                                        @select="
                                            confirming = {
                                                payout,
                                                action: 'DELETE',
                                            }
                                        "
                                    >
                                        <Trash2 />
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState
                v-if="data && data.data.length === 0"
                title="No payouts here"
                description="A payout run gathers the sessions one staff member facilitated over a date range, splits each billed item into their cut and the clinic's, and locks those items so they are never paid out twice. Generate one to see it here."
            />
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>

    <PayoutGenerateDialog
        v-model:open="generateOpen"
        :members="staff"
        @saved="
            (payout) => {
                toast.success(
                    `${payout.reference} generated as a draft. Mark it paid once the staff member has been paid.`,
                );
                refresh();
            }
        "
    />
    <PayoutSheet
        v-model:open="sheetOpen"
        :payout-id="viewing"
        @changed="refresh"
    />
    <ConfirmDialog
        :open="confirming !== null"
        :title="confirming ? confirmCopy[confirming.action].title : ''"
        :description="
            confirming
                ? confirmCopy[confirming.action].body(confirming.payout)
                : ''
        "
        :confirm-label="confirming ? confirmCopy[confirming.action].label : ''"
        :processing="busy"
        @update:open="(value) => !value && (confirming = null)"
        @confirm="perform"
    />
</template>
