<script setup lang="ts">
import type { EventContentArg, EventInput } from '@fullcalendar/core';
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    ListFilter,
    Plus,
    Repeat,
    Users,
} from '@lucide/vue';
import { storeToRefs } from 'pinia';
import {
    computed,
    defineAsyncComponent,
    onMounted,
    ref,
    useTemplateRef,
} from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CalendarFilterDialog from '@/components/schedule/CalendarFilterDialog.vue';
import DailySessionBadge from '@/components/schedule/DailySessionBadge.vue';
import DailySessionSheet from '@/components/schedule/DailySessionSheet.vue';
import ScheduleFilterPanel from '@/components/schedule/ScheduleFilterPanel.vue';
import SessionFormDialog from '@/components/schedule/SessionFormDialog.vue';
import SessionSheet from '@/components/schedule/SessionSheet.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useClinic } from '@/composables/useClinic';
import { useInitials } from '@/composables/useInitials';
import { api, ApiError, fetchAllPages } from '@/lib/api';
import { formatTime, patientName } from '@/lib/format';
import { memberColorIndex, memberColorVar } from '@/lib/memberColors';
import {
    matchesRules,
    paymentState,
    quickFilterRules,
} from '@/lib/scheduleFilters';
import { dailySessions, schedule } from '@/routes/admin/clinics';
import * as calendarFilterRoutes from '@/routes/clinic/calendar-filters';
import { index as lineItemsIndex } from '@/routes/clinic/line-items';
import { index as membersIndex } from '@/routes/clinic/members';
import {
    index as sessionsIndex,
    update as sessionUpdate,
} from '@/routes/clinic/sessions';
import { update as slotUpdate } from '@/routes/clinic/sessions/members/slot';
import type { CalendarView } from '@/stores/schedule';
import { useScheduleStore } from '@/stores/schedule';
import type {
    CalendarFilter,
    ClinicMember,
    ClinicSession,
    LineItem,
    SessionMember,
} from '@/types';

const SessionCalendar = defineAsyncComponent(
    () => import('@/components/schedule/SessionCalendar.vue'),
);

const { clinicId, can } = useClinic();
const { getInitials } = useInitials();
const scheduleStore = useScheduleStore();
const { view, focusDate, quickFilters, savedFilterId } =
    storeToRefs(scheduleStore);

setLayoutProps({
    breadcrumbs: [{ title: 'Schedule', href: schedule(clinicId.value) }],
});

const calendar = useTemplateRef<{
    prev: () => void;
    next: () => void;
    today: () => void;
    changeView: (view: CalendarView) => void;
}>('calendar');

// The calendar is browser-only; render it after hydration.
const isMounted = ref(false);
const sessions = ref<ClinicSession[]>([]);
const members = ref<ClinicMember[]>([]);
const catalog = ref<LineItem[]>([]);
const savedFilters = ref<CalendarFilter[]>([]);
const loadingSessions = ref(false);
const title = ref('');
const range = ref<{ start: Date; end: Date } | null>(null);
let latestLoad = 0;

const views: Array<{ value: CalendarView; label: string }> = [
    { value: 'timeGridDay', label: 'Day' },
    { value: 'timeGridWeek', label: 'Week' },
    { value: 'dayGridMonth', label: 'Month' },
    { value: 'listWeek', label: 'Agenda' },
];

/** Members who hold sessions in view but are not in the members list (e.g. removed staff). */
const legendMembers = computed<ClinicMember[]>(() => {
    const known = new Map(members.value.map((member) => [member.id, member]));

    for (const session of sessions.value) {
        if (session.clinic_user && !known.has(session.clinic_user.id)) {
            known.set(session.clinic_user.id, session.clinic_user);
        }
    }

    return [...known.values()].sort((a, b) => a.id - b.id);
});
const memberOrder = computed(() =>
    legendMembers.value.map((member) => member.id),
);
const sessionCounts = computed(() =>
    sessions.value.reduce<Record<number, number>>((counts, session) => {
        const id = session.clinic_user?.id ?? 0;
        counts[id] = (counts[id] ?? 0) + 1;

        return counts;
    }, {}),
);

/** Patient names seen on the schedule, for labelling saved patient filters. */
const knownPatients = computed(
    () =>
        new Map(
            sessions.value.flatMap((session) =>
                (session.members ?? []).map(
                    (member) =>
                        [
                            member.patient?.id ?? 0,
                            patientName(member.patient),
                        ] as [number, string],
                ),
            ),
        ),
);

const activeSavedFilter = computed(
    () =>
        savedFilters.value.find(
            (filter) => filter.id === savedFilterId.value,
        ) ?? null,
);
const quickRules = computed(() => quickFilterRules(quickFilters.value));
const isFiltered = computed(
    () =>
        activeSavedFilter.value !== null ||
        quickRules.value.conditions.length > 0,
);

function isShown(session: ClinicSession, member?: SessionMember): boolean {
    const now = new Date();

    return (
        scheduleStore.isMemberVisible(session.clinic_user?.id ?? 0) &&
        matchesRules(quickRules.value, session, now, member) &&
        matchesRules(
            activeSavedFilter.value?.rules ?? null,
            session,
            now,
            member,
        )
    );
}

function colorClass(session: ClinicSession): string {
    return `member-color-${memberColorIndex(session.clinic_user?.id ?? 0, memberOrder.value)}`;
}

const events = computed<EventInput[]>(() =>
    sessions.value.flatMap((session): EventInput[] => {
        const isOpen =
            session.status === 'PENDING' || session.status === 'ONGOING';

        if (!session.daily_session) {
            if (!isShown(session)) {
                return [];
            }

            const patients =
                session.members?.map((member) => patientName(member.patient)) ??
                [];

            return [
                {
                    id: `session-${session.id}`,
                    start: session.starts_at,
                    end: session.ends_at,
                    title: session.name || patients.join(', ') || 'Session',
                    classNames: [
                        colorClass(session),
                        `session-${session.status.toLowerCase()}`,
                    ],
                    editable: isOpen && can('clinic.sessions.update'),
                    extendedProps: { kind: 'session', session, patients },
                },
            ];
        }

        // A day of a daily session: only its plotted patients show, each
        // as its own event marked with a daily-session badge.
        const dayEvents: EventInput[] = [];

        for (const member of session.members ?? []) {
            if (
                !member.starts_at ||
                !member.ends_at ||
                !isShown(session, member)
            ) {
                continue;
            }

            dayEvents.push({
                id: `slot-${member.id}`,
                start: member.starts_at,
                end: member.ends_at,
                title: patientName(member.patient),
                classNames: [
                    colorClass(session),
                    'daily-slot',
                    `session-${session.status.toLowerCase()}`,
                ],
                editable: isOpen && can('clinic.sessions.members.slot.update'),
                durationEditable:
                    isOpen && can('clinic.sessions.members.slot.update'),
                extendedProps: { kind: 'slot', session, member },
            });
        }

        return dayEvents;
    }),
);

/** Sessions (one-off, or days of daily sessions) the filters hide. */
const hiddenCount = computed(
    () => sessions.value.filter((session) => !isShown(session)).length,
);

async function loadSessions(): Promise<void> {
    if (!range.value) {
        return;
    }

    const load = ++latestLoad;
    const { start, end } = range.value;
    loadingSessions.value = true;

    try {
        const result = await fetchAllPages<ClinicSession>((page) =>
            sessionsIndex.url(clinicId.value, {
                query: {
                    from: start.toISOString(),
                    to: end.toISOString(),
                    per_page: 100,
                    page,
                },
            }),
        );

        if (load === latestLoad) {
            sessions.value = result;
        }
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not load sessions.',
        );
    } finally {
        if (load === latestLoad) {
            loadingSessions.value = false;
        }
    }
}

async function loadSavedFilters(): Promise<void> {
    try {
        const response = await api.get<{ data: CalendarFilter[] }>(
            calendarFilterRoutes.index.url(clinicId.value),
        );
        savedFilters.value = response.data;

        if (
            savedFilterId.value !== null &&
            !response.data.some((filter) => filter.id === savedFilterId.value)
        ) {
            savedFilterId.value = null;
        }
    } catch {
        savedFilters.value = [];
    }
}

function onRange(next: {
    start: Date;
    end: Date;
    title: string;
    view: CalendarView;
    currentStart: Date;
}): void {
    range.value = { start: next.start, end: next.end };
    title.value = next.title;
    view.value = next.view;
    focusDate.value = next.currentStart.toISOString();
    void loadSessions();
}

function changeView(next: string | number): void {
    calendar.value?.changeView(next as CalendarView);
}

// Creating and editing sessions
const formOpen = ref(false);
const formRange = ref<{ start: Date; end: Date } | null>(null);
const editingSession = ref<ClinicSession | null>(null);

function openCreate(selection: { start: Date; end: Date } | null = null): void {
    if (!can('clinic.sessions.store')) {
        return;
    }

    editingSession.value = null;
    formRange.value = selection;
    formOpen.value = true;
}

function openEdit(session: ClinicSession): void {
    editingSession.value = session;
    formRange.value = null;
    formOpen.value = true;
}

function onSaved(session: ClinicSession): void {
    toast.success(
        editingSession.value ? 'Session updated.' : 'Session booked.',
    );
    void loadSessions();

    if (editingSession.value) {
        sheetSessionId.value = session.id;
    }
}

async function onMove(change: {
    eventId: string;
    start: Date;
    end: Date;
    revert: () => void;
}): Promise<void> {
    const [kind, rawId] = change.eventId.split('-');
    const id = Number(rawId);

    try {
        if (kind === 'session') {
            await api.patch(
                sessionUpdate.url({
                    clinic: clinicId.value,
                    clinicSession: id,
                }),
                {
                    starts_at: change.start.toISOString(),
                    ends_at: change.end.toISOString(),
                },
            );
        } else if (kind === 'slot') {
            const session = sessions.value.find((candidate) =>
                candidate.members?.some((member) => member.id === id),
            );

            if (!session) {
                change.revert();

                return;
            }

            await api.put(
                slotUpdate.url({
                    clinic: clinicId.value,
                    clinicSession: session.id,
                    sessionMember: id,
                }),
                {
                    starts_at: change.start.toISOString(),
                    ends_at: change.end.toISOString(),
                },
            );
        } else {
            change.revert();

            return;
        }

        toast.success(`Moved to ${formatTime(change.start.toISOString())}.`);
        void loadSessions();
    } catch (error) {
        change.revert();
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not move the session.',
        );
    }
}

// Session and daily-session details
const sheetOpen = ref(false);
const sheetSessionId = ref<number | null>(null);
const dailySheetOpen = ref(false);
const dailySheetSessionId = ref<number | null>(null);

function openEvent(eventId: string): void {
    const [kind, rawId] = eventId.split('-');
    const id = Number(rawId);

    if (kind === 'session') {
        sheetSessionId.value = id;
        sheetOpen.value = true;

        return;
    }

    const dayId = sessions.value.find((session) =>
        session.members?.some((member) => member.id === id),
    )?.id;

    if (dayId) {
        dailySheetSessionId.value = dayId;
        dailySheetOpen.value = true;
    }
}

// Saved filters
const filterDialogOpen = ref(false);
const editingFilter = ref<CalendarFilter | null>(null);
const deletingFilter = ref<CalendarFilter | null>(null);
const deletingFilterBusy = ref(false);

function openFilterCreate(): void {
    editingFilter.value = null;
    filterDialogOpen.value = true;
}

function openFilterEdit(filter: CalendarFilter): void {
    editingFilter.value = filter;
    filterDialogOpen.value = true;
}

function onFilterSaved(filter: CalendarFilter): void {
    toast.success(
        editingFilter.value ? 'Filter saved.' : 'Filter saved and applied.',
    );
    savedFilterId.value = filter.id;
    void loadSavedFilters();
}

async function deleteFilter(): Promise<void> {
    const filter = deletingFilter.value;

    if (!filter) {
        return;
    }

    deletingFilterBusy.value = true;

    try {
        await api.delete(
            calendarFilterRoutes.destroy.url({
                clinic: clinicId.value,
                calendarFilter: filter.id,
            }),
        );
        toast.success('Filter deleted.');
        deletingFilter.value = null;
        filterDialogOpen.value = false;
        await loadSavedFilters();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not delete the filter.',
        );
    } finally {
        deletingFilterBusy.value = false;
    }
}

onMounted(async () => {
    // A seven-day grid is too cramped on a phone: start with the day view there.
    if (
        view.value === 'timeGridWeek' &&
        window.matchMedia('(max-width: 640px)').matches
    ) {
        view.value = 'timeGridDay';
    }

    isMounted.value = true;
    void loadSavedFilters();

    const [loadedMembers, loadedCatalog] = await Promise.allSettled([
        can('clinic.members.index')
            ? fetchAllPages<ClinicMember>((page) =>
                  membersIndex.url(clinicId.value, {
                      query: { per_page: 100, page },
                  }),
              )
            : Promise.resolve([]),
        can('clinic.line-items.index')
            ? fetchAllPages<LineItem>((page) =>
                  lineItemsIndex.url(clinicId.value, {
                      query: { per_page: 100, page },
                  }),
              )
            : Promise.resolve([]),
    ]);

    members.value =
        loadedMembers.status === 'fulfilled' ? loadedMembers.value : [];
    catalog.value =
        loadedCatalog.status === 'fulfilled' ? loadedCatalog.value : [];
});

type EventProps =
    | { kind: 'session'; session: ClinicSession; patients: string[] }
    | { kind: 'slot'; session: ClinicSession; member: SessionMember };

const eventProps = (arg: EventContentArg) =>
    arg.event.extendedProps as EventProps;
const eventPatients = (arg: EventContentArg) => {
    const props = eventProps(arg);

    return props.kind === 'session' ? props.patients : [];
};
const eventSession = (arg: EventContentArg) => eventProps(arg).session;
const isDailySlot = (arg: EventContentArg) => eventProps(arg).kind === 'slot';

/** Length of the event as currently drawn (it changes while dragging or resizing). */
function eventMinutes(arg: EventContentArg): number {
    const { start, end } = arg.event;

    return start && end ? (end.getTime() - start.getTime()) / 60000 : 60;
}

/** Start time only, for blocks too short for the full range ("9:30am"). */
function eventStart(arg: EventContentArg): string {
    return arg.event.start
        ? arg.event.start
              .toLocaleTimeString(undefined, {
                  hour: 'numeric',
                  minute: '2-digit',
              })
              .replace(':00', '')
              .replace(' ', '')
              .toLowerCase()
        : '';
}

/** Hover text with everything the block may be too small to show. */
function eventTooltip(arg: EventContentArg): string {
    const props = eventProps(arg);
    const worker = props.session.clinic_user?.user?.name;

    return [
        arg.event.title,
        arg.timeText,
        props.kind === 'slot'
            ? `${props.session.daily_session?.name} (daily session)`
            : null,
        worker ? `Held by ${worker}` : null,
        props.kind === 'session' && props.patients.length
            ? `Patients: ${props.patients.join(', ')}`
            : null,
    ]
        .filter(Boolean)
        .join('\n');
}
</script>

<template>
    <Head title="Schedule" />

    <div class="flex h-[calc(100svh-5rem)] min-h-[640px] flex-1 gap-4 p-4">
        <!-- Side panel: new session, filters, staff calendars -->
        <aside
            class="animate__animated animate__fadeInLeft hidden w-64 shrink-0 flex-col gap-6 overflow-y-auto lg:flex"
            style="--animate-duration: 0.5s"
        >
            <Button
                v-if="can('clinic.sessions.store')"
                size="lg"
                class="h-12 shrink-0 rounded-2xl shadow-md"
                @click="openCreate()"
            >
                <Plus />
                New session
            </Button>
            <Link
                v-if="can('clinic.daily-sessions.index')"
                :href="dailySessions(clinicId)"
                class="-mt-3 flex items-center gap-2 rounded-lg border border-dashed px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
                <Repeat class="size-4 text-teal-600" />
                Daily sessions
                <ArrowUpRight class="ml-auto size-3.5" />
            </Link>

            <ScheduleFilterPanel
                :saved-filters="savedFilters"
                @create="openFilterCreate"
                @edit="openFilterEdit"
            />

            <div class="space-y-2">
                <h2
                    class="flex items-center gap-2 px-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <Users class="size-3.5" />
                    Staff
                </h2>
                <template v-if="!isMounted">
                    <Skeleton v-for="row in 3" :key="row" class="h-8" />
                </template>
                <label
                    v-for="member in legendMembers"
                    :key="member.id"
                    class="flex cursor-pointer items-center gap-2.5 rounded-md px-1 py-1.5 text-sm hover:bg-accent"
                >
                    <Checkbox
                        :model-value="scheduleStore.isMemberVisible(member.id)"
                        class="data-[state=checked]:border-transparent"
                        :style="{
                            backgroundColor: scheduleStore.isMemberVisible(
                                member.id,
                            )
                                ? memberColorVar(
                                      memberColorIndex(member.id, memberOrder),
                                  )
                                : undefined,
                            borderColor: memberColorVar(
                                memberColorIndex(member.id, memberOrder),
                            ),
                        }"
                        @update:model-value="
                            scheduleStore.toggleMember(member.id)
                        "
                    />
                    <span class="flex-1 truncate">{{
                        member.user?.name ?? 'Former staff'
                    }}</span>
                    <span class="text-xs text-muted-foreground tabular-nums">{{
                        sessionCounts[member.id] ?? 0
                    }}</span>
                </label>
                <p
                    v-if="isMounted && legendMembers.length === 0"
                    class="px-1 text-sm text-muted-foreground"
                >
                    No staff with sessions in this range.
                </p>
            </div>

            <div
                class="mt-auto space-y-1.5 rounded-xl border bg-muted/30 p-3 text-xs text-muted-foreground"
            >
                <p class="font-medium text-foreground">Tips</p>
                <p v-if="can('clinic.sessions.store')">
                    Drag across the grid to book a session.
                </p>
                <p>
                    Patients plotted in a daily session carry a
                    <span class="font-medium text-foreground">daily</span>
                    badge. Click one to plot or rearrange that day.
                </p>
                <p v-if="can('clinic.sessions.members.slot.update')">
                    Drag a plotted patient to move their slot.
                </p>
            </div>
        </aside>

        <!-- Calendar -->
        <section
            class="flex min-w-0 flex-1 flex-col overflow-hidden rounded-xl border bg-card shadow-sm"
        >
            <header
                class="flex flex-wrap items-center gap-2 border-b px-3 py-2"
            >
                <Button variant="outline" size="sm" @click="calendar?.today()"
                    >Today</Button
                >
                <div class="flex">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Previous"
                        @click="calendar?.prev()"
                    >
                        <ChevronLeft />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Next"
                        @click="calendar?.next()"
                    >
                        <ChevronRight />
                    </Button>
                </div>
                <h1 class="flex items-center gap-2 text-lg font-semibold">
                    <CalendarDays class="size-5 text-teal-600" />
                    <span
                        :key="title"
                        class="animate__animated animate__fadeIn"
                        style="--animate-duration: 0.3s"
                        >{{ title || 'Schedule' }}</span
                    >
                </h1>
                <Spinner v-if="loadingSessions" class="text-muted-foreground" />
                <span
                    v-if="isFiltered && hiddenCount > 0"
                    class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                >
                    {{ hiddenCount }} hidden by filters
                </span>
                <div class="ml-auto flex items-center gap-2">
                    <Popover>
                        <PopoverTrigger as-child>
                            <Button
                                size="sm"
                                variant="outline"
                                class="lg:hidden"
                                aria-label="Filters"
                            >
                                <ListFilter />
                                <span
                                    v-if="isFiltered"
                                    class="size-1.5 rounded-full bg-teal-600"
                                />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent align="end" class="w-72">
                            <ScheduleFilterPanel
                                :saved-filters="savedFilters"
                                @create="openFilterCreate"
                                @edit="openFilterEdit"
                            />
                        </PopoverContent>
                    </Popover>
                    <Button
                        v-if="can('clinic.sessions.store')"
                        size="sm"
                        class="lg:hidden"
                        @click="openCreate()"
                    >
                        <Plus />
                        New
                    </Button>
                    <Tabs :model-value="view" @update:model-value="changeView">
                        <TabsList>
                            <TabsTrigger
                                v-for="option in views"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                </div>
            </header>

            <div class="relative min-h-0 flex-1 p-2">
                <SessionCalendar
                    v-if="isMounted"
                    ref="calendar"
                    :events="events"
                    :initial-view="view"
                    :initial-date="focusDate"
                    :can-create="can('clinic.sessions.store')"
                    @range="onRange"
                    @select="openCreate"
                    @open="openEvent"
                    @move="onMove"
                >
                    <template #event="{ arg }">
                        <div
                            v-if="arg.view.type === 'dayGridMonth'"
                            class="flex min-w-0 items-center gap-1 px-1 text-xs"
                        >
                            <DailySessionBadge
                                v-if="isDailySlot(arg)"
                                :name="
                                    eventSession(arg).daily_session?.name ?? ''
                                "
                                :holder="
                                    eventSession(arg).clinic_user?.user?.name
                                "
                            />
                            <span
                                v-else
                                class="size-2 shrink-0 rounded-full bg-(--member-color)"
                            />
                            <span class="shrink-0 font-medium">{{
                                arg.timeText
                            }}</span>
                            <span class="truncate">{{ arg.event.title }}</span>
                        </div>
                        <div
                            v-else-if="arg.view.type === 'listWeek'"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1"
                        >
                            <DailySessionBadge
                                v-if="isDailySlot(arg)"
                                :name="
                                    eventSession(arg).daily_session?.name ?? ''
                                "
                                :holder="
                                    eventSession(arg).clinic_user?.user?.name
                                "
                            />
                            <span class="font-medium">{{
                                arg.event.title
                            }}</span>
                            <span
                                v-if="eventProps(arg).kind === 'slot'"
                                class="text-muted-foreground"
                                >{{
                                    eventSession(arg).daily_session?.name
                                }}</span
                            >
                            <span class="text-muted-foreground"
                                >with
                                {{
                                    eventSession(arg).clinic_user?.user?.name
                                }}</span
                            >
                            <span
                                v-if="eventPatients(arg).length > 1"
                                class="text-muted-foreground"
                                >·
                                {{ eventPatients(arg).length }} patients</span
                            >
                        </div>
                        <!-- Day / week grid: show as much as the block's height allows. -->
                        <div
                            v-else-if="eventMinutes(arg) < 45"
                            class="flex h-full min-w-0 items-center gap-1 overflow-hidden px-1.5 text-xs leading-snug"
                            :title="
                                isDailySlot(arg) ? undefined : eventTooltip(arg)
                            "
                        >
                            <DailySessionBadge
                                v-if="isDailySlot(arg)"
                                :name="
                                    eventSession(arg).daily_session?.name ?? ''
                                "
                                :holder="
                                    eventSession(arg).clinic_user?.user?.name
                                "
                            />
                            <span class="min-w-0 truncate font-semibold">{{
                                arg.event.title
                            }}</span>
                            <span class="shrink-0 opacity-80">{{
                                eventStart(arg)
                            }}</span>
                        </div>
                        <div
                            v-else
                            class="flex h-full min-w-0 flex-col overflow-hidden px-1.5 py-1 text-xs leading-snug"
                            :title="
                                isDailySlot(arg) ? undefined : eventTooltip(arg)
                            "
                        >
                            <span class="flex min-w-0 items-center gap-1">
                                <DailySessionBadge
                                    v-if="isDailySlot(arg)"
                                    :name="
                                        eventSession(arg).daily_session?.name ??
                                        ''
                                    "
                                    :holder="
                                        eventSession(arg).clinic_user?.user
                                            ?.name
                                    "
                                />
                                <span class="truncate font-semibold">{{
                                    arg.event.title
                                }}</span>
                            </span>
                            <span class="truncate opacity-80">{{
                                arg.timeText
                            }}</span>
                            <span class="truncate opacity-80">{{
                                eventProps(arg).kind === 'slot'
                                    ? eventSession(arg).daily_session?.name
                                    : eventSession(arg).clinic_user?.user?.name
                            }}</span>
                            <div
                                v-if="
                                    eventMinutes(arg) >= 90 &&
                                    eventPatients(arg).length
                                "
                                class="mt-auto flex gap-0.5 overflow-hidden pt-1"
                            >
                                <span
                                    v-for="name in eventPatients(arg).slice(
                                        0,
                                        3,
                                    )"
                                    :key="name"
                                    class="flex size-5 shrink-0 items-center justify-center rounded-full bg-(--member-color) text-[9px] font-semibold text-white"
                                >
                                    {{ getInitials(name) }}
                                </span>
                                <span
                                    v-if="eventPatients(arg).length > 3"
                                    class="flex h-5 shrink-0 items-center justify-center rounded-full bg-background/70 px-1.5 text-[9px] font-medium"
                                >
                                    +{{ eventPatients(arg).length - 3 }}
                                </span>
                            </div>
                        </div>
                    </template>
                </SessionCalendar>
                <div v-else class="grid h-full grid-cols-7 gap-2 p-2">
                    <Skeleton v-for="day in 7" :key="day" class="h-full" />
                </div>
            </div>
        </section>
    </div>

    <SessionFormDialog
        v-model:open="formOpen"
        :members="members.length ? members : legendMembers"
        :session="editingSession"
        :range="formRange"
        @saved="onSaved"
    />
    <SessionSheet
        v-model:open="sheetOpen"
        :session-id="sheetSessionId"
        :catalog="catalog"
        @changed="loadSessions"
        @edit="openEdit"
    />
    <DailySessionSheet
        v-model:open="dailySheetOpen"
        :session-id="dailySheetSessionId"
        :catalog="catalog"
        @changed="loadSessions"
    />
    <CalendarFilterDialog
        v-model:open="filterDialogOpen"
        :filter="editingFilter"
        :members="legendMembers"
        :known-patients="knownPatients"
        @saved="onFilterSaved"
        @delete="(filter) => (deletingFilter = filter)"
    />
    <ConfirmDialog
        :open="deletingFilter !== null"
        :title="`Delete the filter “${deletingFilter?.name}”?`"
        description="Only your saved filter is deleted; no sessions change."
        :processing="deletingFilterBusy"
        @update:open="(value) => !value && (deletingFilter = null)"
        @confirm="deleteFilter"
    />
</template>
