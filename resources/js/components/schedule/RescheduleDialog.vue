<script setup lang="ts">
import { CalendarClock } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DateTimePicker from '@/components/form/DateTimePicker.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError } from '@/lib/api';
import {
    clinicParts,
    formatClinicRange,
    fromClinicTime,
} from '@/lib/clinicTime';
import { patientName } from '@/lib/format';
import { index as members } from '@/routes/clinic/members';
import { store as reschedule } from '@/routes/clinic/sessions/members/reschedule';
import type { ClinicMember, ClinicSession, SessionMember } from '@/types';

/**
 * Moves a patient who misses a day of a daily session to a makeup on another
 * date, usually later the same week.
 *
 * What the patient owes moves with them, so this is also the right thing to
 * use when they have already paid. The missed day keeps its record of them.
 */
type Props = {
    sessionMember: SessionMember | null;
    day: ClinicSession;
    /** Pre-filled when the makeup came from dragging onto another day. */
    initialStart?: string;
    initialEnd?: string;
    /**
     * Sessions already loaded for the calendar's visible range. The drop
     * target is always in view, so what the holder has booked that day can
     * be shown without asking the server again.
     */
    sessions?: ClinicSession[];
};

const props = defineProps<Props>();
const emit = defineEmits<{ moved: [] }>();
const open = defineModel<boolean>('open', { default: false });

const { clinicId, context } = useClinic();
const timezone = computed(() => context.value.clinic.timezone);

const startsAt = ref('');
const endsAt = ref('');
const holderId = ref('');
const reason = ref('');
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});

// Fetched here rather than passed in: handing the makeup to someone else is
// the uncommon case, so the list is not worth loading until it is asked for.
const staff = ref<ClinicMember[]>([]);

async function loadStaff(): Promise<void> {
    if (staff.value.length > 0) {
        return;
    }

    try {
        const response = await api.get<{ data: ClinicMember[] }>(
            members.url(clinicId.value),
        );
        staff.value = response.data;
    } catch {
        // The dropdown simply stays on the current holder, which is the
        // default anyway; no need to interrupt the move.
    }
}

/** How long the patient was down for on the day they missed. */
const lengthMinutes = computed(() => {
    const member = props.sessionMember;

    if (!member?.starts_at || !member.ends_at) {
        return 60;
    }

    return Math.round(
        (new Date(member.ends_at).getTime() -
            new Date(member.starts_at).getTime()) /
            60_000,
    );
});

/*
 * `immediate` matters: the calendar mounts this with v-if, so `open` is
 * already true on the first render and a plain watcher would never fire,
 * leaving the date fields empty after a drag.
 */
watch(
    open,
    (isOpen) => {
        const member = props.sessionMember;

        if (!isOpen || !member) {
            return;
        }

        errors.value = {};
        reason.value = '';
        holderId.value = String(props.day.clinic_user?.id ?? '');
        void loadStaff();

        // Dragged onto a day: honour exactly where it was dropped. Otherwise
        // suggest the same slot tomorrow, so only the date is left to change.
        if (props.initialStart && props.initialEnd) {
            startsAt.value = toClinicField(props.initialStart);
            endsAt.value = toClinicField(props.initialEnd);

            return;
        }

        const from = new Date(member.starts_at ?? props.day.starts_at);
        from.setDate(from.getDate() + 1);

        startsAt.value = toClinicField(from.toISOString());
        endsAt.value = toClinicField(
            new Date(from.getTime() + lengthMinutes.value * 60_000),
        );
    },
    { immediate: true },
);

/*
 * The pickers hold clinic wall-clock, not the browser's. Everything else on
 * this dialog, and the calendar behind it, reads the clinic's clock, and a
 * field that silently meant something else was how a 12:45 slot came to
 * show as 8:45 PM.
 */
function toClinicField(instant: string | Date): string {
    const { date, time } = clinicParts(instant, timezone.value);

    return `${date}T${time}`;
}

function fromClinicField(value: string): string {
    return fromClinicTime(
        value.slice(0, 10),
        value.slice(11, 16),
        timezone.value,
    );
}

// Keep the length when the start moves, which is what the staff member means
// by "same session, different day".
watch(startsAt, (value, previous) => {
    if (!value || !previous || !endsAt.value) {
        return;
    }

    const shift =
        new Date(fromClinicField(value)).getTime() -
        new Date(fromClinicField(previous)).getTime();

    endsAt.value = toClinicField(
        new Date(new Date(fromClinicField(endsAt.value)).getTime() + shift),
    );
});

const missedOn = computed(() =>
    props.day ? clinicParts(props.day.starts_at, timezone.value).date : '',
);

/** The clinic date the makeup currently points at. */
const makeupDate = computed(() => startsAt.value.slice(0, 10));

/**
 * What the chosen staff member already has on that date, so the slot can be
 * placed around it. Cancelled days are not in the way.
 */
const dayBookings = computed(() => {
    const holder = Number(holderId.value);

    if (!makeupDate.value || !holder) {
        return [];
    }

    return (props.sessions ?? [])
        .filter(
            (session) =>
                session.clinic_user?.id === holder &&
                session.status !== 'CANCELLED' &&
                clinicParts(session.starts_at, timezone.value).date ===
                    makeupDate.value,
        )
        .flatMap((session) =>
            session.daily_session
                ? (session.members ?? [])
                      .filter((member) => member.starts_at && member.ends_at)
                      .map((member) => ({
                          id: `slot-${member.id}`,
                          label: patientName(member.patient),
                          startsAt: member.starts_at!,
                          endsAt: member.ends_at!,
                          isSamePatient:
                              member.patient?.id ===
                              props.sessionMember?.patient?.id,
                      }))
                : [
                      {
                          id: `session-${session.id}`,
                          label: session.name ?? 'Session',
                          startsAt: session.starts_at,
                          endsAt: session.ends_at,
                          isSamePatient: false,
                      },
                  ],
        )
        .sort((a, b) => a.startsAt.localeCompare(b.startsAt));
});

/** Whether the proposed slot runs into something already booked. */
function clashes(booking: { startsAt: string; endsAt: string }): boolean {
    if (!startsAt.value || !endsAt.value) {
        return false;
    }

    const from = new Date(fromClinicField(startsAt.value)).getTime();
    const to = new Date(fromClinicField(endsAt.value)).getTime();

    return (
        from < new Date(booking.endsAt).getTime() &&
        new Date(booking.startsAt).getTime() < to
    );
}

const clashing = computed(() => dayBookings.value.filter(clashes));
const hasClash = computed(() => clashing.value.length > 0);

/*
 * Clashing with the patient's own slot is the ordinary case: a makeup
 * usually lands on a day the daily session already runs, where they are
 * enrolled anyway. Saying "something already booked" there reads as though
 * someone else is in the way.
 */
const clashMessage = computed(() => {
    if (!hasClash.value) {
        return '';
    }

    const own = clashing.value.find((booking) => booking.isSamePatient);

    return own
        ? `${patientName(props.sessionMember?.patient)} already attends that day at ${formatClinicRange(own.startsAt, own.endsAt, timezone.value)}. Pick a time around it.`
        : 'This time runs into something already booked. Saving will be refused.';
});

async function submit(): Promise<void> {
    const member = props.sessionMember;

    if (!member) {
        return;
    }

    saving.value = true;
    errors.value = {};

    try {
        await api.post(
            reschedule.url([clinicId.value, props.day.id, member.id]),
            {
                starts_at: fromClinicField(startsAt.value),
                ends_at: fromClinicField(endsAt.value),
                clinic_user_id: holderId.value ? Number(holderId.value) : null,
                reason: reason.value.trim() || null,
            },
        );

        toast.success(
            `${patientName(member.patient)} moved to a makeup session.`,
        );
        open.value = false;
        emit('moved');
    } catch (error) {
        if (error instanceof ApiError && error.status === 422) {
            errors.value = error.errors ?? {};
            toast.error(
                error.errors?.session_member?.[0] ??
                    'That makeup could not be arranged.',
            );
        } else {
            toast.error('Could not arrange the makeup.');
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <CalendarClock class="size-4" />
                    Move to a makeup session
                </DialogTitle>
                <DialogDescription>
                    <template v-if="sessionMember">
                        {{ patientName(sessionMember.patient) }} misses
                        {{ missedOn }}. What they owe moves with them, so they
                        are not billed twice.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <form
                id="reschedule-form"
                class="grid gap-4"
                @submit.prevent="submit"
            >
                <FormField
                    label="Makeup starts"
                    for="makeup_starts_at"
                    :error="errors.starts_at?.[0] ?? errors.session_member?.[0]"
                    :hint="`Clinic time (${timezone}).`"
                >
                    <DateTimePicker id="makeup_starts_at" v-model="startsAt" />
                </FormField>

                <FormField
                    label="Makeup ends"
                    for="makeup_ends_at"
                    :error="errors.ends_at?.[0]"
                >
                    <DateTimePicker id="makeup_ends_at" v-model="endsAt" />
                </FormField>

                <FormField
                    label="Held by"
                    for="makeup_holder"
                    :error="errors.clinic_user_id?.[0]"
                >
                    <Select v-model="holderId">
                        <SelectTrigger
                            id="makeup_holder"
                            class="w-full"
                            aria-label="Held by"
                        >
                            <SelectValue placeholder="Same staff member" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="member in staff"
                                :key="member.id"
                                :value="String(member.id)"
                            >
                                {{ member.user?.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>

                <!--
                    What the holder already has that day. Without it the date
                    fields are a guess, and the first you hear of a clash is
                    a 422 after pressing save.
                -->
                <div v-if="makeupDate" class="space-y-1.5">
                    <p class="text-sm font-medium">
                        Already booked on {{ makeupDate }}
                    </p>
                    <ul
                        v-if="dayBookings.length"
                        class="max-h-32 space-y-1 overflow-y-auto rounded-md border p-2"
                    >
                        <li
                            v-for="booking in dayBookings"
                            :key="booking.id"
                            class="flex items-center justify-between gap-2 rounded px-1.5 py-1 text-xs"
                            :class="
                                clashes(booking)
                                    ? 'bg-destructive/10 text-destructive'
                                    : 'text-muted-foreground'
                            "
                        >
                            <span class="truncate">{{ booking.label }}</span>
                            <span class="shrink-0 tabular-nums">
                                {{
                                    formatClinicRange(
                                        booking.startsAt,
                                        booking.endsAt,
                                        timezone,
                                    )
                                }}
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-xs text-muted-foreground">
                        Nothing booked that day — the whole day is free.
                    </p>
                    <p v-if="hasClash" class="text-xs text-destructive">
                        {{ clashMessage }}
                    </p>
                </div>

                <FormField
                    label="Reason"
                    for="makeup_reason"
                    :error="errors.reason?.[0]"
                    hint="Optional, shown on the day they missed."
                >
                    <Input
                        id="makeup_reason"
                        v-model="reason"
                        maxlength="255"
                        placeholder="e.g. Patient was unwell"
                    />
                </FormField>
            </form>

            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="saving"
                    @click="open = false"
                >
                    Cancel
                </Button>
                <Button type="submit" form="reschedule-form" :disabled="saving">
                    <Spinner v-if="saving" />
                    Arrange makeup
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
