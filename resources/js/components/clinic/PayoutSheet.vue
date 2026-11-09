<script setup lang="ts">
import { Ban, CalendarRange, CheckCircle2, UserRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatMoney, patientName } from '@/lib/format';
import { show } from '@/routes/clinic/payouts';
import { update as payoutStatusUpdate } from '@/routes/clinic/payouts/status';
import type { Payout, PayoutStatus, Wrapped } from '@/types';

/**
 * One payout in full: how the money was split between the staff member and
 * the clinic, what the clinic advanced against patients who had not paid,
 * and every billed item the run covers.
 */
const props = defineProps<{ payoutId: number | null }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ changed: [] }>();

const { clinicId, can } = useClinic();

const { data, refresh } = useApiQuery<Wrapped<Payout>>(() =>
    props.payoutId
        ? show.url({ clinic: clinicId.value, payout: props.payoutId })
        : null,
);

/** Never show the payout that was open a moment ago while the next loads. */
const payout = computed(() =>
    data.value?.data.id === props.payoutId ? data.value.data : null,
);

const items = computed(() => payout.value?.items ?? []);

const hasAdvance = computed(
    () => (payout.value?.pending_amount ?? '0.00') !== '0.00',
);

const unsettled = computed(() => payout.value?.unsettled_amount ?? '0.00');

const canChangeStatus = computed(() => can('clinic.payouts.status.update'));

const busy = ref<PayoutStatus | null>(null);

async function changeStatus(status: 'PAID' | 'CANCELLED'): Promise<void> {
    if (!payout.value) {
        return;
    }

    busy.value = status;

    try {
        await api.put(
            payoutStatusUpdate.url({
                clinic: clinicId.value,
                payout: payout.value.id,
            }),
            { status },
        );
        toast.success(
            status === 'PAID' ? 'Payout marked paid.' : 'Payout cancelled.',
        );
        emit('changed');
        await refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not update the payout.',
        );
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 overflow-y-auto sm:max-w-2xl">
            <SheetHeader class="border-b">
                <template v-if="payout">
                    <div class="flex items-center gap-2">
                        <StatusBadge kind="payout" :status="payout.status" />
                        <span
                            v-if="hasAdvance"
                            class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"
                            >Advanced</span
                        >
                    </div>
                    <SheetTitle class="font-mono text-xl">{{
                        payout.reference
                    }}</SheetTitle>
                    <SheetDescription class="flex flex-col gap-1">
                        <span class="flex items-center gap-1.5">
                            <UserRound class="size-4" />
                            {{
                                payout.member?.user?.name ??
                                'Former staff member'
                            }}
                        </span>
                        <span class="flex items-center gap-1.5">
                            <CalendarRange class="size-4" />
                            {{ formatDate(payout.period_start) }} –
                            {{ formatDate(payout.period_end) }}
                        </span>
                    </SheetDescription>
                </template>
                <template v-else>
                    <SheetTitle><Skeleton class="h-6 w-40" /></SheetTitle>
                    <SheetDescription
                        ><Skeleton class="h-4 w-56"
                    /></SheetDescription>
                </template>
            </SheetHeader>

            <div v-if="!payout" class="space-y-3 p-4">
                <Skeleton v-for="row in 4" :key="row" class="h-16" />
            </div>

            <div
                v-else
                class="animate__animated animate__fadeIn flex flex-col gap-6 p-4"
                style="--animate-duration: 0.3s"
            >
                <!-- How what the patients owed was split -->
                <div class="rounded-lg border">
                    <div class="space-y-1 p-3 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground">Gross</span>
                            <span class="tabular-nums">{{
                                formatMoney(payout.gross_amount)
                            }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground">Discounts</span>
                            <span class="tabular-nums"
                                >−{{ formatMoney(payout.discount_total) }}</span
                            >
                        </div>
                    </div>
                    <Separator />
                    <div class="grid grid-cols-2 gap-3 p-3">
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Staff payout ({{ payout.share_percentage }}%)
                            </div>
                            <div
                                class="text-2xl font-semibold text-teal-700 tabular-nums dark:text-teal-300"
                            >
                                {{ formatMoney(payout.worker_amount) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Clinic keeps
                            </div>
                            <div class="text-2xl font-semibold tabular-nums">
                                {{ formatMoney(payout.clinic_amount) }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Money paid to the member before the patient paid -->
                <div
                    v-if="hasAdvance"
                    class="space-y-1 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
                >
                    <div class="flex items-center justify-between font-medium">
                        <span>Advanced</span>
                        <span class="tabular-nums">{{
                            formatMoney(payout.pending_amount)
                        }}</span>
                    </div>
                    <div class="flex items-center justify-between font-medium">
                        <span>Still not collected</span>
                        <span class="tabular-nums">{{
                            formatMoney(unsettled)
                        }}</span>
                    </div>
                    <p class="pt-1">
                        The clinic paid the member for these sessions before the
                        patients paid, so it is out of pocket until the money
                        comes in.
                        {{
                            unsettled === '0.00'
                                ? 'All of it has since been collected.'
                                : 'Each item settles automatically as soon as its payment is recorded.'
                        }}
                    </p>
                </div>

                <p
                    v-if="payout.notes"
                    class="rounded-lg border bg-muted/30 p-3 text-sm text-muted-foreground"
                >
                    {{ payout.notes }}
                </p>

                <!-- Every billed item the run covers -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold">
                            Items
                            <span class="text-muted-foreground"
                                >({{ items.length }})</span
                            >
                        </h3>
                        <span class="text-xs text-muted-foreground"
                            >An item can only ever be on one payout.</span
                        >
                    </div>

                    <p
                        v-if="!items.length"
                        class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
                    >
                        This payout covers no items.
                    </p>

                    <div v-else class="overflow-x-auto rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>Service</TableHead>
                                    <TableHead class="text-right"
                                        >Gross</TableHead
                                    >
                                    <TableHead class="text-right"
                                        >Discount</TableHead
                                    >
                                    <TableHead class="text-right"
                                        >Staff</TableHead
                                    >
                                    <TableHead>Payment</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="item in items"
                                    :key="item.id"
                                    :class="
                                        item.is_unsettled &&
                                        'bg-amber-50/60 dark:bg-amber-500/5'
                                    "
                                >
                                    <TableCell class="whitespace-nowrap">{{
                                        formatDate(item.session?.starts_at)
                                    }}</TableCell>
                                    <TableCell class="font-medium">{{
                                        patientName(item.patient ?? undefined)
                                    }}</TableCell>
                                    <TableCell class="text-muted-foreground">
                                        {{ item.service ?? 'Item' }}
                                        <span
                                            v-if="(item.quantity ?? 1) > 1"
                                            class="tabular-nums"
                                            >× {{ item.quantity }}</span
                                        >
                                    </TableCell>
                                    <TableCell
                                        class="text-right text-muted-foreground tabular-nums"
                                        >{{
                                            formatMoney(item.gross_amount)
                                        }}</TableCell
                                    >
                                    <TableCell
                                        class="text-right text-muted-foreground tabular-nums"
                                    >
                                        <template
                                            v-if="
                                                item.discount_amount !== '0.00'
                                            "
                                            >−{{
                                                formatMoney(
                                                    item.discount_amount,
                                                )
                                            }}</template
                                        >
                                        <template v-else>—</template>
                                    </TableCell>
                                    <TableCell
                                        class="text-right font-medium tabular-nums"
                                        >{{
                                            formatMoney(item.worker_amount)
                                        }}</TableCell
                                    >
                                    <TableCell>
                                        <div
                                            class="flex flex-wrap items-center gap-1.5"
                                        >
                                            <StatusBadge
                                                v-if="item.payment_status"
                                                kind="payment"
                                                :status="item.payment_status"
                                            />
                                            <span
                                                v-if="item.is_unsettled"
                                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"
                                                >Advanced</span
                                            >
                                            <span
                                                v-else-if="item.was_pending"
                                                class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300"
                                                >Settled</span
                                            >
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </div>

            <SheetFooter
                v-if="
                    payout && canChangeStatus && payout.status !== 'CANCELLED'
                "
                class="flex-row justify-end border-t"
            >
                <Button
                    v-if="payout.status === 'DRAFT'"
                    :disabled="busy !== null"
                    @click="changeStatus('PAID')"
                >
                    <Spinner v-if="busy === 'PAID'" />
                    <CheckCircle2 v-else />
                    Mark paid
                </Button>
                <Button
                    variant="outline"
                    :disabled="busy !== null"
                    @click="changeStatus('CANCELLED')"
                >
                    <Spinner v-if="busy === 'CANCELLED'" />
                    <Ban v-else />
                    Cancel payout
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
