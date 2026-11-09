<script setup lang="ts">
import { CalendarCheck } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError, fetchAllPages } from '@/lib/api';
import { formatClinicRange } from '@/lib/clinicTime';
import { formatDate, formatMoney, patientName } from '@/lib/format';
import * as dailySessionRoutes from '@/routes/clinic/daily-sessions';
import * as paymentRoutes from '@/routes/clinic/payments';
import { store as prepayStore } from '@/routes/clinic/payments/prepay';
import type { DailySession, Payment, UpcomingDay, Wrapped } from '@/types';

/**
 * Pay in advance for upcoming days of a daily session: choose the daily
 * session, an enrolled patient, then the days. Days the patient already paid
 * (or that were cancelled) cannot be picked.
 */
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();

const { clinicId, context } = useClinic();

const dailySessions = ref<DailySession[]>([]);
const dailySessionId = ref('');
const patientId = ref('');
const detail = ref<DailySession | null>(null);
const upcomingDays = ref<UpcomingDay[]>([]);
const paidSessionIds = ref<Set<number>>(new Set());
const selected = ref<string[]>([]);
const loading = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});

const enrollments = computed(() => detail.value?.enrollments ?? []);
const total = computed(() =>
    detail.value
        ? (
              (Math.round(Number(detail.value.unit_price) * 100) *
                  selected.value.length) /
              100
          ).toFixed(2)
        : '0.00',
);

function isUnavailable(day: UpcomingDay): boolean {
    return (
        day.status === 'CANCELLED' ||
        (day.clinic_session_id !== null &&
            paidSessionIds.value.has(day.clinic_session_id))
    );
}

const available = computed(() =>
    upcomingDays.value.filter((day) => !isUnavailable(day)),
);

watch(open, async (isOpen) => {
    if (!isOpen) {
        return;
    }

    dailySessionId.value = '';
    patientId.value = '';
    detail.value = null;
    selected.value = [];
    errors.value = {};
    dailySessions.value = await fetchAllPages<DailySession>((page) =>
        dailySessionRoutes.index.url(clinicId.value, {
            query: { active: 1, per_page: 100, page },
        }),
    ).catch(() => []);
});

watch(dailySessionId, async (id) => {
    detail.value = null;
    patientId.value = '';
    upcomingDays.value = [];
    selected.value = [];

    if (!id) {
        return;
    }

    loading.value = true;

    try {
        const response = await api.get<
            Wrapped<DailySession> & { meta: { upcoming_days: UpcomingDay[] } }
        >(
            dailySessionRoutes.show.url({
                clinic: clinicId.value,
                dailySession: Number(id),
            }),
        );
        detail.value = response.data;
        upcomingDays.value = response.meta.upcoming_days;
    } finally {
        loading.value = false;
    }
});

watch(patientId, async (id) => {
    paidSessionIds.value = new Set();
    selected.value = [];

    if (!id || !dailySessionId.value) {
        return;
    }

    const paid = await fetchAllPages<Payment>((page) =>
        paymentRoutes.index.url(clinicId.value, {
            query: {
                daily_session_id: Number(dailySessionId.value),
                patient_id: Number(id),
                status: 'DONE',
                per_page: 100,
                page,
            },
        }),
    ).catch(() => []);

    paidSessionIds.value = new Set(
        paid.map((payment) => payment.session?.id ?? 0),
    );
});

function toggle(date: string): void {
    selected.value = selected.value.includes(date)
        ? selected.value.filter((d) => d !== date)
        : [...selected.value, date];
}

function selectNext(count: number): void {
    selected.value = available.value.slice(0, count).map((day) => day.date);
}

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};

    try {
        await api.post(prepayStore.url(clinicId.value), {
            daily_session_id: Number(dailySessionId.value),
            patient_id: Number(patientId.value),
            dates: [...selected.value].sort(),
        });
        toast.success(
            `${selected.value.length} ${selected.value.length === 1 ? 'day' : 'days'} prepaid.`,
        );
        emit('saved');
        open.value = false;
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.errors;
            toast.error(error.firstMessage);
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <CalendarCheck class="size-5 text-teal-600" />
                    Prepay daily sessions
                </DialogTitle>
                <DialogDescription>
                    Record payment now for upcoming days. Each day is marked
                    paid and appears in transactions.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <FormField
                    label="Daily session"
                    for="prepay-session"
                    :error="errors.daily_session_id?.[0]"
                >
                    <Select v-model="dailySessionId">
                        <SelectTrigger id="prepay-session" class="w-full">
                            <SelectValue placeholder="Choose a daily session" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="session in dailySessions"
                                :key="session.id"
                                :value="String(session.id)"
                            >
                                {{ session.name }} ·
                                {{ session.clinic_user?.user?.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>

                <FormField
                    v-if="detail"
                    label="Patient"
                    for="prepay-patient"
                    :error="errors.patient_id?.[0]"
                >
                    <Select v-model="patientId" :disabled="!enrollments.length">
                        <SelectTrigger id="prepay-patient" class="w-full">
                            <SelectValue
                                :placeholder="
                                    enrollments.length
                                        ? 'Choose an enrolled patient'
                                        : 'Nobody is enrolled'
                                "
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="enrollment in enrollments"
                                :key="enrollment.id"
                                :value="String(enrollment.patient?.id)"
                            >
                                {{ patientName(enrollment.patient) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>

                <Skeleton v-if="loading" class="h-40" />

                <div v-if="detail && patientId" class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Days</span>
                        <div class="flex gap-1">
                            <Button
                                v-for="count in [5, 10, 20]"
                                :key="count"
                                type="button"
                                size="sm"
                                variant="ghost"
                                class="h-7 px-2 text-xs"
                                @click="selectNext(count)"
                                >Next {{ count }}</Button
                            >
                        </div>
                    </div>
                    <ul
                        class="max-h-64 divide-y overflow-y-auto rounded-md border"
                    >
                        <li v-for="day in upcomingDays" :key="day.date">
                            <label
                                class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-accent/50"
                                :class="
                                    isUnavailable(day) &&
                                    'cursor-not-allowed opacity-50'
                                "
                            >
                                <Checkbox
                                    :model-value="selected.includes(day.date)"
                                    :disabled="isUnavailable(day)"
                                    @update:model-value="toggle(day.date)"
                                />
                                <span class="flex-1">{{
                                    formatDate(day.date)
                                }}</span>
                                <span
                                    class="text-xs text-muted-foreground tabular-nums"
                                    >{{
                                        formatClinicRange(
                                            day.starts_at,
                                            day.ends_at,
                                            context.clinic.timezone,
                                        )
                                    }}</span
                                >
                                <span
                                    v-if="isUnavailable(day)"
                                    class="text-xs text-muted-foreground"
                                    >{{
                                        day.status === 'CANCELLED'
                                            ? 'Cancelled'
                                            : 'Paid'
                                    }}</span
                                >
                            </label>
                        </li>
                    </ul>
                    <InputError
                        :message="
                            errors.dates?.[0] ??
                            Object.entries(errors).find(([key]) =>
                                key.startsWith('dates.'),
                            )?.[1]?.[0]
                        "
                    />
                </div>
            </div>

            <DialogFooter class="items-center sm:justify-between">
                <p v-if="detail" class="text-sm text-muted-foreground">
                    {{ selected.length }} ×
                    {{ formatMoney(detail.unit_price) }} =
                    <span class="font-medium text-foreground tabular-nums">{{
                        formatMoney(total)
                    }}</span>
                </p>
                <div class="flex gap-2 sm:ml-auto">
                    <Button variant="outline" @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        :disabled="saving || !selected.length || !patientId"
                        @click="submit"
                    >
                        <Spinner v-if="saving" />
                        Record prepayment
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
