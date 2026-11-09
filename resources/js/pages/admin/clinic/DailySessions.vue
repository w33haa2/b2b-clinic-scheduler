<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarDays,
    Clock,
    Pencil,
    Plus,
    Repeat,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import DailySessionWizard from '@/components/schedule/DailySessionWizard.vue';
import DayPlanner from '@/components/schedule/DayPlanner.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError, fetchAllPages } from '@/lib/api';
import {
    clinicParts,
    formatClinicDate,
    formatWallTime,
    fromClinicTime,
} from '@/lib/clinicTime';
import { formatDate, formatWeekdays } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dailySessions as dailySessionsPage } from '@/routes/admin/clinics';
import * as dailySessionRoutes from '@/routes/clinic/daily-sessions';
import { index as lineItemsIndex } from '@/routes/clinic/line-items';
import { index as membersIndex } from '@/routes/clinic/members';
import { index as sessionsIndex } from '@/routes/clinic/sessions';
import type {
    ClinicMember,
    ClinicSession,
    DailySession,
    LineItem,
    UpcomingDay,
    Wrapped,
} from '@/types';

/**
 * Daily sessions: each staff member holds at most one, kept up to date with
 * the wizard instead of creating more. Views: the list (with an indicator
 * of who already has one), the wizard (create or update) and the day planner
 * (plot a day's patients by drag and drop). The view is kept in the URL
 * (`?session=`, `?edit=`, `?new=1`) so the calendar can link here.
 */
const { clinicId, can, context } = useClinic();
const timezone = computed(() => context.value.clinic.timezone);

setLayoutProps({
    breadcrumbs: [
        { title: 'Daily sessions', href: dailySessionsPage(clinicId.value) },
    ],
});

type View =
    | { name: 'list' }
    | { name: 'create' }
    | { name: 'edit'; id: number; step: number }
    | { name: 'plan'; id: number };

const view = ref<View>({ name: 'list' });
const loading = ref(true);
const sessions = ref<DailySession[]>([]);
const members = ref<ClinicMember[]>([]);
const catalog = ref<LineItem[]>([]);
const todays = ref<ClinicSession[]>([]);

// The open daily session (with enrollments) and its days.
const current = ref<DailySession | null>(null);
const upcomingDays = ref<UpcomingDay[]>([]);
/** The day picked in the planner ("YYYY-MM-DD", clinic time); today by default. */
const selectedDate = ref(clinicParts(new Date(), timezone.value).date);
const deleteOpen = ref(false);
const deleting = ref(false);

const takenHolderIds = computed(() =>
    sessions.value.map((session) => session.clinic_user?.id ?? 0),
);
const mine = computed(() =>
    sessions.value.find(
        (session) => session.clinic_user?.id === context.value.member.id,
    ),
);
const staffWithout = computed(() =>
    members.value.filter((member) => !takenHolderIds.value.includes(member.id)),
);
const canCreate = computed(
    () =>
        can('clinic.daily-sessions.store') &&
        (members.value.length === 0 || staffWithout.value.length > 0),
);
const ordered = computed(() =>
    [...sessions.value].sort(
        (a, b) =>
            Number(b.id === mine.value?.id) - Number(a.id === mine.value?.id) ||
            a.name.localeCompare(b.name),
    ),
);
/** Today's day of the open daily session, while it can still change. */
const todayDayId = computed(() => {
    const today = clinicParts(new Date(), timezone.value).date;
    const day = upcomingDays.value.find(
        (candidate) => candidate.date === today,
    );

    return day && (day.status === 'PENDING' || day.status === 'ONGOING')
        ? day.clinic_session_id
        : null;
});
const todayDate = computed(() => clinicParts(new Date(), timezone.value).date);
const dayId = computed(
    () =>
        upcomingDays.value.find((day) => day.date === selectedDate.value)
            ?.clinic_session_id ?? null,
);
const scheduledDays = computed(() =>
    upcomingDays.value.filter((day) => day.clinic_session_id !== null),
);

function todayOf(session: DailySession): ClinicSession | undefined {
    return todays.value.find((day) => day.daily_session?.id === session.id);
}

function isEnded(session: DailySession): boolean {
    const today = clinicParts(new Date(), timezone.value).date;

    return session.ends_on !== null && session.ends_on < today;
}

function progress(day: ClinicSession) {
    const dayMembers = day.members ?? [];

    return {
        total: dayMembers.length,
        plotted: dayMembers.filter((member) => member.starts_at).length,
        paid: dayMembers.filter((member) => member.payment_status === 'DONE')
            .length,
    };
}

// Loading
async function loadList(): Promise<void> {
    const today = clinicParts(new Date(), timezone.value).date;
    const [list, days] = await Promise.all([
        fetchAllPages<DailySession>((page) =>
            dailySessionRoutes.index.url(clinicId.value, {
                query: { per_page: 100, page },
            }),
        ),
        can('clinic.sessions.index')
            ? fetchAllPages<ClinicSession>((page) =>
                  sessionsIndex.url(clinicId.value, {
                      query: {
                          type: 'daily',
                          from: fromClinicTime(today, '00:00', timezone.value),
                          to: fromClinicTime(today, '23:59', timezone.value),
                          per_page: 100,
                          page,
                      },
                  }),
              )
            : Promise.resolve([]),
    ]);

    sessions.value = list;
    todays.value = days.filter((day) => day.occurs_on === today);
}

async function loadCurrent(id: number): Promise<void> {
    current.value = null;
    const response = await api.get<
        Wrapped<DailySession> & { meta: { upcoming_days: UpcomingDay[] } }
    >(
        dailySessionRoutes.show.url({
            clinic: clinicId.value,
            dailySession: id,
        }),
    );
    current.value = response.data;
    upcomingDays.value = response.meta.upcoming_days;

    selectedDate.value = todayDate.value;
}

// Navigation between views (kept in the URL)
function go(next: View): void {
    view.value = next;

    const query = new URLSearchParams();

    if (next.name === 'plan') {
        query.set('session', String(next.id));
    } else if (next.name === 'edit') {
        query.set('edit', String(next.id));
    } else if (next.name === 'create') {
        query.set('new', '1');
    }

    const search = query.toString();
    window.history.replaceState(
        window.history.state,
        '',
        `${window.location.pathname}${search ? `?${search}` : ''}`,
    );

    if (next.name === 'plan' || next.name === 'edit') {
        void loadCurrent(next.id);
    }

    window.scrollTo({ top: 0 });
}

function onSaved(saved: DailySession): void {
    toast.success(
        view.value.name === 'create'
            ? 'Daily session created. Its days are on the calendar.'
            : 'Daily session updated. Upcoming days were re-planned.',
    );
    void loadList();
    go({ name: 'plan', id: saved.id });
}

async function deleteCurrent(): Promise<void> {
    if (!current.value) {
        return;
    }

    deleting.value = true;

    try {
        await api.delete(
            dailySessionRoutes.destroy.url({
                clinic: clinicId.value,
                dailySession: current.value.id,
            }),
        );
        toast.success('Daily session deleted.');
        deleteOpen.value = false;
        await loadList();
        go({ name: 'list' });
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not delete the daily session.',
        );
    } finally {
        deleting.value = false;
    }
}

onMounted(async () => {
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
        loadList(),
    ]);

    members.value =
        loadedMembers.status === 'fulfilled' ? loadedMembers.value : [];
    catalog.value =
        loadedCatalog.status === 'fulfilled' ? loadedCatalog.value : [];
    loading.value = false;

    const query = new URLSearchParams(window.location.search);
    const open = Number(query.get('session') ?? query.get('edit'));

    if (open && sessions.value.some((session) => session.id === open)) {
        go(
            query.has('edit')
                ? { name: 'edit', id: open, step: 0 }
                : { name: 'plan', id: open },
        );
    } else if (query.has('new') && canCreate.value) {
        go({ name: 'create' });
    }
});

/**
 * The days to pick from: today first (even when the session does not run
 * today, so it is clear why there is nothing to plot), then every
 * scheduled day.
 */
const dayChoices = computed(() => [
    ...(scheduledDays.value.some((day) => day.date === todayDate.value)
        ? []
        : [
              {
                  date: todayDate.value,
                  label: 'Today',
                  runs: false,
                  cancelled: false,
              },
          ]),
    ...scheduledDays.value.map((day) => ({
        date: day.date,
        label:
            day.date === todayDate.value
                ? 'Today'
                : formatClinicDate(day.starts_at, timezone.value),
        runs: true,
        cancelled: day.status === 'CANCELLED',
    })),
]);
const nextDay = computed(() =>
    scheduledDays.value.find((day) => day.date > todayDate.value),
);

/** Why the session has no day today, in a sentence. */
const whyNotToday = computed(() => {
    const session = current.value;

    if (!session) {
        return '';
    }

    const weekday = new Date(`${todayDate.value}T12:00:00Z`).toLocaleDateString(
        undefined,
        {
            weekday: 'long',
            timeZone: 'UTC',
        },
    );

    if (session.starts_on > todayDate.value) {
        return `${session.name} starts on ${formatDate(session.starts_on)}.`;
    }

    if (session.ends_on !== null && session.ends_on < todayDate.value) {
        return `${session.name} ended on ${formatDate(session.ends_on)}.`;
    }

    return `${session.name} doesn't run on ${weekday}s. It repeats ${formatWeekdays(session.weekdays)}.`;
});
</script>

<template>
    <Head title="Daily sessions" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <!-- List -->
        <template v-if="view.name === 'list'">
            <PageHeader
                title="Daily sessions"
                description="Sessions that repeat on set days. Each staff member has one; update it whenever the plan changes, and plot each day here or from the calendar."
            >
                <template #actions>
                    <Button
                        v-if="can('clinic.daily-sessions.store')"
                        :disabled="!canCreate"
                        @click="go({ name: 'create' })"
                    >
                        <Plus />
                        New daily session
                    </Button>
                </template>
            </PageHeader>

            <!-- Whether the current member already has one -->
            <div
                v-if="!loading && mine"
                class="animate__animated animate__fadeIn flex flex-wrap items-center gap-3 rounded-xl border border-teal-600/30 bg-teal-50 p-4 dark:bg-teal-500/10"
                style="--animate-duration: 0.3s"
            >
                <Repeat class="size-5 text-teal-700 dark:text-teal-300" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium">
                        You already have a daily session: {{ mine.name }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Update it when something changes instead of creating
                        another.
                    </p>
                </div>
                <Button size="sm" @click="go({ name: 'plan', id: mine.id })">
                    <CalendarDays />
                    Plot today
                </Button>
                <Button
                    v-if="can('clinic.daily-sessions.update')"
                    size="sm"
                    variant="outline"
                    @click="go({ name: 'edit', id: mine.id, step: 0 })"
                >
                    <Pencil />
                    Update
                </Button>
            </div>
            <div
                v-else-if="
                    !loading &&
                    canCreate &&
                    staffWithout.some(
                        (member) => member.id === context.member.id,
                    )
                "
                class="flex flex-wrap items-center gap-3 rounded-xl border border-dashed p-4"
            >
                <Repeat class="size-5 text-muted-foreground" />
                <p class="min-w-0 flex-1 text-sm text-muted-foreground">
                    You don't have a daily session yet.
                </p>
                <Button
                    size="sm"
                    variant="outline"
                    @click="go({ name: 'create' })"
                >
                    Create yours
                </Button>
            </div>
            <p
                v-if="
                    !loading && can('clinic.daily-sessions.store') && !canCreate
                "
                class="text-sm text-muted-foreground"
            >
                Every staff member already has a daily session.
            </p>

            <div
                v-if="loading"
                class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
            >
                <Skeleton v-for="card in 3" :key="card" class="h-44" />
            </div>
            <EmptyState
                v-else-if="sessions.length === 0"
                title="No daily sessions yet"
                description="Create one for a staff member who sees the same patients every day."
            />
            <div
                v-else
                class="animate__animated animate__fadeInUp grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                style="--animate-duration: 0.4s"
            >
                <Card
                    v-for="session in ordered"
                    :key="session.id"
                    :class="
                        cn(
                            'gap-3 p-4',
                            session.id === mine?.id &&
                                'ring-2 ring-teal-600/40',
                            isEnded(session) && 'opacity-70',
                        )
                    "
                >
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">
                                {{ session.name }}
                            </p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{
                                    session.clinic_user?.user?.name ??
                                    'Former staff'
                                }}
                                <span
                                    v-if="session.id === mine?.id"
                                    class="ml-1 rounded-full bg-teal-100 px-1.5 py-0.5 text-xs text-teal-800 dark:bg-teal-500/15 dark:text-teal-300"
                                    >You</span
                                >
                            </p>
                        </div>
                        <span
                            v-if="isEnded(session)"
                            class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                            >Ended</span
                        >
                    </div>
                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-2 gap-y-1 text-sm"
                    >
                        <dt><Clock class="size-4 text-muted-foreground" /></dt>
                        <dd class="tabular-nums">
                            {{ formatWallTime(session.start_time) }} –
                            {{ formatWallTime(session.end_time) }}
                            <span class="text-muted-foreground">
                                · {{ formatWeekdays(session.weekdays) }}</span
                            >
                        </dd>
                        <dt><Users class="size-4 text-muted-foreground" /></dt>
                        <dd>
                            {{ session.patients_count ?? 0 }} patients
                            <span
                                v-if="session.ends_on"
                                class="text-muted-foreground"
                            >
                                · until {{ formatDate(session.ends_on) }}</span
                            >
                        </dd>
                    </dl>
                    <div
                        v-if="todayOf(session)"
                        class="rounded-lg bg-muted/50 px-3 py-2 text-xs"
                    >
                        Today:
                        <span class="font-medium tabular-nums"
                            >{{ progress(todayOf(session)!).plotted }}/{{
                                progress(todayOf(session)!).total
                            }}</span
                        >
                        plotted ·
                        <span class="font-medium tabular-nums"
                            >{{ progress(todayOf(session)!).paid }}/{{
                                progress(todayOf(session)!).total
                            }}</span
                        >
                        paid
                    </div>
                    <p v-else class="text-xs text-muted-foreground">
                        Not running today.
                    </p>
                    <div class="mt-auto flex gap-2">
                        <Button
                            size="sm"
                            @click="go({ name: 'plan', id: session.id })"
                        >
                            <CalendarDays />
                            Plot days
                        </Button>
                        <Button
                            v-if="can('clinic.daily-sessions.update')"
                            size="sm"
                            variant="outline"
                            @click="
                                go({ name: 'edit', id: session.id, step: 0 })
                            "
                        >
                            <Pencil />
                            Update
                        </Button>
                    </div>
                </Card>
            </div>
        </template>

        <!-- Wizard: create or update -->
        <template v-else-if="view.name === 'create' || view.name === 'edit'">
            <div class="flex items-center gap-2">
                <Button variant="ghost" size="sm" @click="go({ name: 'list' })">
                    <ArrowLeft />
                    All daily sessions
                </Button>
            </div>
            <PageHeader
                :title="
                    view.name === 'create'
                        ? 'New daily session'
                        : `Update ${current?.name ?? 'daily session'}`
                "
                :description="`All times are clinic time (${timezone}).`"
            />
            <Skeleton v-if="view.name === 'edit' && !current" class="h-96" />
            <DailySessionWizard
                v-else
                :key="view.name === 'edit' ? `edit-${view.id}` : 'create'"
                :members="members"
                :catalog="catalog"
                :daily-session="view.name === 'edit' ? current : null"
                :taken-holder-ids="
                    takenHolderIds.filter(
                        (id) =>
                            view.name !== 'edit' ||
                            id !== current?.clinic_user?.id,
                    )
                "
                :initial-step="view.name === 'edit' ? view.step : 0"
                :today-day-id="view.name === 'edit' ? todayDayId : null"
                @saved="onSaved"
                @cancel="go({ name: 'list' })"
            />
        </template>

        <!-- Day planner -->
        <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="ghost" size="sm" @click="go({ name: 'list' })">
                    <ArrowLeft />
                    All daily sessions
                </Button>
            </div>
            <Skeleton v-if="!current" class="h-[32rem]" />
            <template v-else>
                <PageHeader
                    :title="current.name"
                    :description="`${current.clinic_user?.user?.name ?? 'Former staff'} · ${formatWeekdays(current.weekdays)}, ${formatWallTime(current.start_time)} – ${formatWallTime(current.end_time)} (clinic time)`"
                >
                    <template #actions>
                        <Button
                            v-if="can('clinic.daily-sessions.update')"
                            variant="outline"
                            @click="
                                go({ name: 'edit', id: current.id, step: 2 })
                            "
                        >
                            <Pencil />
                            Update regular plan
                        </Button>
                        <Button
                            v-if="can('clinic.daily-sessions.destroy')"
                            variant="ghost"
                            class="text-destructive"
                            @click="deleteOpen = true"
                        >
                            <Trash2 />
                            Delete
                        </Button>
                    </template>
                </PageHeader>

                <!-- Pick a day -->
                <div
                    class="flex gap-2 overflow-x-auto pb-1"
                    role="tablist"
                    aria-label="Day"
                >
                    <button
                        v-for="day in dayChoices"
                        :key="day.date"
                        type="button"
                        role="tab"
                        :aria-selected="selectedDate === day.date"
                        :class="
                            cn(
                                'shrink-0 rounded-lg border px-3 py-1.5 text-sm transition-colors',
                                selectedDate === day.date
                                    ? 'border-teal-600 bg-teal-600 text-white'
                                    : 'bg-card hover:bg-accent',
                                !day.runs &&
                                    selectedDate !== day.date &&
                                    'border-dashed text-muted-foreground',
                                day.cancelled && 'line-through opacity-60',
                            )
                        "
                        @click="selectedDate = day.date"
                    >
                        {{ day.label }}
                        <span v-if="!day.runs" class="text-xs opacity-80"
                            >· not running</span
                        >
                    </button>
                    <p
                        v-if="scheduledDays.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No upcoming days are scheduled. Check the start date,
                        end date and repeat days.
                    </p>
                </div>

                <Card
                    v-if="!dayId && selectedDate === todayDate"
                    class="items-start gap-3 p-6"
                >
                    <p class="font-medium">Not running today</p>
                    <p class="text-sm text-muted-foreground">
                        {{ whyNotToday }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="nextDay"
                            size="sm"
                            @click="selectedDate = nextDay.date"
                        >
                            <CalendarDays />
                            Go to
                            {{ formatClinicDate(nextDay.starts_at, timezone) }}
                        </Button>
                        <Button
                            v-if="can('clinic.daily-sessions.update')"
                            size="sm"
                            variant="outline"
                            @click="
                                go({ name: 'edit', id: current.id, step: 0 })
                            "
                        >
                            <Pencil />
                            Change repeat days
                        </Button>
                    </div>
                </Card>
                <Card v-else-if="dayId" class="p-4">
                    <DayPlanner
                        :session-id="dayId"
                        :catalog="catalog"
                        @changed="loadList"
                    />
                </Card>

                <p class="text-xs text-muted-foreground">
                    Changes to a day are for that day only. To change who comes,
                    their services or their usual slots for every day, use
                    Update regular plan.
                </p>
            </template>
        </template>
    </div>

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Delete this daily session?"
        description="Every day and unpaid bill is deleted. If anyone has paid for a day, set an end date instead (Update → Ends on)."
        :processing="deleting"
        @confirm="deleteCurrent"
    />
</template>
