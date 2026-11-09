<script setup lang="ts">
import {
    ArrowLeft,
    ArrowRight,
    Check,
    ChevronDown,
    ChevronUp,
    GripVertical,
    MousePointerClick,
    Sparkles,
    TriangleAlert,
    X,
} from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import DatePicker from '@/components/form/DatePicker.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PatientPicker from '@/components/PatientPicker.vue';
import DayPlanner from '@/components/schedule/DayPlanner.vue';
import type { TimelineBlock } from '@/components/schedule/DayTimeline.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError } from '@/lib/api';
import {
    browserTimeZone,
    formatWallTime,
    minutesOf,
    timeOf,
} from '@/lib/clinicTime';
import {
    formatDate,
    formatMoney,
    formatWeekdays,
    patientName,
    sumMoney,
    weekdayLabels,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { store, update } from '@/routes/clinic/daily-sessions';
import type {
    ClinicMember,
    DailySession,
    LineItem,
    Patient,
    Wrapped,
} from '@/types';

const DayTimeline = defineAsyncComponent(
    () => import('@/components/schedule/DayTimeline.vue'),
);

/**
 * Create or update a daily session in four steps: details, patients and
 * their services, the plan of the day (each patient's usual slot, by drag
 * and drop or click-to-place) and a review. Saving an update syncs the
 * patients too: new ones are enrolled, changed plans apply to upcoming days
 * (except days changed by hand) and removed ones leave upcoming days.
 *
 * When updating, step 3 can also rearrange today for last-minute changes
 * ("Today only", the day planner): those changes are saved at once and only
 * for today, which the regular plan does not touch once the day has started.
 */
type Props = {
    members: ClinicMember[];
    catalog: LineItem[];
    /** Updating this daily session (with its enrollments); null to create one. */
    dailySession?: DailySession | null;
    /** Staff who already hold a daily session (each may hold one). */
    takenHolderIds?: number[];
    /** Open on this step (0–3). */
    initialStep?: number;
    /** Today's day of this daily session, when it is still open: step 3 can rearrange it. */
    todayDayId?: number | null;
};

const props = withDefaults(defineProps<Props>(), {
    dailySession: null,
    takenHolderIds: () => [],
    initialStep: 0,
    todayDayId: null,
});
const emit = defineEmits<{ saved: [dailySession: DailySession]; cancel: [] }>();

const { clinicId, context } = useClinic();
const timezone = computed(() => context.value.clinic.timezone);
const isEditing = computed(() => props.dailySession !== null);

type Plan = {
    patient: Patient;
    /** "" = the daily session's default service. */
    line_item_id: string;
    unit_price: string;
    start_time: string | null;
    duration_minutes: number;
};

const steps = [
    { title: 'Details', description: 'Who, when and how often' },
    { title: 'Patients & services', description: 'What each patient does' },
    { title: 'Plan the day', description: 'Each patient’s usual slot' },
    { title: 'Review', description: 'Check and save' },
];
const lengths = [15, 30, 45, 60, 90];

const step = ref(props.initialStep);
const details = ref({
    name: '',
    clinic_user_id: '',
    line_item_id: '',
    unit_price: '',
    start_time: '08:00',
    end_time: '17:00',
    weekdays: [1, 2, 3, 4, 5] as number[],
    starts_on: '',
    ends_on: '',
});
const plans = ref<Plan[]>([]);
const pickedPatients = ref<Patient[]>([]);
const gap = ref('0');
/** Step 3: the regular plan (saved with the wizard) or today only (saved at once). */
const planMode = ref<'regular' | 'today'>('regular');
const selectedPatientId = ref<number | null>(null);
const errors = ref<Record<string, string[]>>({});
const saving = ref(false);

const unplacedList = useTemplateRef<HTMLElement>('unplacedList');

/** 15-minute steps from 05:00 to 23:00. */
const times = Array.from({ length: (23 - 5) * 4 + 1 }, (_, index) =>
    timeOf(5 * 60 + index * 15),
);

const defaultService = computed(() =>
    props.catalog.find(
        (item) => String(item.id) === details.value.line_item_id,
    ),
);
const showsTimezoneWarning = computed(
    () => browserTimeZone() !== timezone.value,
);
const removedPatients = computed(() =>
    (props.dailySession?.enrollments ?? [])
        .filter(
            (enrollment) =>
                !plans.value.some(
                    (plan) => plan.patient.id === enrollment.patient?.id,
                ),
        )
        .map((enrollment) => enrollment.patient),
);

onMounted(() => {
    const session = props.dailySession;
    const own = props.members.find(
        (member) =>
            member.id === context.value.member.id &&
            !props.takenHolderIds.includes(member.id),
    );

    details.value = {
        name: session?.name ?? '',
        clinic_user_id: String(session?.clinic_user?.id ?? own?.id ?? ''),
        line_item_id: session?.line_item ? String(session.line_item.id) : '',
        unit_price: session?.unit_price ?? '',
        start_time: session?.start_time ?? '08:00',
        end_time: session?.end_time ?? '17:00',
        weekdays: session?.weekdays ?? [1, 2, 3, 4, 5],
        starts_on:
            session?.starts_on ??
            new Date().toLocaleDateString('en-CA', {
                timeZone: timezone.value,
            }),
        ends_on: session?.ends_on ?? '',
    };

    plans.value = (session?.enrollments ?? [])
        .filter((enrollment) => enrollment.patient)
        .map((enrollment) => ({
            patient: enrollment.patient!,
            line_item_id: enrollment.line_item
                ? String(enrollment.line_item.id)
                : '',
            unit_price: enrollment.unit_price,
            start_time: enrollment.start_time,
            duration_minutes: enrollment.duration_minutes ?? 30,
        }));
    pickedPatients.value = plans.value.map((plan) => plan.patient);

    // Picking a service fills in its catalog price (it can still be changed).
    watch(
        () => details.value.line_item_id,
        () => {
            if (defaultService.value) {
                details.value.unit_price = defaultService.value.price;
            }
        },
    );
});

// Patients picked in step 2 become plans; removing one drops its plan.
watch(pickedPatients, (patients) => {
    const existing = new Map(
        plans.value.map((plan) => [plan.patient.id, plan]),
    );

    plans.value = patients.map(
        (patient) =>
            existing.get(patient.id) ?? {
                patient,
                line_item_id: '',
                unit_price: '',
                start_time: null,
                duration_minutes: 30,
            },
    );
});

function serviceOf(plan: Plan): LineItem | undefined {
    return plan.line_item_id
        ? props.catalog.find((item) => String(item.id) === plan.line_item_id)
        : defaultService.value;
}

function priceOf(plan: Plan): string {
    return (
        plan.unit_price ||
        (plan.line_item_id
            ? (serviceOf(plan)?.price ?? '0')
            : details.value.unit_price || defaultService.value?.price || '0')
    );
}

function changeService(plan: Plan, id: string): void {
    plan.line_item_id = id === 'default' ? '' : id;
    plan.unit_price = '';
}

function toggleWeekday(day: number): void {
    const days = details.value.weekdays;
    details.value.weekdays = days.includes(day)
        ? days.filter((d) => d !== day)
        : [...days, day].sort((a, b) => a - b);
}

// Step 3: the plan of the day
const unplaced = computed(() => plans.value.filter((plan) => !plan.start_time));
const blocks = computed<TimelineBlock[]>(() =>
    plans.value
        .filter((plan) => plan.start_time)
        .map((plan) => ({
            id: String(plan.patient.id),
            title: patientName(plan.patient),
            subtitle: serviceOf(plan)?.name,
            start: plan.start_time!,
            end: timeOf(minutesOf(plan.start_time!) + plan.duration_minutes),
            selected: selectedPatientId.value === plan.patient.id,
        })),
);
const selectedPlan = computed(() =>
    plans.value.find((plan) => plan.patient.id === selectedPatientId.value),
);
const planFor = (id: string) =>
    plans.value.find((plan) => String(plan.patient.id) === id);

/** Why a slot cannot be used, or null if it can. */
function slotProblem(
    plan: Plan,
    start: string,
    minutes: number,
): string | null {
    const from = minutesOf(start);

    if (
        from < minutesOf(details.value.start_time) ||
        from + minutes > minutesOf(details.value.end_time)
    ) {
        return 'That does not fit inside the daily hours.';
    }

    const clash = plans.value.find(
        (other) =>
            other !== plan &&
            other.start_time !== null &&
            from < minutesOf(other.start_time) + other.duration_minutes &&
            minutesOf(other.start_time) < from + minutes,
    );

    return clash
        ? `That time is ${patientName(clash.patient)}’s usual slot.`
        : null;
}

function place(
    plan: Plan,
    start: string,
    minutes: number,
    revert?: () => void,
): boolean {
    const problem = slotProblem(plan, start, minutes);

    if (problem) {
        revert?.();
        toast.error(problem);

        return false;
    }

    plan.start_time = start;
    plan.duration_minutes = minutes;
    selectedPatientId.value = plan.patient.id;

    return true;
}

function onDrop(change: {
    id: string;
    start: string;
    end: string;
    revert?: () => void;
}): void {
    const plan = planFor(change.id);

    if (plan) {
        place(
            plan,
            change.start,
            minutesOf(change.end) - minutesOf(change.start),
            change.revert,
        );
    }
}

/** Click-to-place: the picked patient goes to the clicked time. */
function onPick(time: string): void {
    const plan = selectedPlan.value;

    if (plan && !plan.start_time) {
        place(plan, time, plan.duration_minutes);
    }
}

function unplace(id: string): void {
    const plan = planFor(id);

    if (plan) {
        plan.start_time = null;
    }
}

/** Move the selected patient 15 minutes earlier or later. */
function nudge(plan: Plan, minutes: number): void {
    if (plan.start_time) {
        place(
            plan,
            timeOf(minutesOf(plan.start_time) + minutes),
            plan.duration_minutes,
        );
    }
}

function resize(plan: Plan, minutes: number): void {
    if (!plan.start_time) {
        plan.duration_minutes = minutes;

        return;
    }

    place(plan, plan.start_time, minutes);
}

/** Put every unplaced patient into the first free time, with the chosen gap. */
function autoArrange(): void {
    const pause = Number(gap.value);
    const windowEnd = minutesOf(details.value.end_time);

    for (const plan of unplaced.value) {
        for (
            let start = minutesOf(details.value.start_time);
            start + plan.duration_minutes <= windowEnd;
            start += 5
        ) {
            const blocked = plans.value.some(
                (other) =>
                    other !== plan &&
                    other.start_time !== null &&
                    start <
                        minutesOf(other.start_time) +
                            other.duration_minutes +
                            pause &&
                    minutesOf(other.start_time) - pause <
                        start + plan.duration_minutes,
            );

            if (!blocked) {
                plan.start_time = timeOf(start);
                break;
            }
        }
    }

    if (unplaced.value.length) {
        toast.warning(
            `${unplaced.value.length} did not fit in the hours. They can be plotted day by day.`,
        );
    }
}

// Review and save
const dailyTotal = computed(() => sumMoney(plans.value.map(priceOf)));
const holderName = computed(
    () =>
        props.members.find(
            (member) => String(member.id) === details.value.clinic_user_id,
        )?.user?.name,
);

const stepOfError: Array<[RegExp, number]> = [
    [/^patients\.\d+\.(start_time|duration_minutes)$/, 2],
    [/^patient/, 1],
];

function canContinue(): boolean {
    const d = details.value;

    return !!(
        d.name &&
        d.clinic_user_id &&
        d.line_item_id &&
        d.weekdays.length &&
        d.starts_on &&
        d.end_time > d.start_time
    );
}

function payload(): Record<string, unknown> {
    const d = details.value;
    const data: Record<string, unknown> = {
        ...d,
        clinic_user_id: Number(d.clinic_user_id),
        line_item_id: Number(d.line_item_id),
        ends_on: d.ends_on || null,
        patients: plans.value.map((plan) => ({
            patient_id: plan.patient.id,
            line_item_id: plan.line_item_id ? Number(plan.line_item_id) : null,
            unit_price: plan.unit_price || null,
            start_time: plan.start_time,
            duration_minutes: plan.start_time ? plan.duration_minutes : null,
        })),
    };

    if (!d.unit_price) {
        delete data.unit_price;
    }

    return data;
}

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};

    try {
        const response = props.dailySession
            ? await api.put<Wrapped<DailySession>>(
                  update.url({
                      clinic: clinicId.value,
                      dailySession: props.dailySession.id,
                  }),
                  payload(),
              )
            : await api.post<Wrapped<DailySession>>(
                  store.url(clinicId.value),
                  payload(),
              );
        emit('saved', response.data);
    } catch (error) {
        if (!(error instanceof ApiError)) {
            toast.error('Could not save the daily session.');

            return;
        }

        errors.value = error.errors;
        toast.error(error.firstMessage);

        const firstKey = Object.keys(error.errors)[0] ?? '';
        step.value =
            stepOfError.find(([pattern]) => pattern.test(firstKey))?.[1] ?? 0;
    } finally {
        saving.value = false;
    }
}

const error = (key: string) => errors.value[key]?.[0];
const planError = (index: number, ...fields: string[]) =>
    fields.map((field) => error(`patients.${index}.${field}`)).find(Boolean);
</script>

<template>
    <Card class="gap-0 overflow-hidden py-0">
        <!-- Steps -->
        <ol class="grid grid-cols-4 gap-2 border-b p-4" aria-label="Steps">
            <li v-for="(item, index) in steps" :key="item.title">
                <button
                    type="button"
                    class="w-full text-left disabled:cursor-not-allowed"
                    :disabled="index > 0 && !canContinue()"
                    :aria-current="index === step ? 'step' : undefined"
                    @click="step = index"
                >
                    <span
                        :class="
                            cn(
                                'block h-1 rounded-full transition-colors',
                                index <= step ? 'bg-teal-600' : 'bg-muted',
                            )
                        "
                    />
                    <span
                        :class="
                            cn(
                                'mt-1.5 flex items-center gap-1 text-xs font-medium sm:text-sm',
                                index === step
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                            )
                        "
                    >
                        <Check v-if="index < step" class="size-3.5" />
                        {{ item.title }}
                    </span>
                    <span
                        class="hidden text-xs text-muted-foreground sm:block"
                        >{{ item.description }}</span
                    >
                </button>
            </li>
        </ol>

        <div class="p-4 sm:p-6">
            <!-- 1. Details -->
            <div v-if="step === 0" class="grid max-w-3xl gap-4">
                <p
                    v-if="showsTimezoneWarning"
                    class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    Your computer is on {{ browserTimeZone() }} but the clinic
                    is on {{ timezone }}. Enter the hours as the clinic's local
                    time.
                </p>

                <FormField
                    label="Name"
                    for="wizard-name"
                    :error="error('name')"
                >
                    <Input
                        id="wizard-name"
                        v-model="details.name"
                        placeholder="e.g. Daily physio"
                    />
                </FormField>

                <div class="grid gap-4 sm:grid-cols-3">
                    <FormField
                        label="Held by"
                        for="wizard-holder"
                        :error="error('clinic_user_id')"
                        hint="Each staff member holds one daily session."
                    >
                        <Select v-model="details.clinic_user_id">
                            <SelectTrigger id="wizard-holder" class="w-full">
                                <SelectValue placeholder="Choose staff" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="member in members"
                                    :key="member.id"
                                    :value="String(member.id)"
                                    :disabled="
                                        takenHolderIds.includes(member.id)
                                    "
                                >
                                    {{ member.user?.name }}
                                    <span
                                        v-if="
                                            takenHolderIds.includes(member.id)
                                        "
                                        class="text-xs text-muted-foreground"
                                        >· has one</span
                                    >
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Default service"
                        for="wizard-service"
                        :error="error('line_item_id')"
                        hint="Each patient can have their own."
                    >
                        <Select v-model="details.line_item_id">
                            <SelectTrigger id="wizard-service" class="w-full">
                                <SelectValue placeholder="Choose a service" />
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
                    </FormField>
                    <FormField
                        label="Default price"
                        for="wizard-price"
                        :error="error('unit_price')"
                    >
                        <Input
                            id="wizard-price"
                            v-model="details.unit_price"
                            inputmode="decimal"
                            class="tabular-nums"
                            :placeholder="defaultService?.price ?? '0.00'"
                        />
                    </FormField>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <FormField
                        label="Opens"
                        for="wizard-start"
                        :error="error('start_time')"
                    >
                        <Select v-model="details.start_time">
                            <SelectTrigger id="wizard-start" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="time in times"
                                    :key="time"
                                    :value="time"
                                    >{{ formatWallTime(time) }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Closes"
                        for="wizard-end"
                        :error="error('end_time')"
                    >
                        <Select v-model="details.end_time">
                            <SelectTrigger id="wizard-end" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="time in times"
                                    :key="time"
                                    :value="time"
                                    >{{ formatWallTime(time) }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </FormField>
                </div>

                <FormField label="Repeats on" :error="error('weekdays')">
                    <div
                        class="flex flex-wrap gap-1.5"
                        role="group"
                        aria-label="Repeats on"
                    >
                        <button
                            v-for="day in [1, 2, 3, 4, 5, 6, 7]"
                            :key="day"
                            type="button"
                            :aria-pressed="details.weekdays.includes(day)"
                            :class="
                                cn(
                                    'h-8 min-w-11 rounded-full border px-3 text-sm font-medium transition-colors',
                                    details.weekdays.includes(day)
                                        ? 'border-teal-600 bg-teal-600 text-white'
                                        : 'bg-background text-muted-foreground hover:bg-accent',
                                )
                            "
                            @click="toggleWeekday(day)"
                        >
                            {{ weekdayLabels[day] }}
                        </button>
                    </div>
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Starts on"
                        for="wizard-starts-on"
                        :error="error('starts_on')"
                    >
                        <DatePicker
                            id="wizard-starts-on"
                            v-model="details.starts_on"
                        />
                    </FormField>
                    <FormField
                        label="Ends on (optional)"
                        for="wizard-ends-on"
                        :error="error('ends_on')"
                        hint="Leave empty to keep it running. Set it to pause the daily session."
                    >
                        <DatePicker
                            id="wizard-ends-on"
                            v-model="details.ends_on"
                            clearable
                            placeholder="No end date"
                        />
                    </FormField>
                </div>
            </div>

            <!-- 2. Patients & services -->
            <div v-else-if="step === 1" class="grid max-w-3xl gap-4">
                <PatientPicker
                    v-model="pickedPatients"
                    placeholder="Add patients"
                />
                <InputError :message="error('patient')" />

                <ul v-if="plans.length" class="divide-y rounded-lg border">
                    <li
                        v-for="(plan, index) in plans"
                        :key="plan.patient.id"
                        class="grid gap-2 px-3 py-2 sm:grid-cols-[1fr_15rem_7rem_auto] sm:items-center"
                    >
                        <span class="truncate text-sm font-medium">{{
                            patientName(plan.patient)
                        }}</span>
                        <Select
                            :model-value="plan.line_item_id || 'default'"
                            @update:model-value="
                                (id) => changeService(plan, String(id))
                            "
                        >
                            <SelectTrigger
                                size="sm"
                                class="w-full"
                                :aria-label="`Service for ${patientName(plan.patient)}`"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="default"
                                    >Default ({{
                                        defaultService?.name
                                    }})</SelectItem
                                >
                                <SelectItem
                                    v-for="item in catalog"
                                    :key="item.id"
                                    :value="String(item.id)"
                                    >{{ item.name }} ·
                                    {{ formatMoney(item.price) }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <Input
                            v-model="plan.unit_price"
                            inputmode="decimal"
                            class="h-8 tabular-nums"
                            :placeholder="priceOf(plan)"
                            :aria-label="`Price for ${patientName(plan.patient)}`"
                        />
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :aria-label="`Remove ${patientName(plan.patient)}`"
                            @click="
                                pickedPatients = pickedPatients.filter(
                                    (p) => p.id !== plan.patient.id,
                                )
                            "
                        >
                            <X />
                        </Button>
                        <InputError
                            class="sm:col-span-4"
                            :message="
                                planError(
                                    index,
                                    'patient_id',
                                    'line_item_id',
                                    'unit_price',
                                )
                            "
                        />
                    </li>
                </ul>
                <p
                    v-else
                    class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    Add the patients who come every day.
                </p>
                <p
                    v-if="removedPatients.length"
                    class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
                >
                    {{
                        removedPatients
                            .map((patient) => patientName(patient))
                            .join(', ')
                    }}
                    will leave every upcoming day when you save. Past days stay.
                </p>
            </div>

            <!-- 3. Plan the day -->
            <div v-else-if="step === 2" class="space-y-4">
                <div
                    v-if="todayDayId"
                    class="flex flex-wrap items-center gap-3"
                >
                    <Tabs v-model="planMode">
                        <TabsList>
                            <TabsTrigger value="regular"
                                >Regular plan</TabsTrigger
                            >
                            <TabsTrigger value="today">Today only</TabsTrigger>
                        </TabsList>
                    </Tabs>
                    <p class="text-xs text-muted-foreground">
                        {{
                            planMode === 'today'
                                ? 'Last-minute changes for today. Saved right away; tomorrow follows the regular plan.'
                                : 'Every day’s usual slots. Saved with the wizard.'
                        }}
                    </p>
                </div>
                <DayPlanner
                    v-if="todayDayId && planMode === 'today'"
                    :session-id="todayDayId"
                    :catalog="catalog"
                />
                <div
                    v-else
                    class="grid gap-4 lg:grid-cols-[17rem_minmax(0,1fr)]"
                >
                    <div class="min-w-0 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium">
                                Not placed
                                <span
                                    class="text-muted-foreground tabular-nums"
                                    >{{ unplaced.length }}</span
                                >
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ blocks.length }}/{{ plans.length }} placed
                            </span>
                        </div>
                        <div
                            ref="unplacedList"
                            class="max-h-[30rem] min-h-24 space-y-1.5 overflow-y-auto rounded-lg border border-dashed p-2"
                        >
                            <div
                                v-for="plan in unplaced"
                                :key="plan.patient.id"
                                :data-drag-id="plan.patient.id"
                                :data-minutes="plan.duration_minutes"
                                :data-title="patientName(plan.patient)"
                                role="button"
                                tabindex="0"
                                :aria-pressed="
                                    selectedPatientId === plan.patient.id
                                "
                                :class="
                                    cn(
                                        'space-y-1.5 rounded-md border bg-card px-2 py-1.5 text-sm shadow-xs',
                                        selectedPatientId === plan.patient.id &&
                                            'ring-2 ring-teal-600',
                                    )
                                "
                                @click="selectedPatientId = plan.patient.id"
                                @keydown.enter="
                                    selectedPatientId = plan.patient.id
                                "
                            >
                                <div class="flex items-center gap-2">
                                    <GripVertical
                                        class="size-4 shrink-0 text-muted-foreground"
                                    />
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate font-medium"
                                            >{{
                                                patientName(plan.patient)
                                            }}</span
                                        >
                                        <span
                                            class="block truncate text-xs text-muted-foreground"
                                            >{{ serviceOf(plan)?.name }}</span
                                        >
                                    </span>
                                </div>
                                <div
                                    class="flex gap-1"
                                    role="group"
                                    :aria-label="`Length for ${patientName(plan.patient)}`"
                                >
                                    <button
                                        v-for="length in lengths"
                                        :key="length"
                                        type="button"
                                        :aria-pressed="
                                            plan.duration_minutes === length
                                        "
                                        :class="
                                            cn(
                                                'rounded px-1.5 py-0.5 text-[11px] font-medium tabular-nums',
                                                plan.duration_minutes === length
                                                    ? 'bg-teal-600 text-white'
                                                    : 'bg-muted text-muted-foreground hover:bg-accent',
                                            )
                                        "
                                        @click.stop="
                                            plan.duration_minutes = length
                                        "
                                    >
                                        {{ length }}
                                    </button>
                                </div>
                            </div>
                            <p
                                v-if="!unplaced.length"
                                class="p-2 text-center text-xs text-muted-foreground"
                            >
                                Everyone has a usual slot. Drag a block here to
                                take it off.
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="!unplaced.length"
                                @click="autoArrange"
                            >
                                <Sparkles />
                                Auto-arrange
                            </Button>
                            <Select v-model="gap">
                                <SelectTrigger
                                    size="sm"
                                    class="w-32"
                                    aria-label="Gap between patients"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="0">No gap</SelectItem>
                                    <SelectItem value="5">5 min gap</SelectItem>
                                    <SelectItem value="10"
                                        >10 min gap</SelectItem
                                    >
                                    <SelectItem value="15"
                                        >15 min gap</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <Button
                                size="sm"
                                variant="ghost"
                                :disabled="!blocks.length"
                                @click="
                                    plans.forEach(
                                        (plan) => (plan.start_time = null),
                                    )
                                "
                                >Clear all</Button
                            >
                        </div>

                        <p
                            class="flex items-start gap-1.5 text-xs text-muted-foreground"
                        >
                            <MousePointerClick
                                class="mt-0.5 size-3.5 shrink-0"
                            />
                            Drag a patient onto a time, or click a patient and
                            then a time. Drag a block to move it or its bottom
                            edge to change its length. Patients without a usual
                            slot are plotted day by day.
                        </p>

                        <!-- The selected patient -->
                        <div
                            v-if="selectedPlan"
                            class="space-y-2 rounded-lg border bg-muted/30 p-3 text-sm"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="min-w-0 flex-1 truncate font-medium"
                                    >{{
                                        patientName(selectedPlan.patient)
                                    }}</span
                                >
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    class="size-7"
                                    aria-label="Close"
                                    @click="selectedPatientId = null"
                                >
                                    <X />
                                </Button>
                            </div>
                            <p
                                class="text-xs text-muted-foreground tabular-nums"
                            >
                                <template v-if="selectedPlan.start_time"
                                    >{{
                                        formatWallTime(selectedPlan.start_time)
                                    }}
                                    –
                                    {{
                                        formatWallTime(
                                            timeOf(
                                                minutesOf(
                                                    selectedPlan.start_time,
                                                ) +
                                                    selectedPlan.duration_minutes,
                                            ),
                                        )
                                    }}</template
                                >
                                <template v-else
                                    >Click a time on the timeline</template
                                >
                            </p>
                            <div class="flex flex-wrap gap-1">
                                <Button
                                    v-for="length in lengths"
                                    :key="length"
                                    size="sm"
                                    :variant="
                                        selectedPlan.duration_minutes === length
                                            ? 'default'
                                            : 'outline'
                                    "
                                    class="h-7 px-2 text-xs tabular-nums"
                                    @click="resize(selectedPlan, length)"
                                    >{{ length }}m</Button
                                >
                            </div>
                            <div
                                v-if="selectedPlan.start_time"
                                class="flex gap-1"
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="h-7"
                                    @click="nudge(selectedPlan, -15)"
                                >
                                    <ChevronUp />
                                    15 min
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="h-7"
                                    @click="nudge(selectedPlan, 15)"
                                >
                                    <ChevronDown />
                                    15 min
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="h-7"
                                    @click="selectedPlan.start_time = null"
                                    >Take off</Button
                                >
                            </div>
                        </div>
                        <InputError
                            :message="
                                plans
                                    .map((_, index) =>
                                        planError(
                                            index,
                                            'start_time',
                                            'duration_minutes',
                                        ),
                                    )
                                    .find(Boolean)
                            "
                        />
                    </div>
                    <div class="min-w-0 rounded-lg border p-1">
                        <DayTimeline
                            date="2000-01-03"
                            :window="{
                                start: details.start_time,
                                end: details.end_time,
                            }"
                            :blocks="blocks"
                            :source="unplacedList"
                            :trash="unplacedList"
                            @drop="onDrop"
                            @change="onDrop"
                            @remove="unplace"
                            @pick="onPick"
                            @select="(id) => (selectedPatientId = Number(id))"
                        />
                    </div>
                </div>
            </div>

            <!-- 4. Review -->
            <div v-else class="grid max-w-3xl gap-4 text-sm">
                <dl
                    class="grid grid-cols-[8rem_1fr] gap-x-4 gap-y-1.5 rounded-lg border bg-muted/30 p-3"
                >
                    <dt class="text-muted-foreground">Name</dt>
                    <dd class="font-medium">{{ details.name }}</dd>
                    <dt class="text-muted-foreground">Held by</dt>
                    <dd>{{ holderName }}</dd>
                    <dt class="text-muted-foreground">Repeats</dt>
                    <dd>
                        {{ formatWeekdays(details.weekdays) }},
                        <span class="tabular-nums"
                            >{{ formatWallTime(details.start_time) }} –
                            {{ formatWallTime(details.end_time) }}</span
                        >
                        <span class="text-muted-foreground">
                            (clinic time)</span
                        >
                    </dd>
                    <dt class="text-muted-foreground">Runs</dt>
                    <dd>
                        {{ formatDate(details.starts_on) }} –
                        {{
                            details.ends_on
                                ? formatDate(details.ends_on)
                                : 'no end date'
                        }}
                    </dd>
                </dl>

                <table v-if="plans.length" class="w-full text-left">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1 font-medium">Patient</th>
                            <th class="py-1 font-medium">Service</th>
                            <th class="py-1 font-medium">Usual slot</th>
                            <th class="py-1 text-right font-medium">Per day</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="plan in plans" :key="plan.patient.id">
                            <td class="py-1.5 font-medium">
                                {{ patientName(plan.patient) }}
                            </td>
                            <td class="py-1.5">{{ serviceOf(plan)?.name }}</td>
                            <td class="py-1.5 tabular-nums">
                                <template v-if="plan.start_time"
                                    >{{ formatWallTime(plan.start_time) }} ·
                                    {{ plan.duration_minutes }} min</template
                                >
                                <span v-else class="text-muted-foreground"
                                    >Plot each day</span
                                >
                            </td>
                            <td class="py-1.5 text-right tabular-nums">
                                {{ formatMoney(priceOf(plan)) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td
                                colspan="3"
                                class="pt-2 text-right text-muted-foreground"
                            >
                                Billed per day
                            </td>
                            <td
                                class="pt-2 text-right font-semibold tabular-nums"
                            >
                                {{ formatMoney(dailyTotal) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <p
                    v-if="removedPatients.length"
                    class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
                >
                    Leaving upcoming days:
                    {{
                        removedPatients
                            .map((patient) => patientName(patient))
                            .join(', ')
                    }}.
                </p>
                <p class="text-xs text-muted-foreground">
                    <template v-if="isEditing">
                        Changes apply to days that have not started. Days you
                        changed by hand keep their changes; past days never
                        change.
                        <template v-if="todayDayId">
                            If today has already started it keeps its schedule;
                            rearrange it under Plan the day → Today only.
                        </template>
                    </template>
                    <template v-else>
                        The next 14 days are scheduled right away. Each day
                        every patient gets an unpaid bill for their service and
                        is put into their usual slot when the staff member is
                        free; otherwise they wait in “To plot”.
                    </template>
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2 border-t bg-muted/30 px-4 py-3">
            <Button v-if="step > 0" variant="ghost" @click="step--">
                <ArrowLeft />
                Back
            </Button>
            <div class="ml-auto flex gap-2">
                <Button variant="outline" @click="emit('cancel')"
                    >Cancel</Button
                >
                <Button
                    v-if="step < steps.length - 1"
                    :disabled="!canContinue()"
                    @click="step++"
                >
                    {{ step === 2 && !blocks.length ? 'Skip' : 'Next' }}
                    <ArrowRight />
                </Button>
                <Button
                    v-else
                    :disabled="saving || !canContinue()"
                    @click="submit"
                >
                    <Spinner v-if="saving" />
                    {{ isEditing ? 'Save changes' : 'Create daily session' }}
                </Button>
            </div>
        </div>
    </Card>
</template>
