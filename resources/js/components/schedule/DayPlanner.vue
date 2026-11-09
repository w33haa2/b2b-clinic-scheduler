<script setup lang="ts">
import {
    CheckCircle2,
    CircleSlash,
    Clock,
    GripVertical,
    MousePointerClick,
    RotateCcw,
    Search,
    Undo2,
    UserMinus,
    X,
} from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PatientPicker from '@/components/PatientPicker.vue';
import type { TimelineBlock } from '@/components/schedule/DayTimeline.vue';
import PlotSlotPopover from '@/components/schedule/PlotSlotPopover.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { api, ApiError } from '@/lib/api';
import {
    clinicParts,
    formatClinicDate,
    formatClinicRange,
    formatClinicTime,
    formatWallTime,
    fromClinicTime,
    minutesOf,
    timeOf,
} from '@/lib/clinicTime';
import { formatMoney, patientName, sumMoney } from '@/lib/format';
import * as dailySessionRoutes from '@/routes/clinic/daily-sessions';
import * as sessionRoutes from '@/routes/clinic/sessions';
import * as memberRoutes from '@/routes/clinic/sessions/members';
import * as paymentRoutes from '@/routes/clinic/sessions/members/payment';
import * as slotRoutes from '@/routes/clinic/sessions/members/slot';
import * as statusRoutes from '@/routes/clinic/sessions/status';
import type {
    ClinicSession,
    DailySession,
    LineItem,
    Patient,
    SessionMember,
    SessionStatus,
    Vacancy,
    Wrapped,
} from '@/types';

const DayTimeline = defineAsyncComponent(
    () => import('@/components/schedule/DayTimeline.vue'),
);

/**
 * Plot one day of a daily session. Patients waiting in "To plot" are dragged
 * onto the timeline, or picked with a click and placed by clicking a time.
 * Blocks can be moved, resized or dragged back to unplot. Everything here is
 * for this day only (the patient is marked "changed today"); the next day
 * follows the regular plan again. Times are clinic time.
 *
 * The current day can be rearranged while it runs (last-minute changes): a
 * now line marks the time, "Fill free time" only uses what is left of the
 * day, and a patient can be added for today only (a walk-in).
 */
type Props = {
    /** The clinic session of the day. */
    sessionId: number;
    catalog: LineItem[];
};

const props = defineProps<Props>();
const emit = defineEmits<{ changed: []; loaded: [day: ClinicSession] }>();

const { clinicId, can, context } = useClinic();
const timezone = computed(() => context.value.clinic.timezone);

const day = ref<ClinicSession | null>(null);
const vacancies = ref<Vacancy[]>([]);
const busyTimes = ref<Vacancy[]>([]);
const dailySession = ref<DailySession | null>(null);
const busy = ref<string | null>(null);
const search = ref('');
const selectedMemberId = ref<number | null>(null);
const refundTarget = ref<SessionMember | null>(null);

const toPlotList = useTemplateRef<HTMLElement>('toPlotList');

const isOpenDay = computed(
    () => day.value?.status === 'PENDING' || day.value?.status === 'ONGOING',
);
const isOver = computed(
    () => !!day.value && new Date(day.value.ends_at) < new Date(),
);
const canPlot = computed(
    () => isOpenDay.value && can('clinic.sessions.members.slot.update'),
);

const dayDate = computed(() =>
    day.value ? clinicParts(day.value.starts_at, timezone.value).date : '',
);
const dayWindow = computed(() =>
    day.value
        ? {
              start: clinicParts(day.value.starts_at, timezone.value).time,
              end: clinicParts(day.value.ends_at, timezone.value).time,
          }
        : { start: '08:00', end: '17:00' },
);
const toWall = (instant: string) => clinicParts(instant, timezone.value).time;

// The clinic's current time, for the now line on today's timeline.
const now = ref(clinicParts(new Date(), timezone.value));
let clock: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    clock = setInterval(() => {
        now.value = clinicParts(new Date(), timezone.value);
    }, 60_000);
});
onBeforeUnmount(() => clearInterval(clock));

const isToday = computed(() => dayDate.value === now.value.date);

const members = computed(() => day.value?.members ?? []);
const toPlot = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return members.value.filter(
        (member) =>
            !member.starts_at &&
            (!needle ||
                patientName(member.patient).toLowerCase().includes(needle)),
    );
});
const counts = computed(() => ({
    total: members.value.length,
    plotted: members.value.filter((member) => member.starts_at).length,
    paid: members.value.filter((member) => member.payment_status === 'DONE')
        .length,
}));
const enrollmentByPatient = computed(
    () =>
        new Map(
            (dailySession.value?.enrollments ?? []).map((enrollment) => [
                enrollment.patient?.id ?? 0,
                enrollment,
            ]),
        ),
);

function serviceOf(member: SessionMember): string {
    return member.line_items?.[0]?.line_item?.name ?? 'No service';
}

function usualLength(member: SessionMember): number {
    return (
        enrollmentByPatient.value.get(member.patient?.id ?? 0)
            ?.duration_minutes ?? 30
    );
}

const blocks = computed<TimelineBlock[]>(() =>
    members.value
        .filter((member) => member.starts_at && member.ends_at)
        .map((member) => ({
            id: String(member.id),
            title: patientName(member.patient),
            subtitle: serviceOf(member),
            start: toWall(member.starts_at!),
            end: toWall(member.ends_at!),
            editable: canPlot.value,
            tone:
                member.payment_status === 'DONE'
                    ? 'paid'
                    : member.customised
                      ? 'changed'
                      : 'default',
            selected: member.id === selectedMemberId.value,
        })),
);
const dayBusy = computed(() =>
    busyTimes.value.map((interval) => ({
        start: toWall(interval.starts_at),
        end: toWall(interval.ends_at),
    })),
);
const selectedMember = computed(() =>
    members.value.find((member) => member.id === selectedMemberId.value),
);

/** A day starts on its date and finishes when its hours end; cancelling is the only manual change. */
const cancelOpen = ref(false);
const statusNote = computed(() => {
    if (!day.value) {
        return '';
    }

    switch (day.value.status) {
        case 'PENDING':
            return `Starts on its own on ${formatClinicDate(day.value.starts_at, timezone.value)}.`;
        case 'ONGOING':
            return `In progress today. Finishes on its own at ${formatClinicTime(day.value.ends_at, timezone.value)}.`;
        case 'DONE':
            return 'This day is over.';
        default:
            return 'This day was cancelled.';
    }
});

async function cancelDay(): Promise<void> {
    if (await changeStatus('CANCELLED')) {
        cancelOpen.value = false;
    }
}

watch(
    () => props.sessionId,
    () => {
        day.value = null;
        search.value = '';
        selectedMemberId.value = null;
        void load();
    },
    { immediate: true },
);

async function load(): Promise<void> {
    try {
        const response = await api.get<
            Wrapped<ClinicSession> & {
                meta?: { vacancies: Vacancy[]; busy: Vacancy[] };
            }
        >(
            sessionRoutes.show.url({
                clinic: clinicId.value,
                clinicSession: props.sessionId,
            }),
        );
        day.value = response.data;
        vacancies.value = response.meta?.vacancies ?? [];
        busyTimes.value = response.meta?.busy ?? [];
        emit('loaded', response.data);

        const seriesId = response.data.daily_session?.id;

        if (
            seriesId &&
            seriesId !== dailySession.value?.id &&
            can('clinic.daily-sessions.show')
        ) {
            dailySession.value = (
                await api.get<Wrapped<DailySession>>(
                    dailySessionRoutes.show.url({
                        clinic: clinicId.value,
                        dailySession: seriesId,
                    }),
                )
            ).data;
        }
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not load the day.',
        );
    }
}

async function perform(
    key: string,
    action: () => Promise<unknown>,
    success: string,
): Promise<boolean> {
    busy.value = key;

    try {
        await action();
        toast.success(success);
        emit('changed');
        await load();

        return true;
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Something went wrong.',
        );

        return false;
    } finally {
        busy.value = null;
    }
}

const ids = () => ({ clinic: clinicId.value, clinicSession: props.sessionId });
const memberIds = (member: SessionMember) => ({
    ...ids(),
    sessionMember: member.id,
});
const memberById = (id: string) =>
    members.value.find((member) => String(member.id) === id);

function changeStatus(status: SessionStatus): Promise<boolean> {
    return perform(
        `status-${status}`,
        () => api.put(statusRoutes.update.url(ids()), { status }),
        'Day updated.',
    );
}

/** Change one patient on this day only. */
function changeDay(
    member: SessionMember,
    changes: Record<string, unknown>,
    success: string,
): Promise<boolean> {
    return perform(
        `day-${member.id}`,
        () => api.put(slotRoutes.update.url(memberIds(member)), changes),
        success,
    );
}

function plotAt(member: SessionMember, start: string, end: string) {
    return changeDay(
        member,
        {
            starts_at: fromClinicTime(dayDate.value, start, timezone.value),
            ends_at: fromClinicTime(dayDate.value, end, timezone.value),
        },
        `${patientName(member.patient)} at ${formatWallTime(start)} today.`,
    );
}

async function onDrop(change: {
    id: string;
    start: string;
    end: string;
    revert?: () => void;
}): Promise<void> {
    const member = memberById(change.id);

    if (!member) {
        return;
    }

    selectedMemberId.value = member.id;

    if (!(await plotAt(member, change.start, change.end))) {
        change.revert?.();
    }
}

/** Click-to-place: the picked "to plot" patient goes to the clicked time. */
function onPick(start: string): void {
    const member = selectedMember.value;

    if (!member || member.starts_at || !canPlot.value) {
        return;
    }

    const end = timeOf(
        Math.min(
            minutesOf(start) + usualLength(member),
            minutesOf(dayWindow.value.end),
        ),
    );
    void plotAt(member, start, end);
}

function onRemove(id: string): void {
    const member = memberById(id);

    if (member) {
        void changeDay(
            member,
            { starts_at: null, ends_at: null },
            `${patientName(member.patient)} unplotted for today.`,
        );
    }
}

/**
 * Put every waiting patient into the first free time, in list order. On
 * today, only the time still ahead counts (from the next quarter hour).
 */
async function plotAllWaiting(): Promise<void> {
    busy.value = 'fill';
    const earliest = isToday.value
        ? Math.ceil(minutesOf(now.value.time) / 15) * 15
        : 0;
    const free = vacancies.value
        .map((vacancy) => [
            Math.max(minutesOf(toWall(vacancy.starts_at)), earliest),
            minutesOf(toWall(vacancy.ends_at)),
        ])
        .filter(([from, to]) => to > from);
    const waiting = [...toPlot.value];
    let placed = 0;

    try {
        for (const member of waiting) {
            const length = usualLength(member);
            const index = free.findIndex(([from, to]) => to - from >= length);

            if (index === -1) {
                break;
            }

            const [from, to] = free[index];
            await api.put(slotRoutes.update.url(memberIds(member)), {
                starts_at: fromClinicTime(
                    dayDate.value,
                    timeOf(from),
                    timezone.value,
                ),
                ends_at: fromClinicTime(
                    dayDate.value,
                    timeOf(from + length),
                    timezone.value,
                ),
            });
            free[index] = [from + length, to];
            placed++;
        }
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not plot everyone.',
        );
    } finally {
        busy.value = null;
    }

    if (placed) {
        toast.success(
            `${placed} ${placed === 1 ? 'patient' : 'patients'} plotted for today.`,
        );
        emit('changed');
    }

    if (placed < waiting.length) {
        toast.warning(
            `${waiting.length - placed} still waiting: not enough free time left.`,
        );
    }

    await load();
}

/** A walk-in: the patient joins this day only, billed the default service. */
function addForToday(patient: Patient): Promise<boolean> {
    return perform(
        'add',
        () =>
            api.post(memberRoutes.store.url(ids()), { patient_id: patient.id }),
        `${patientName(patient)} added for this day only. Plot them on the timeline.`,
    );
}

function setPayment(
    member: SessionMember,
    payment_status: 'DONE' | 'REFUNDED',
): Promise<boolean> {
    return perform(
        `pay-${member.id}`,
        () =>
            api.put(paymentRoutes.update.url(memberIds(member)), {
                payment_status,
            }),
        payment_status === 'DONE' ? 'Payment recorded.' : 'Refund recorded.',
    );
}

async function removeFromDay(member: SessionMember): Promise<void> {
    if (
        await perform(
            `remove-${member.id}`,
            () => api.delete(memberRoutes.destroy.url(memberIds(member))),
            `${patientName(member.patient)} removed from this day.`,
        )
    ) {
        selectedMemberId.value = null;
    }
}

function memberTotal(member: SessionMember): string {
    return sumMoney((member.line_items ?? []).map((item) => item.amount));
}

defineExpose({ reload: load });
</script>

<template>
    <div v-if="!day" class="space-y-3">
        <Skeleton class="h-10" />
        <Skeleton class="h-96" />
    </div>

    <div v-else class="min-w-0 space-y-3">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <dl class="flex gap-4 text-sm">
                <div>
                    <dt class="text-xs text-muted-foreground">Plotted</dt>
                    <dd class="font-medium tabular-nums">
                        {{ counts.plotted }}/{{ counts.total }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Paid</dt>
                    <dd class="font-medium tabular-nums">
                        {{ counts.paid }}/{{ counts.total }}
                    </dd>
                </div>
            </dl>
            <StatusBadge kind="session" :status="day.status" />
            <span class="text-xs text-muted-foreground">{{ statusNote }}</span>
            <Button
                v-if="isOpenDay && can('clinic.sessions.status.update')"
                size="sm"
                variant="outline"
                class="ml-auto"
                :disabled="busy !== null"
                @click="cancelOpen = true"
            >
                <CircleSlash />
                {{ isToday ? 'Cancel today' : 'Cancel this day' }}
            </Button>
        </div>

        <p class="text-xs text-muted-foreground">
            <template v-if="isToday && isOpenDay"
                >Rearrange today for last-minute changes: they apply to today
                only (amber blocks) and tomorrow follows the regular plan
                again.</template
            >
            <template v-else
                >Changes here are for
                {{ formatClinicDate(day.starts_at, timezone) }} only (amber
                blocks). The next day follows the regular plan again.</template
            >
        </p>

        <div class="grid gap-4 md:grid-cols-[15rem_minmax(0,1fr)]">
            <!-- To plot (also where blocks are dragged to unplot) -->
            <div class="min-w-0 space-y-2">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium">
                        To plot
                        <span class="text-muted-foreground tabular-nums">{{
                            toPlot.length
                        }}</span>
                    </span>
                    <Button
                        v-if="canPlot && toPlot.length"
                        size="sm"
                        variant="ghost"
                        class="h-7 px-2 text-xs"
                        :disabled="busy !== null"
                        @click="plotAllWaiting"
                        >Fill free time</Button
                    >
                </div>
                <div v-if="counts.total > 6" class="relative">
                    <Search
                        class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        placeholder="Find a patient"
                        class="h-8 pl-8"
                    />
                </div>
                <div
                    ref="toPlotList"
                    class="max-h-[32rem] min-h-24 space-y-1.5 overflow-y-auto rounded-lg border border-dashed p-2"
                >
                    <div
                        v-for="member in toPlot"
                        :key="member.id"
                        :data-drag-id="canPlot ? member.id : undefined"
                        :data-minutes="usualLength(member)"
                        :data-title="patientName(member.patient)"
                        role="button"
                        tabindex="0"
                        :aria-pressed="member.id === selectedMemberId"
                        class="flex items-center gap-2 rounded-md border bg-card px-2 py-1.5 text-sm shadow-xs"
                        :class="
                            member.id === selectedMemberId &&
                            'ring-2 ring-teal-600'
                        "
                        @click="selectedMemberId = member.id"
                        @keydown.enter="selectedMemberId = member.id"
                    >
                        <GripVertical
                            v-if="canPlot"
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{
                                patientName(member.patient)
                            }}</span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ serviceOf(member) }} ·
                                {{ usualLength(member) }} min</span
                            >
                        </span>
                        <PlotSlotPopover
                            v-if="canPlot"
                            :vacancies="vacancies"
                            :time-zone="timezone"
                            label=""
                            :disabled="busy !== null"
                            @plot="
                                (slot) =>
                                    changeDay(
                                        member,
                                        slot,
                                        `${patientName(member.patient)} plotted for today.`,
                                    )
                            "
                        />
                    </div>
                    <p
                        v-if="!toPlot.length"
                        class="p-2 text-center text-xs text-muted-foreground"
                    >
                        {{
                            counts.total
                                ? 'Everyone is plotted. Drag a block here to unplot it.'
                                : 'Nobody is enrolled yet.'
                        }}
                    </p>
                </div>
                <PatientPicker
                    v-if="isOpenDay && can('clinic.sessions.members.store')"
                    single
                    :placeholder="
                        isToday
                            ? 'Add a patient for today'
                            : 'Add a patient for this day'
                    "
                    :exclude-ids="
                        members.map((member) => member.patient?.id ?? 0)
                    "
                    @pick="addForToday"
                />
                <p
                    v-if="canPlot && toPlot.length"
                    class="flex items-start gap-1.5 text-xs text-muted-foreground"
                >
                    <MousePointerClick class="mt-0.5 size-3.5 shrink-0" />
                    Drag a patient onto a time, or click the patient and then
                    click a time.
                </p>
            </div>

            <div class="min-w-0 rounded-lg border p-1">
                <DayTimeline
                    :date="dayDate"
                    :window="dayWindow"
                    :blocks="blocks"
                    :busy="dayBusy"
                    :source="toPlotList"
                    :trash="toPlotList"
                    :editable="canPlot"
                    :now="isToday ? now.time : null"
                    @drop="onDrop"
                    @change="onDrop"
                    @remove="onRemove"
                    @pick="onPick"
                    @select="(id) => (selectedMemberId = Number(id))"
                />
            </div>
        </div>

        <!-- The selected patient, today -->
        <div
            v-if="selectedMember"
            class="animate__animated animate__fadeIn space-y-3 rounded-lg border bg-muted/30 p-3"
            style="--animate-duration: 0.2s"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium">{{
                    patientName(selectedMember.patient)
                }}</span>
                <StatusBadge
                    v-if="selectedMember.payment_status"
                    kind="payment"
                    :status="selectedMember.payment_status"
                    :past-due="isOver && day.status !== 'CANCELLED'"
                />
                <span
                    v-if="selectedMember.customised"
                    class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"
                    >Changed today</span
                >
                <Button
                    size="icon"
                    variant="ghost"
                    class="ml-auto size-7"
                    aria-label="Close"
                    @click="selectedMemberId = null"
                >
                    <X />
                </Button>
            </div>
            <p
                class="flex items-center gap-1.5 text-xs text-muted-foreground tabular-nums"
            >
                <Clock class="size-3.5" />
                <template v-if="selectedMember.starts_at">{{
                    formatClinicRange(
                        selectedMember.starts_at,
                        selectedMember.ends_at!,
                        timezone,
                    )
                }}</template>
                <template v-else-if="canPlot"
                    >Click a time on the timeline to plot</template
                >
                <template v-else>Not plotted</template>
                · {{ formatMoney(memberTotal(selectedMember)) }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <Select
                    v-if="
                        canPlot && selectedMember.payment_status === 'PENDING'
                    "
                    :model-value="
                        String(
                            selectedMember.line_items?.[0]?.line_item?.id ?? '',
                        )
                    "
                    @update:model-value="
                        (id) =>
                            changeDay(
                                selectedMember!,
                                { line_item_id: Number(id) },
                                'Service changed for today.',
                            )
                    "
                >
                    <SelectTrigger
                        size="sm"
                        class="w-52"
                        aria-label="Service today"
                    >
                        <SelectValue placeholder="Service today" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="item in catalog"
                            :key="item.id"
                            :value="String(item.id)"
                            >{{ item.name }} ·
                            {{ formatMoney(item.price) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <Button
                    v-if="
                        canPlot &&
                        selectedMember.customised &&
                        enrollmentByPatient.has(selectedMember.patient?.id ?? 0)
                    "
                    size="sm"
                    variant="outline"
                    :disabled="busy !== null"
                    @click="
                        changeDay(
                            selectedMember,
                            { reset: true },
                            'Back on the regular plan for today.',
                        )
                    "
                >
                    <RotateCcw />
                    Back to regular plan
                </Button>
                <template v-if="can('clinic.sessions.members.payment.update')">
                    <Button
                        v-if="
                            selectedMember.payment_status === 'PENDING' &&
                            day.status !== 'CANCELLED' &&
                            selectedMember.line_items?.length
                        "
                        size="sm"
                        :disabled="busy !== null"
                        @click="setPayment(selectedMember, 'DONE')"
                    >
                        <CheckCircle2 />
                        Mark paid
                    </Button>
                    <Button
                        v-if="selectedMember.payment_status === 'DONE'"
                        size="sm"
                        variant="outline"
                        :disabled="busy !== null"
                        @click="refundTarget = selectedMember"
                    >
                        <Undo2 />
                        Refund
                    </Button>
                </template>
                <Button
                    v-if="
                        isOpenDay &&
                        selectedMember.payment_status === 'PENDING' &&
                        can('clinic.sessions.members.destroy')
                    "
                    size="sm"
                    variant="ghost"
                    class="text-muted-foreground"
                    :disabled="busy !== null"
                    @click="removeFromDay(selectedMember)"
                >
                    <UserMinus />
                    Not coming today
                </Button>
            </div>
        </div>
    </div>

    <ConfirmDialog
        v-model:open="cancelOpen"
        :title="isToday ? 'Cancel today?' : 'Cancel this day?'"
        :description="`No one is seen on ${day ? formatClinicDate(day.starts_at, timezone) : 'this day'} and its unpaid bills no longer count. Days with paid patients must be refunded first. This cannot be undone.`"
        confirm-label="Cancel the day"
        :processing="busy === 'status-CANCELLED'"
        @confirm="cancelDay"
    />
    <ConfirmDialog
        :open="refundTarget !== null"
        title="Refund this payment?"
        :description="`A refund of ${formatMoney(refundTarget ? memberTotal(refundTarget) : 0)} will be recorded for ${patientName(refundTarget?.patient)}. This cannot be undone.`"
        confirm-label="Refund"
        :processing="busy?.startsWith('pay-') ?? false"
        @update:open="(value) => !value && (refundTarget = null)"
        @confirm="
            refundTarget && setPayment(refundTarget, 'REFUNDED');
            refundTarget = null;
        "
    />
</template>
