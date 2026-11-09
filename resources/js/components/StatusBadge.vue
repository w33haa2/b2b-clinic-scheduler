<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import {
    attendanceStatusLabels,
    paymentStatusLabels,
    payoutStatusLabels,
    sessionStatusLabels,
    transactionTypeLabels,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    AttendanceStatus,
    PaymentStatus,
    PayoutStatus,
    SessionStatus,
    TransactionType,
} from '@/types';

type Props = (
    | { kind: 'session'; status: SessionStatus }
    | { kind: 'payment'; status: PaymentStatus }
    | { kind: 'transaction'; status: TransactionType }
    | { kind: 'payout'; status: PayoutStatus }
    | { kind: 'attendance'; status: AttendanceStatus }
) & {
    /** Payments only: an unpaid payment whose session is over. */
    pastDue?: boolean;
};

const props = defineProps<Props>();

const tones: Record<string, string> = {
    'session:PENDING':
        'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
    'session:ONGOING':
        'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'session:DONE':
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    'session:CANCELLED': 'bg-muted text-muted-foreground line-through',
    'payment:PENDING':
        'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'payment:DONE':
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    'payment:PAST_DUE':
        'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300',
    'payment:REFUNDED':
        'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300',
    'transaction:INBOUND':
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    'transaction:REVERSED':
        'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300',
    'payout:DRAFT':
        'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
    'payout:PAID':
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    'payout:CANCELLED': 'bg-muted text-muted-foreground line-through',
    'attendance:PRESENT':
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    'attendance:LATE':
        'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'attendance:ABSENT':
        'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300',
    'attendance:EXCUSED': 'bg-muted text-muted-foreground',
};

/** An unpaid payment whose session is over shows as "Past due". */
const tone = computed(() =>
    props.kind === 'payment' && props.pastDue && props.status === 'PENDING'
        ? 'payment:PAST_DUE'
        : `${props.kind}:${props.status}`,
);

const label = computed(() => {
    switch (props.kind) {
        case 'session':
            return sessionStatusLabels[props.status];
        case 'payment':
            return tone.value === 'payment:PAST_DUE'
                ? 'Past due'
                : paymentStatusLabels[props.status as PaymentStatus];
        case 'payout':
            return payoutStatusLabels[props.status];
        case 'attendance':
            return attendanceStatusLabels[props.status];
        default:
            return transactionTypeLabels[props.status];
    }
});
</script>

<template>
    <Badge variant="secondary" :class="cn('border-transparent', tones[tone])">
        {{ label }}
    </Badge>
</template>
