<script setup lang="ts">
import { ChevronLeft, HandCoins, Info } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import EmptyState from '@/components/EmptyState.vue';
import DatePicker from '@/components/form/DatePicker.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError } from '@/lib/api';
import { clinicParts } from '@/lib/clinicTime';
import { formatDate, formatMoney, sumMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { store } from '@/routes/clinic/payouts';
import { store as previewStore } from '@/routes/clinic/payouts/preview';
import type {
    ClinicMember,
    Payout,
    PayoutPreview,
    PayoutPreviewLine,
    Wrapped,
} from '@/types';

/**
 * Generate a payout for one staff member over one period, in two steps:
 * pick the member and the dates, then review every session they facilitated
 * before anything is saved. Nothing is written until "Generate payout", and
 * items can be left out of the run on the way.
 */
defineProps<{ members: ClinicMember[] }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [payout: Payout] }>();

const { clinicId, context } = useClinic();

const memberId = ref('');
const periodStart = ref('');
const periodEnd = ref('');
const notes = ref('');
/** Items the admin took out of the run, by session line item id. */
const excluded = ref<number[]>([]);
const preview = ref<PayoutPreview | null>(null);
const previewing = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});

/** Today in the clinic's timezone: a period is a range of clinic-local dates. */
function todayInClinic(): string {
    return clinicParts(new Date(), context.value.clinic.timezone).date;
}

function isoDate(year: number, month: number, day: number): string {
    return new Date(Date.UTC(year, month, day)).toISOString().slice(0, 10);
}

/** The whole calendar month, `offset` months away from the current one. */
function monthRange(offset: number): [string, string] {
    const [year, month] = todayInClinic().split('-').map(Number);

    return [
        isoDate(year, month - 1 + offset, 1),
        isoDate(year, month + offset, 0),
    ];
}

/** The last `count` days, ending today. */
function recentRange(count: number): [string, string] {
    const today = todayInClinic();
    const [year, month, day] = today.split('-').map(Number);

    return [isoDate(year, month - 1, day - (count - 1)), today];
}

const quickPicks = computed(() => [
    { label: 'This month', range: monthRange(0) },
    { label: 'Last month', range: monthRange(-1) },
    { label: 'Last 7 days', range: recentRange(7) },
]);

function applyRange([start, end]: [string, string]): void {
    periodStart.value = start;
    periodEnd.value = end;
}

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    memberId.value = '';
    notes.value = '';
    excluded.value = [];
    preview.value = null;
    errors.value = {};
    applyRange(monthRange(0));
});

// Changing the period or the member invalidates what is on screen.
watch([memberId, periodStart, periodEnd], () => {
    preview.value = null;
    excluded.value = [];
});

const lines = computed<PayoutPreviewLine[]>(() => preview.value?.lines ?? []);

const includedLines = computed(() =>
    lines.value.filter(
        (line) => !excluded.value.includes(line.session_line_item_id),
    ),
);

/** Recalculated as rows are unchecked, so the strip always matches the run. */
const totals = computed(() => {
    const included = includedLines.value;
    const total = (pick: (line: PayoutPreviewLine) => string) =>
        sumMoney(included.map(pick));

    return {
        gross: total((line) => line.gross_amount),
        discount: total((line) => line.discount_amount),
        worker: total((line) => line.worker_amount),
        clinic: total((line) => line.clinic_amount),
        pending: sumMoney(
            included
                .filter((line) => line.is_pending)
                .map((line) => line.worker_amount),
        ),
    };
});

const hasAdvance = computed(
    () => (preview.value?.totals.pending_amount ?? '0.00') !== '0.00',
);

/** Why eligible items were left out of the period, in plain words. */
const skippedReasons = computed(() => {
    const skipped = preview.value?.skipped;

    if (!skipped) {
        return [];
    }

    return [
        skipped.refunded ? `${skipped.refunded} refunded` : null,
        skipped.unpaid
            ? `${skipped.unpaid} unpaid (the clinic does not pay out unpaid sessions)`
            : null,
        skipped.not_worked ? `${skipped.not_worked} on a day not worked` : null,
    ].filter((reason): reason is string => reason !== null);
});

const memberName = computed(
    () => preview.value?.member.user?.name ?? 'This staff member',
);

const canPreview = computed(
    () =>
        memberId.value !== '' &&
        periodStart.value !== '' &&
        periodEnd.value !== '',
);

function toggleLine(line: PayoutPreviewLine): void {
    excluded.value = excluded.value.includes(line.session_line_item_id)
        ? excluded.value.filter((id) => id !== line.session_line_item_id)
        : [...excluded.value, line.session_line_item_id];
}

/** The body both endpoints take, so the preview is what gets generated. */
function period(): Record<string, unknown> {
    return {
        clinic_user_id: Number(memberId.value),
        period_start: periodStart.value,
        period_end: periodEnd.value,
    };
}

function fail(error: unknown): void {
    if (error instanceof ApiError) {
        errors.value = error.errors;
        toast.error(error.firstMessage);

        return;
    }

    toast.error('Something went wrong.');
}

async function loadPreview(): Promise<void> {
    previewing.value = true;
    errors.value = {};

    try {
        const response = await api.post<Wrapped<PayoutPreview>>(
            previewStore.url(clinicId.value),
            period(),
        );
        preview.value = response.data;
        excluded.value = [];
    } catch (error) {
        fail(error);
    } finally {
        previewing.value = false;
    }
}

async function generate(): Promise<void> {
    saving.value = true;
    errors.value = {};

    try {
        const response = await api.post<Wrapped<Payout>>(
            store.url(clinicId.value),
            {
                ...period(),
                exclude_session_line_item_ids: excluded.value,
                notes: notes.value || null,
            },
        );
        emit('saved', response.data);
        open.value = false;
    } catch (error) {
        fail(error);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[92svh] overflow-y-auto sm:max-w-3xl lg:max-w-5xl"
        >
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <HandCoins class="size-5 text-teal-600" />
                    Generate payout
                </DialogTitle>
                <DialogDescription>
                    What the clinic owes one staff member for the sessions they
                    facilitated in a date range. Review the run before anything
                    is saved; the payout starts as a draft.
                </DialogDescription>
            </DialogHeader>

            <!-- Step 1: who and when -->
            <div v-if="!preview" class="grid gap-4">
                <FormField
                    label="Staff member"
                    for="payout-member"
                    :error="errors.clinic_user_id?.[0]"
                >
                    <Select v-model="memberId">
                        <SelectTrigger id="payout-member" class="w-full">
                            <SelectValue placeholder="Choose a staff member" />
                        </SelectTrigger>
                        <SelectContent class="max-h-72">
                            <SelectItem
                                v-for="member in members"
                                :key="member.id"
                                :value="String(member.id)"
                            >
                                {{ member.user?.name ?? 'Unknown member' }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Period start"
                        for="payout-period-start"
                        :error="errors.period_start?.[0]"
                    >
                        <DatePicker
                            id="payout-period-start"
                            v-model="periodStart"
                        />
                    </FormField>
                    <FormField
                        label="Period end"
                        for="payout-period-end"
                        :error="errors.period_end?.[0]"
                    >
                        <DatePicker
                            id="payout-period-end"
                            v-model="periodEnd"
                        />
                    </FormField>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-muted-foreground"
                        >Quick pick</span
                    >
                    <Button
                        v-for="pick in quickPicks"
                        :key="pick.label"
                        type="button"
                        size="sm"
                        variant="outline"
                        class="h-7 px-2 text-xs"
                        @click="applyRange(pick.range)"
                        >{{ pick.label }}</Button
                    >
                </div>

                <InputError :message="errors.period?.[0]" />
            </div>

            <!-- Step 2: review the run -->
            <div v-else class="grid gap-4">
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span class="font-medium">{{ memberName }}</span>
                    <span class="text-sm text-muted-foreground">
                        {{ formatDate(preview.period_start) }} –
                        {{ formatDate(preview.period_end) }}
                    </span>
                    <span class="ml-auto text-sm text-muted-foreground">
                        Share
                        <span class="font-medium text-foreground tabular-nums"
                            >{{ preview.share_percentage }}%</span
                        >
                        {{
                            preview.uses_member_override
                                ? "(this member's own rate)"
                                : '(clinic default)'
                        }}
                    </span>
                </div>

                <!-- What the run adds up to, recalculated as rows are excluded -->
                <div
                    class="grid grid-cols-2 gap-3 rounded-lg border bg-muted/30 p-3 sm:grid-cols-4"
                >
                    <div>
                        <div class="text-xs text-muted-foreground">Gross</div>
                        <div class="font-medium tabular-nums">
                            {{ formatMoney(totals.gross) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground">
                            Discounts
                        </div>
                        <div class="font-medium tabular-nums">
                            −{{ formatMoney(totals.discount) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground">
                            Staff payout
                        </div>
                        <div
                            class="text-2xl font-semibold text-teal-700 tabular-nums dark:text-teal-300"
                        >
                            {{ formatMoney(totals.worker) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground">
                            Clinic keeps
                        </div>
                        <div class="font-medium tabular-nums">
                            {{ formatMoney(totals.clinic) }}
                        </div>
                    </div>
                    <p
                        v-if="hasAdvance"
                        class="col-span-full text-xs text-amber-800 dark:text-amber-300"
                    >
                        Of which
                        <span class="font-medium tabular-nums">{{
                            formatMoney(totals.pending)
                        }}</span>
                        is advanced (unpaid by patients) — the clinic pays the
                        member now and collects from the patient later.
                    </p>
                </div>

                <!-- How the period looks on the attendance sheet -->
                <p
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                >
                    <span>
                        <span
                            class="font-medium text-foreground tabular-nums"
                            >{{ preview.attendance.worked }}</span
                        >
                        worked
                    </span>
                    <span>
                        <span
                            class="font-medium text-foreground tabular-nums"
                            >{{ preview.attendance.not_worked }}</span
                        >
                        not worked
                    </span>
                    <span>
                        <span
                            class="font-medium text-foreground tabular-nums"
                            >{{ preview.attendance.unrecorded }}</span
                        >
                        unrecorded days
                    </span>
                    <span v-if="preview.settings.require_attendance">
                        · This clinic requires attendance, so only days that
                        count as worked are included.
                    </span>
                </p>

                <p
                    v-if="skippedReasons.length"
                    class="flex items-start gap-2 rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground"
                >
                    <Info class="mt-px size-3.5 shrink-0" />
                    <span
                        >Left out of this period:
                        {{ skippedReasons.join(', ') }}.</span
                    >
                </p>

                <EmptyState
                    v-if="!lines.length"
                    title="Nothing to pay out"
                    :description="`${memberName} has no billable sessions left to pay out between ${formatDate(preview.period_start)} and ${formatDate(preview.period_end)}. Sessions already on another payout, cancelled sessions and sessions still running are never included.`"
                />

                <template v-else>
                    <div class="max-h-80 overflow-y-auto rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-10" />
                                    <TableHead>Date</TableHead>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>Service</TableHead>
                                    <TableHead class="text-right"
                                        >Qty</TableHead
                                    >
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
                                    v-for="line in lines"
                                    :key="line.session_line_item_id"
                                    :class="
                                        cn(
                                            line.is_pending &&
                                                'bg-amber-50/60 dark:bg-amber-500/5',
                                            excluded.includes(
                                                line.session_line_item_id,
                                            ) && 'opacity-50',
                                        )
                                    "
                                >
                                    <TableCell>
                                        <Checkbox
                                            :model-value="
                                                !excluded.includes(
                                                    line.session_line_item_id,
                                                )
                                            "
                                            :aria-label="`Include ${line.service} for ${line.patient}`"
                                            @update:model-value="
                                                toggleLine(line)
                                            "
                                        />
                                    </TableCell>
                                    <TableCell class="whitespace-nowrap">{{
                                        formatDate(line.occurred_on)
                                    }}</TableCell>
                                    <TableCell class="font-medium">{{
                                        line.patient
                                    }}</TableCell>
                                    <TableCell class="text-muted-foreground">{{
                                        line.service
                                    }}</TableCell>
                                    <TableCell
                                        class="text-right tabular-nums"
                                        >{{ line.quantity }}</TableCell
                                    >
                                    <TableCell
                                        class="text-right text-muted-foreground tabular-nums"
                                        >{{
                                            formatMoney(line.gross_amount)
                                        }}</TableCell
                                    >
                                    <TableCell
                                        class="text-right text-muted-foreground tabular-nums"
                                    >
                                        <template
                                            v-if="
                                                line.discount_amount !== '0.00'
                                            "
                                            >−{{
                                                formatMoney(
                                                    line.discount_amount,
                                                )
                                            }}</template
                                        >
                                        <template v-else>—</template>
                                    </TableCell>
                                    <TableCell
                                        class="text-right font-medium tabular-nums"
                                        >{{
                                            formatMoney(line.worker_amount)
                                        }}</TableCell
                                    >
                                    <TableCell>
                                        <div
                                            class="flex items-center gap-1.5 whitespace-nowrap"
                                        >
                                            <StatusBadge
                                                kind="payment"
                                                :status="line.payment_status"
                                            />
                                            <span
                                                v-if="line.is_pending"
                                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"
                                                >Unpaid</span
                                            >
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <FormField
                        label="Notes"
                        for="payout-notes"
                        :error="errors.notes?.[0]"
                        hint="Optional. Kept on the payout as a record."
                    >
                        <Textarea
                            id="payout-notes"
                            v-model="notes"
                            rows="2"
                            maxlength="1000"
                            placeholder="e.g. Paid with the December cycle"
                        />
                    </FormField>
                </template>

                <InputError
                    :message="errors.period?.[0] ?? errors.status?.[0]"
                />
            </div>

            <DialogFooter class="items-center sm:justify-between">
                <p v-if="preview" class="text-sm text-muted-foreground">
                    {{ includedLines.length }} of {{ lines.length }}
                    {{ lines.length === 1 ? 'item' : 'items' }} included
                </p>
                <div class="flex gap-2 sm:ml-auto">
                    <Button
                        v-if="preview"
                        variant="outline"
                        @click="preview = null"
                    >
                        <ChevronLeft />
                        Back
                    </Button>
                    <Button v-else variant="outline" @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        v-if="!preview"
                        :disabled="!canPreview || previewing"
                        @click="loadPreview"
                    >
                        <Spinner v-if="previewing" />
                        Preview
                    </Button>
                    <Button
                        v-else
                        :disabled="saving || !includedLines.length"
                        @click="generate"
                    >
                        <Spinner v-if="saving" />
                        Generate payout
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
