<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarClock,
    Check,
    ChevronDown,
    Lock,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import FrontDeskStoryboard from '@/components/landing/FrontDeskStoryboard.vue';
import HeroSchedule from '@/components/landing/HeroSchedule.vue';
import TourActivityLog from '@/components/landing/TourActivityLog.vue';
import TourBilling from '@/components/landing/TourBilling.vue';
import TourDailyPlanner from '@/components/landing/TourDailyPlanner.vue';
import TourInvoice from '@/components/landing/TourInvoice.vue';
import TourPatients from '@/components/landing/TourPatients.vue';
import TourRoles from '@/components/landing/TourRoles.vue';
import TourSchedule from '@/components/landing/TourSchedule.vue';
import { Button } from '@/components/ui/button';
import { useParallax, useScrollProgress } from '@/composables/useParallax';
import { cn } from '@/lib/utils';
import { dashboard, login } from '@/routes';

type Props = {
    contactEmail: string;
    permissionCount: number;
    faqs: Array<{ question: string; answer: string }>;
};

const props = defineProps<Props>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const demoHref = computed(
    () =>
        `mailto:${props.contactEmail}?subject=${encodeURIComponent(`${page.props.name} demo`)}`,
);

// Section refs for scroll-linked parallax and storyboard progression
const daySectionRef = ref<HTMLElement | null>(null);
const dailySectionRef = ref<HTMLElement | null>(null);
const productSectionRef = ref<HTMLElement | null>(null);
const securitySectionRef = ref<HTMLElement | null>(null);

// Section-scoped parallax styles
const dayStageParallax = useParallax(-0.045, daySectionRef, 36);
const dailyMainParallax = useParallax(-0.045, dailySectionRef, 36);
const dailyBadgeParallax = useParallax(-0.1, dailySectionRef, 52);
const productStageParallax = useParallax(-0.035, productSectionRef, 28);
const securityLogParallax = useParallax(-0.05, securitySectionRef, 40);
const securityBadgeParallax = useParallax(-0.1, securitySectionRef, 54);

// Scroll-driven + clickable "A day at the front desk" storyboard
const dayMoments = [
    {
        time: '07:45',
        tag: 'Morning triage',
        title: 'Every practitioner side by side, nobody double-booked.',
        text: 'Reception opens the day view and plots waiting daily-session patients into free morning slots with one click.',
    },
    {
        time: '09:00',
        tag: 'Group session',
        title: 'Group physio starts with three patients in one block.',
        text: 'Marked in progress with a click. The practitioner’s 09:00–10:30 window is automatically locked against overlapping bookings.',
    },
    {
        time: '10:32',
        tag: 'Per-patient billing',
        title: 'Each patient is billed on their own, at today’s catalogue price.',
        text: 'Unit prices are copied onto each patient’s bill the moment a service is added, so future price updates never rewrite history.',
    },
    {
        time: '10:35',
        tag: 'Permanent ledger',
        title: 'Marco pays at the desk. The payment lands in an append-only ledger.',
        text: 'Marking his bill paid writes inbound transactions per item that no staff member or admin can ever edit or erase.',
    },
    {
        time: '16:50',
        tag: 'Statement & PDF',
        title: 'Invoice #000214 goes out for his week, ready to print or download.',
        text: 'Only paid, uninvoiced items are offered, preventing duplicate billing across sessions.',
    },
];

const dayProgress = useScrollProgress(daySectionRef);
const manualDayStep = ref<number | null>(null);

const scrollDayStep = computed(() => {
    const idx = Math.floor(dayProgress.value * dayMoments.length);
    return Math.max(0, Math.min(dayMoments.length - 1, idx));
});

// When the user scrolls to a new step, release manual override so scroll storytelling feels natural
watch(scrollDayStep, (newStep) => {
    if (manualDayStep.value !== null && manualDayStep.value !== newStep) {
        manualDayStep.value = null;
    }
});

const activeDayStep = computed(
    () => manualDayStep.value ?? scrollDayStep.value,
);

type Tab = {
    key: string;
    title: string;
    summary: string;
    points: string[];
    mock: Component;
};

const tabs: Tab[] = [
    {
        key: 'schedule',
        title: 'Schedule',
        summary:
            'A shared calendar for the whole team, by day, week, month or agenda.',
        points: [
            'Drag across the grid to book; drag a session or its edge to reschedule.',
            'Each practitioner has a colour, plus saved custom filters for status and payment.',
            'One practitioner can’t be in two sessions at once. The schedule refuses.',
        ],
        mock: TourSchedule,
    },
    {
        key: 'daily',
        title: 'Daily sessions',
        summary:
            'Recurring daily rosters with a usual plan and one-day drag-and-drop plotting.',
        points: [
            'Days schedule themselves 14 days ahead and advance from Scheduled to Done automatically.',
            'Drag waiting patients onto the timeline or tap “Fill free time” to place everyone.',
            'Move a patient or swap their service for today only (amber); tomorrow follows the regular plan.',
        ],
        mock: TourDailyPlanner,
    },
    {
        key: 'patients',
        title: 'Patients',
        summary:
            'One list of everyone your clinic sees, searchable as you type.',
        points: [
            'Contact details, birth date, age and clinical notes in one place.',
            'Add a new patient while booking a session, without leaving the calendar.',
            'Archived patients disappear from pickers while keeping their billing history intact.',
        ],
        mock: TourPatients,
    },
    {
        key: 'billing',
        title: 'Billing & prepayments',
        summary:
            'Bill each patient in a session separately, record payments, or prepay upcoming days.',
        points: [
            'Group sessions bill every patient on their own with independent payment states.',
            'Prepay 5, 10 or 20 upcoming daily-session days in one transaction.',
            'Every payment and refund is written to an append-only ledger.',
        ],
        mock: TourBilling,
    },
    {
        key: 'invoices',
        title: 'Invoices & PDF',
        summary:
            'Pick a patient, tick their paid services, and download the PDF.',
        points: [
            'Only paid services not already on an invoice are offered.',
            'Sequential numbers per clinic from the first statement onward.',
            'Delete an invoice and its services become available to invoice again.',
        ],
        mock: TourInvoice,
    },
    {
        key: 'roles',
        title: 'Roles & access',
        summary: 'Decide exactly what each role can do, action by action.',
        points: [
            'Build roles like “Front desk” or “Cashier” from individual permissions.',
            'Staff and role management always stays with Clinic Admins.',
            'A clinic can never lose or demote its last admin.',
        ],
        mock: TourRoles,
    },
];

const activeTab = ref(tabs[0].key);
const active = computed(
    () => tabs.find((tab) => tab.key === activeTab.value) ?? tabs[0],
);

const clinicTypes = [
    {
        name: 'Therapy & counselling',
        text: 'Run 1-to-1 sessions alongside group workshops where every client gets their own bill and statement.',
        tag: 'Per-patient group billing',
        setup: 'Group & 1-to-1 sessions · Privacy-redacted notes',
    },
    {
        name: 'Physiotherapy & rehab',
        text: 'Keep recurring daily rehab rosters on a regular plan while moving slots around on busy mornings.',
        tag: 'Daily session planner',
        setup: 'Usual slots · Multi-day prepayments',
    },
    {
        name: 'Dental practices',
        text: 'Price treatments from your clinic catalogue with the unit price locked the moment it is billed.',
        tag: 'Historical price lock',
        setup: 'Service catalogue · Instant PDF statements',
    },
    {
        name: 'GP & multi-site clinics',
        text: 'Practitioners who split their week across branches sign in once and switch clinics from the sidebar.',
        tag: 'Multi-clinic switcher',
        setup: 'Per-clinic timezones · Isolated records',
    },
    {
        name: 'Wellness & allied health',
        text: 'Give reception the schedule, daily plotting and payments while keeping catalogue pricing and roles with owners.',
        tag: 'Role permissions',
        setup: 'Custom front-desk roles · Full activity trail',
    },
];

const security = [
    {
        title: 'Clinics are walled off from each other.',
        text: 'Every query is scoped to the active clinic, and non-members receive a 404 so another clinic’s existence cannot even be detected.',
    },
    {
        title: 'Staff only see what their role allows.',
        text: 'Permissions map directly to actions, and only Clinic Admins can manage staff members or edit role permissions.',
    },
    {
        title: 'Money has an uneditable paper trail.',
        text: 'Payments and refunds append inbound and reversed entries to a transaction ledger that has no edit or delete path.',
    },
    {
        title: 'Personal patient details are redacted in logs.',
        text: 'The activity log records who changed what and when, while birth dates, phones, emails and notes are masked for privacy.',
    },
    {
        title: 'Nothing with billing history is erased.',
        text: 'Patients, staff and catalogue services are archived instead of hard-deleted, keeping past bills and invoices intact.',
    },
];
</script>

<template>
    <Head title="Scheduling and billing software for clinics" />

    <!-- HERO: Multi-Plane Parallax & Interactive Schedule -->
    <section class="relative overflow-hidden border-b">
        <div
            class="mx-auto max-w-6xl px-4 pt-12 pb-24 md:px-8 lg:pt-20 lg:pb-32"
        >
            <div
                class="grid items-start gap-14 lg:grid-cols-[0.95fr_1.2fr] lg:gap-12"
            >
                <div class="lg:pt-2">
                    <p
                        class="animate__animated animate__revealUp text-sm font-medium text-primary"
                    >
                        Practice software for therapy, physio, dental and GP
                        clinics
                    </p>
                    <h1
                        class="animate__animated animate__revealUp mt-4 font-display text-display font-normal text-balance"
                        style="animation-delay: 60ms"
                    >
                        From the first booking to the
                        <em class="font-normal text-primary italic"
                            >paid invoice.</em
                        >
                    </h1>
                    <p
                        class="animate__animated animate__revealUp mt-6 max-w-lg text-lg leading-relaxed text-pretty text-muted-foreground"
                        style="animation-delay: 120ms"
                    >
                        {{ page.props.name }} gives your clinic one shared
                        calendar, a drag-and-drop planner for recurring daily
                        sessions, and per-patient billing with an uneditable
                        ledger—so the day runs on the schedule instead of sticky
                        notes.
                    </p>

                    <div
                        class="animate__animated animate__revealUp mt-8 flex flex-wrap items-center gap-x-6 gap-y-3"
                        style="animation-delay: 180ms"
                    >
                        <Button size="lg" class="h-11 px-5" as-child>
                            <a :href="demoHref">Book a demo</a>
                        </Button>
                        <Link
                            :href="user ? dashboard() : login()"
                            class="group inline-flex items-center gap-1.5 text-sm font-medium"
                        >
                            {{ user ? 'Open your clinic' : 'Staff log in' }}
                            <ArrowRight
                                class="size-4 transition-transform group-hover:translate-x-0.5"
                            />
                        </Link>
                    </div>

                    <!-- Humanized highlights row -->
                    <div
                        class="animate__animated animate__revealUp mt-10 grid grid-cols-3 gap-4 border-t pt-6 text-xs"
                        style="animation-delay: 240ms"
                    >
                        <div>
                            <div class="font-semibold text-foreground">
                                No double-bookings
                            </div>
                            <p class="mt-1 text-muted-foreground">
                                Refuses overlaps across regular and daily slots.
                            </p>
                        </div>
                        <div class="border-l pl-4">
                            <div class="font-semibold text-foreground">
                                Locked price history
                            </div>
                            <p class="mt-1 text-muted-foreground">
                                Catalogue edits never alter past patient bills.
                            </p>
                        </div>
                        <div class="border-l pl-4">
                            <div class="font-semibold text-foreground">
                                Guided onboarding
                            </div>
                            <p class="mt-1 text-muted-foreground">
                                We load your staff, services and patients with
                                you.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Multi-Plane Parallax Schedule Stage -->
                <div
                    class="animate__animated animate__revealUp lg:-mr-16"
                    style="animation-delay: 180ms"
                >
                    <HeroSchedule />
                </div>
            </div>
        </div>
    </section>

    <!-- A DAY AT THE FRONT DESK: Scroll-Linked & Interactive Storyboard -->
    <section
        id="day"
        ref="daySectionRef"
        aria-labelledby="day-title"
        class="relative border-b"
    >
        <div class="mx-auto max-w-6xl px-4 py-24 md:px-8 md:py-32">
            <div
                class="grid items-start gap-12 lg:grid-cols-[0.95fr_1.15fr] lg:gap-16"
            >
                <div>
                    <p class="text-sm font-medium text-primary">
                        A day at the front desk
                    </p>
                    <h2
                        id="day-title"
                        class="mt-3 font-display text-section font-normal text-balance"
                    >
                        What happens
                        <em class="font-normal italic">between</em>
                        appointments.
                    </h2>
                    <p
                        class="mt-4 max-w-md leading-relaxed text-muted-foreground"
                    >
                        Most of a clinic’s admin happens in the gaps: a morning
                        roster needs plotting, a group session runs with three
                        patients, someone pays on the way out.
                        {{ page.props.name }} keeps every moment on the same
                        record.
                    </p>

                    <!-- Interactive timestamped timeline with scroll progress -->
                    <ol
                        class="relative mt-10 space-y-2 border-l border-border pl-6"
                    >
                        <li
                            v-for="(moment, index) in dayMoments"
                            :key="moment.time"
                            class="relative"
                        >
                            <span
                                class="absolute top-4 -left-[1.85rem] size-2.5 rounded-full border-2 border-background transition-all duration-300"
                                :class="
                                    activeDayStep === index
                                        ? 'scale-125 bg-primary ring-4 ring-primary/15'
                                        : index < activeDayStep
                                          ? 'bg-primary/60'
                                          : 'bg-border'
                                "
                                aria-hidden="true"
                            />
                            <button
                                type="button"
                                class="w-full rounded-xl border p-3.5 text-left transition-all duration-200"
                                :class="
                                    activeDayStep === index
                                        ? 'border-border bg-card shadow-xs'
                                        : 'border-transparent hover:bg-(--surface-sunken)/70'
                                "
                                @click="manualDayStep = index"
                            >
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <time
                                        class="text-xs font-semibold tabular-nums"
                                        :class="
                                            activeDayStep === index
                                                ? 'text-primary'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        {{ moment.time }}
                                    </time>
                                    <span
                                        class="rounded-full bg-(--surface-sunken) px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground"
                                    >
                                        {{ moment.tag }}
                                    </span>
                                </div>
                                <div
                                    class="mt-1 font-medium transition-colors"
                                    :class="
                                        activeDayStep === index
                                            ? 'text-foreground'
                                            : 'text-foreground/75'
                                    "
                                >
                                    {{ moment.title }}
                                </div>
                                <p
                                    v-if="activeDayStep === index"
                                    class="mt-1.5 text-sm leading-relaxed text-muted-foreground"
                                >
                                    {{ moment.text }}
                                </p>
                            </button>
                        </li>
                    </ol>
                </div>

                <!-- Sticky / Parallax Live Desk Artifact -->
                <div class="lg:sticky lg:top-24 lg:pt-6">
                    <div :style="dayStageParallax">
                        <FrontDeskStoryboard :step="activeDayStep" />
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- DAILY SESSIONS & DAY PLANNER SPOTLIGHT: Multi-Plane Parallax -->
    <section
        id="daily"
        ref="dailySectionRef"
        aria-labelledby="daily-title"
        class="relative overflow-hidden border-b bg-(--surface-sunken)"
    >
        <div class="mx-auto max-w-6xl px-4 py-24 md:px-8 md:py-32">
            <div
                class="grid items-center gap-12 lg:grid-cols-[1.2fr_0.9fr] lg:gap-14"
            >
                <!-- Left Column: Layered Parallax Day Planner Stage -->
                <div v-reveal="'revealUp'" class="relative order-2 lg:order-1">
                    <div :style="dailyMainParallax">
                        <TourDailyPlanner />
                    </div>

                    <!-- Foreground Parallax Callout Card -->
                    <div
                        :style="dailyBadgeParallax"
                        class="mt-4 rounded-xl border bg-card/95 p-3.5 text-xs shadow-lg backdrop-blur-md md:absolute md:-right-5 md:-bottom-7 md:mt-0 md:w-76"
                        aria-hidden="true"
                    >
                        <div
                            class="flex items-center gap-2 font-medium text-foreground"
                        >
                            <CalendarClock class="size-4 text-amber-500" />
                            <span>One-day changes stay on that day</span>
                        </div>
                        <p class="mt-1 leading-relaxed text-muted-foreground">
                            Nudge a slot by 15 minutes, swap a service, or add a
                            walk-in for today. Tomorrow’s roster follows the
                            regular plan automatically.
                        </p>
                    </div>
                </div>

                <!-- Right Column: Editorial Explanation -->
                <div v-reveal="'revealUp'" class="order-1 lg:order-2">
                    <p class="text-sm font-medium text-primary">
                        Daily sessions &amp; day planner
                    </p>
                    <h2
                        id="daily-title"
                        class="mt-3 font-display text-section font-normal text-balance"
                    >
                        A usual plan for the week.
                        <em class="font-normal text-primary italic"
                            >Freedom to rearrange today.</em
                        >
                    </h2>
                    <p class="mt-5 leading-relaxed text-muted-foreground">
                        In physio, rehab and therapy clinics, practitioners
                        often run a daily window with an enrolled roster of
                        patients. {{ page.props.name }} gives each staff member
                        their own daily session alongside regular appointments.
                    </p>

                    <dl class="mt-8 space-y-4 border-t pt-6 text-sm">
                        <div class="flex gap-3">
                            <span
                                class="mt-1 size-2.5 shrink-0 rounded-full bg-teal-600"
                                aria-hidden="true"
                            />
                            <div>
                                <dt class="font-medium">
                                    Teal — Regular plan &amp; usual slots
                                </dt>
                                <dd class="mt-0.5 text-muted-foreground">
                                    Enrolled patients land in their usual slot
                                    automatically if the practitioner is free,
                                    or wait in “To plot” if another session took
                                    that time.
                                </dd>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <span
                                class="mt-1 size-2.5 shrink-0 rounded-full bg-amber-500"
                                aria-hidden="true"
                            />
                            <div>
                                <dt class="font-medium">
                                    Amber — Changed for today only
                                </dt>
                                <dd class="mt-0.5 text-muted-foreground">
                                    Drag a patient to a new time or tap “Fill
                                    free time” to pack remaining gaps ahead of
                                    the current time line.
                                </dd>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <span
                                class="mt-1 size-2.5 shrink-0 rounded-full bg-emerald-600"
                                aria-hidden="true"
                            />
                            <div>
                                <dt class="font-medium">
                                    Emerald — Paid or prepaid ahead
                                </dt>
                                <dd class="mt-0.5 text-muted-foreground">
                                    Patients can prepay their next 5, 10 or 20
                                    days at once, locking their payment into the
                                    ledger before the week begins.
                                </dd>
                            </div>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </section>

    <!-- WORKSPACE TOUR: Six Screens + Product Facts -->
    <section
        id="product"
        ref="productSectionRef"
        aria-labelledby="product-title"
        class="border-b"
    >
        <div class="mx-auto max-w-6xl px-4 py-24 md:px-8 md:py-32">
            <div v-reveal="'revealUp'" class="max-w-2xl">
                <p class="text-sm font-medium text-primary">Product tour</p>
                <h2
                    id="product-title"
                    class="mt-3 font-display text-section font-normal text-balance"
                >
                    Everything the front desk touches, in six screens.
                </h2>
            </div>

            <div
                v-reveal="'revealUp'"
                class="mt-12 grid items-start gap-10 lg:grid-cols-[0.85fr_1.35fr] lg:gap-14"
            >
                <div
                    role="tablist"
                    aria-label="Product areas"
                    class="flex flex-col divide-y border-y"
                >
                    <div
                        v-for="(tab, index) in tabs"
                        :key="tab.key"
                        class="py-3.5"
                    >
                        <button
                            :id="`tab-${tab.key}`"
                            type="button"
                            role="tab"
                            :aria-selected="activeTab === tab.key"
                            :aria-controls="`panel-${tab.key}`"
                            :class="
                                cn(
                                    'block w-full text-left transition-colors',
                                    activeTab === tab.key
                                        ? 'text-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                )
                            "
                            @click="activeTab = tab.key"
                        >
                            <span class="flex items-baseline gap-3">
                                <span
                                    class="text-xs tabular-nums"
                                    :class="
                                        activeTab === tab.key
                                            ? 'font-semibold text-primary'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ String(index + 1).padStart(2, '0') }}
                                </span>
                                <span class="text-base font-medium">{{
                                    tab.title
                                }}</span>
                            </span>
                            <span
                                class="mt-1 block pl-7 text-sm text-muted-foreground"
                                >{{ tab.summary }}</span
                            >
                        </button>
                        <ul
                            v-if="activeTab === tab.key"
                            class="mt-3 space-y-1.5 pl-7 text-sm"
                        >
                            <li
                                v-for="point in tab.points"
                                :key="point"
                                class="flex gap-2 text-foreground/90"
                            >
                                <Check
                                    class="mt-1 size-3.5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                                <span>{{ point }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div
                    :id="`panel-${active.key}`"
                    role="tabpanel"
                    :aria-labelledby="`tab-${active.key}`"
                    class="min-w-0 lg:sticky lg:top-24"
                >
                    <div :style="productStageParallax">
                        <Transition
                            mode="out-in"
                            enter-active-class="transition duration-300 ease-[cubic-bezier(.32,.72,0,1)]"
                            enter-from-class="opacity-0 translate-y-2"
                            leave-active-class="transition duration-150"
                            leave-to-class="opacity-0"
                        >
                            <component :is="active.mock" :key="active.key" />
                        </Transition>
                    </div>
                </div>
            </div>

            <!-- Honest Product Facts -->
            <dl
                v-reveal="'revealUp'"
                class="mt-24 grid overflow-hidden rounded-xl border bg-(--surface-sunken)/50 sm:grid-cols-2 lg:grid-cols-4"
            >
                <div class="p-6">
                    <dt class="text-xs font-medium text-muted-foreground">
                        Double bookings
                    </dt>
                    <dd class="mt-2 font-display text-5xl tabular-nums">0</dd>
                    <dd class="mt-2 text-sm text-muted-foreground">
                        A practitioner cannot be placed in two sessions or
                        overlapping daily slots at once.
                    </dd>
                </div>
                <div class="border-t p-6 sm:border-t-0 sm:border-l">
                    <dt class="text-xs font-medium text-muted-foreground">
                        Role permissions
                    </dt>
                    <dd class="mt-2 font-display text-5xl tabular-nums">
                        {{ permissionCount }}
                    </dd>
                    <dd class="mt-2 text-sm text-muted-foreground">
                        Separate permissions you can grant or hold back, role by
                        role, across your staff.
                    </dd>
                </div>
                <div class="border-t p-6 lg:border-t-0 lg:border-l">
                    <dt class="text-xs font-medium text-muted-foreground">
                        Multi-site staff
                    </dt>
                    <dd class="mt-2 font-display text-5xl tabular-nums">1</dd>
                    <dd class="mt-2 text-sm text-muted-foreground">
                        Single login for practitioners and receptionists who
                        work across several of your clinics.
                    </dd>
                </div>
                <div class="border-t p-6 sm:border-l lg:border-t-0">
                    <dt class="text-xs font-medium text-muted-foreground">
                        Daily session horizon
                    </dt>
                    <dd class="mt-2 font-display text-5xl tabular-nums">14d</dd>
                    <dd class="mt-2 text-sm text-muted-foreground">
                        Upcoming daily-session days kept scheduled ahead
                        automatically, or up to 90 days when prepaid.
                    </dd>
                </div>
            </dl>
        </div>
    </section>

    <!-- CLINIC ARCHETYPES -->
    <section
        id="clinics"
        aria-labelledby="clinics-title"
        class="border-b bg-(--surface-sunken)"
    >
        <div
            v-reveal="'revealUp'"
            class="mx-auto max-w-6xl px-4 py-24 md:px-8 md:py-32"
        >
            <div class="grid gap-8 lg:grid-cols-[0.9fr_1.4fr] lg:items-end">
                <div>
                    <p class="text-sm font-medium text-primary">Who it’s for</p>
                    <h2
                        id="clinics-title"
                        class="mt-3 font-display text-section font-normal text-balance"
                    >
                        Shaped around how your clinic runs.
                    </h2>
                </div>
                <p class="max-w-xl text-muted-foreground">
                    Whether you book one-to-one consultations, group therapy
                    circles, or recurring daily rehab blocks, the schedule and
                    billing adapt without custom spreadsheets.
                </p>
            </div>

            <ul class="mt-12 divide-y rounded-xl border bg-card">
                <li
                    v-for="type in clinicTypes"
                    :key="type.name"
                    class="grid gap-3 p-6 transition-colors hover:bg-(--surface-sunken)/50 md:grid-cols-[1.1fr_1.6fr_auto] md:items-baseline md:gap-8"
                >
                    <h3 class="font-display text-2xl tracking-tight">
                        {{ type.name }}
                    </h3>
                    <div>
                        <p class="text-foreground/90">{{ type.text }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ type.setup }}
                        </p>
                    </div>
                    <span
                        class="w-fit rounded-full border bg-(--surface-sunken) px-3 py-1 text-xs font-medium text-muted-foreground"
                    >
                        {{ type.tag }}
                    </span>
                </li>
            </ul>
        </div>
    </section>

    <!-- SECURITY, TENANT ISOLATION & REDACTED AUDIT LOG -->
    <section
        id="security"
        ref="securitySectionRef"
        aria-labelledby="security-title"
        class="relative overflow-hidden border-b"
    >
        <div class="mx-auto max-w-6xl px-4 py-24 md:px-8 md:py-32">
            <div
                class="grid items-start gap-12 lg:grid-cols-[1fr_1.15fr] lg:gap-16"
            >
                <div v-reveal="'revealUp'">
                    <p class="text-sm font-medium text-primary">
                        Security &amp; accountability
                    </p>
                    <h2
                        id="security-title"
                        class="mt-3 font-display text-section font-normal text-balance"
                    >
                        Private by design,
                        <em class="font-normal italic">clinic by clinic.</em>
                    </h2>
                    <p
                        class="mt-4 max-w-md leading-relaxed text-muted-foreground"
                    >
                        Patient and payment records deserve plain answers, not
                        badges. This is what the software enforces on every
                        request today.
                    </p>

                    <dl class="mt-8 divide-y border-y">
                        <div
                            v-for="item in security"
                            :key="item.title"
                            class="py-4"
                        >
                            <dt class="font-medium">
                                {{ item.title }}
                            </dt>
                            <dd
                                class="mt-1 text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ item.text }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Right Column: Parallax Redacted Activity Log Specimen -->
                <div v-reveal="'revealUp'" class="relative lg:pt-8">
                    <div :style="securityLogParallax">
                        <TourActivityLog />
                    </div>

                    <div
                        :style="securityBadgeParallax"
                        class="mt-4 flex items-start gap-3 rounded-xl border bg-card/95 p-3.5 text-xs shadow-lg backdrop-blur-md md:absolute md:-bottom-6 md:-left-6 md:mt-0 md:w-80"
                        aria-hidden="true"
                    >
                        <span
                            class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary/12 text-primary"
                        >
                            <Lock class="size-3.5" />
                        </span>
                        <div>
                            <div class="font-semibold">
                                Strict clinic isolation
                            </div>
                            <p class="mt-0.5 text-muted-foreground">
                                Requests outside a staff member’s clinic
                                membership return 404 before any data is read.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- WHITE-GLOVE ONBOARDING -->
    <section
        id="start"
        aria-labelledby="start-title"
        class="border-b bg-(--surface-sunken)"
    >
        <div
            v-reveal="'revealUp'"
            class="mx-auto max-w-6xl px-4 py-24 md:px-8 md:py-32"
        >
            <div class="grid gap-12 lg:grid-cols-[0.95fr_1.25fr]">
                <div>
                    <p class="text-sm font-medium text-primary">
                        Getting started
                    </p>
                    <h2
                        id="start-title"
                        class="mt-3 font-display text-section font-normal text-balance"
                    >
                        We set it up with you.
                    </h2>
                    <p class="mt-4 leading-relaxed text-muted-foreground">
                        {{ page.props.name }} is new, so instead of a wall of
                        logos we would rather show it to you running on a week
                        like yours.
                        <a
                            :href="demoHref"
                            class="font-medium text-foreground underline decoration-primary underline-offset-4"
                            >Book a demo</a
                        >
                        and bring your trickiest day.
                    </p>
                </div>

                <ol class="grid gap-4 sm:grid-cols-3">
                    <li
                        class="flex flex-col justify-between rounded-xl border bg-card p-5"
                    >
                        <div>
                            <span class="text-xs font-semibold text-primary"
                                >First</span
                            >
                            <h3 class="mt-2 font-display text-xl">
                                Clinic &amp; catalogue
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                We create your clinic in your timezone and load
                                your billable services and prices.
                            </p>
                        </div>
                        <span class="mt-6 text-xs text-muted-foreground">
                            Services &amp; prices ready
                        </span>
                    </li>
                    <li
                        class="flex flex-col justify-between rounded-xl border bg-card p-5"
                    >
                        <div>
                            <span class="text-xs font-semibold text-primary"
                                >Second</span
                            >
                            <h3 class="mt-2 font-display text-xl">
                                Staff &amp; roles
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                We add your practitioners and front desk with
                                the exact permissions and daily sessions they
                                need.
                            </p>
                        </div>
                        <span class="mt-6 text-xs text-muted-foreground">
                            Role permissions configured
                        </span>
                    </li>
                    <li
                        class="flex flex-col justify-between rounded-xl border bg-card p-5"
                    >
                        <div>
                            <span class="text-xs font-semibold text-primary"
                                >Third</span
                            >
                            <h3 class="mt-2 font-display text-xl">
                                Patient import
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                We help bring your patient list across so your
                                team signs in to a schedule that looks like
                                their week.
                            </p>
                        </div>
                        <span class="mt-6 text-xs text-muted-foreground">
                            Guided migration
                        </span>
                    </li>
                </ol>
            </div>
        </div>
    </section>

    <!-- FAQ (Server-Driven Structured Data) -->
    <section id="faq" aria-labelledby="faq-title" class="border-b">
        <div
            v-reveal="'revealUp'"
            class="mx-auto grid max-w-6xl gap-10 px-4 py-24 md:px-8 md:py-32 lg:grid-cols-[1fr_1.6fr]"
        >
            <div>
                <p class="text-sm font-medium text-primary">Questions</p>
                <h2
                    id="faq-title"
                    class="mt-3 font-display text-section font-normal"
                >
                    Things clinics ask us.
                </h2>
                <p
                    class="mt-4 max-w-xs text-sm leading-relaxed text-muted-foreground"
                >
                    Have a question about how your clinic’s workflow fits?
                    <a
                        :href="demoHref"
                        class="font-medium text-foreground underline decoration-primary underline-offset-4"
                        >Write to us directly</a
                    >.
                </p>
            </div>
            <div class="divide-y border-y">
                <details
                    v-for="faq in faqs"
                    :key="faq.question"
                    class="group py-5"
                >
                    <summary
                        class="flex cursor-pointer list-none items-start justify-between gap-6 font-medium [&::-webkit-details-marker]:hidden"
                    >
                        <h3>{{ faq.question }}</h3>
                        <ChevronDown
                            class="mt-0.5 size-5 shrink-0 text-muted-foreground transition-transform duration-300 group-open:rotate-180"
                        />
                    </summary>
                    <p
                        class="mt-3 max-w-2xl leading-relaxed text-muted-foreground"
                    >
                        {{ faq.answer }}
                    </p>
                </details>
            </div>
        </div>
    </section>

    <!-- CLOSING BAND -->
    <section
        aria-labelledby="closing-title"
        class="bg-primary text-primary-foreground"
    >
        <div
            v-reveal="'revealUp'"
            class="mx-auto flex max-w-6xl flex-col items-start gap-8 px-4 py-20 md:flex-row md:items-end md:justify-between md:px-8"
        >
            <div>
                <h2
                    id="closing-title"
                    class="font-display text-section font-normal"
                >
                    See it with your own schedule.
                </h2>
                <p class="mt-4 max-w-lg opacity-85">
                    We’ll walk through it using your services, your staff and a
                    typical week at your clinic.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <Button
                    size="lg"
                    variant="secondary"
                    class="h-11 bg-background px-5 text-foreground hover:bg-background/90"
                    as-child
                >
                    <a :href="demoHref">Book a demo</a>
                </Button>
                <Link
                    :href="user ? dashboard() : login()"
                    class="group inline-flex items-center gap-1.5 text-sm font-medium"
                >
                    {{ user ? 'Open your clinic' : 'Staff log in' }}
                    <ArrowRight
                        class="size-4 transition-transform group-hover:translate-x-0.5"
                    />
                </Link>
            </div>
        </div>
    </section>
</template>
