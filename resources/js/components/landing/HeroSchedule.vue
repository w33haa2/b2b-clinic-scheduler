<script setup lang="ts">
import { CalendarSync, CheckCircle2, ReceiptText } from '@lucide/vue';
import { ref } from 'vue';
import { useParallax } from '@/composables/useParallax';
import MockFrame from './MockFrame.vue';
import { hours, initials, practitioners, weekDays, weekEvents } from './sample';

// One hour = this many rem of grid height.
const hourHeight = 4.15;

// Interactive practitioner filter: null = show all, 0..2 = highlight practitioner
const selectedPractitioner = ref<number | null>(null);

function togglePractitioner(colorIndex: number) {
    selectedPractitioner.value =
        selectedPractitioner.value === colorIndex ? null : colorIndex;
}

// Multi-plane scroll parallax layers
const backdropPlane = useParallax(0.035);
const schedulePlane = useParallax(-0.045);
const invoicePlane = useParallax(-0.11);
const dailyToastPlane = useParallax(-0.14);
</script>

<template>
    <div class="relative">
        <!-- Plane 0: Subtle architectural grid backplate -->
        <div
            :style="backdropPlane"
            class="landing-grid pointer-events-none absolute -inset-x-6 -inset-y-6 -z-10 hidden rounded-2xl border border-dashed border-border/70 opacity-65 lg:block"
            aria-hidden="true"
        />

        <!-- Interactive practitioner filter bar above the frame -->
        <div
            class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs"
        >
            <div
                class="flex flex-wrap items-center gap-1.5"
                role="group"
                aria-label="Filter sample schedule by practitioner"
            >
                <button
                    type="button"
                    class="rounded-full border px-2.5 py-1 font-medium transition-colors"
                    :class="
                        selectedPractitioner === null
                            ? 'border-foreground bg-foreground text-background'
                            : 'bg-card/80 text-muted-foreground hover:text-foreground'
                    "
                    @click="selectedPractitioner = null"
                >
                    All staff (3)
                </button>
                <button
                    v-for="person in practitioners"
                    :key="person.name"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 transition-all"
                    :class="
                        selectedPractitioner === person.color
                            ? 'border-foreground/40 bg-card font-medium text-foreground shadow-xs'
                            : 'bg-card/60 text-muted-foreground hover:bg-card hover:text-foreground'
                    "
                    @click="togglePractitioner(person.color)"
                >
                    <span
                        class="size-2 rounded-full"
                        :style="{
                            background: `var(--member-color-${person.color})`,
                        }"
                    />
                    <span>{{ person.name }}</span>
                </button>
            </div>
            <span class="hidden text-xs text-muted-foreground sm:inline">
                Filter by practitioner
            </span>
        </div>

        <!-- Plane 1: Main Schedule Workspace Mock -->
        <div :style="schedulePlane">
            <MockFrame
                title="Schedule · Week view"
                clinic="Northside Clinic"
                label="The Clinic App week view: sessions for three practitioners, colour-coded, with one-off sessions and dotted daily-session slots."
            >
                <template #toolbar>
                    <span
                        class="hidden items-center gap-1.5 text-[11px] text-muted-foreground tabular-nums sm:inline-flex"
                    >
                        <span class="size-1.5 rounded-full bg-emerald-500" />3 –
                        7 Nov
                    </span>
                    <span
                        class="hidden rounded-md border bg-card p-0.5 text-[11px] sm:flex"
                    >
                        <span class="px-2 py-0.5 text-muted-foreground"
                            >Day</span
                        >
                        <span class="rounded bg-muted px-2 py-0.5 font-medium"
                            >Week</span
                        >
                        <span class="px-2 py-0.5 text-muted-foreground"
                            >Month</span
                        >
                    </span>
                    <span class="flex -space-x-1.5">
                        <span
                            v-for="person in practitioners"
                            :key="person.name"
                            class="flex size-5 items-center justify-center rounded-full border-2 border-card text-[8px] font-semibold text-white transition-opacity"
                            :class="
                                selectedPractitioner !== null &&
                                selectedPractitioner !== person.color &&
                                'opacity-30'
                            "
                            :style="{
                                background: `var(--member-color-${person.color})`,
                            }"
                        >
                            {{ person.initials }}
                        </span>
                    </span>
                </template>

                <div
                    class="grid grid-cols-[2.75rem_repeat(5,minmax(0,1fr))] text-[11px]"
                >
                    <div class="border-b" />
                    <div
                        v-for="day in weekDays"
                        :key="day"
                        class="flex items-center justify-between border-b border-l px-2 py-1.5 font-medium"
                        :class="
                            day === 'Tue 4' &&
                            'bg-(--surface-sunken) text-primary'
                        "
                    >
                        <span>{{ day }}</span>
                        <span
                            v-if="day === 'Tue 4'"
                            class="rounded-full bg-primary/12 px-1.5 py-0.5 text-[10px] font-medium text-primary"
                        >
                            Today
                        </span>
                    </div>

                    <div class="relative bg-(--surface-sunken)/40">
                        <div
                            v-for="hour in hours"
                            :key="hour"
                            class="pt-1 pr-1.5 text-right text-[10px] text-muted-foreground tabular-nums"
                            :style="{ height: `${hourHeight}rem` }"
                        >
                            {{ hour }}
                        </div>
                    </div>

                    <div
                        v-for="(day, dayIndex) in weekDays"
                        :key="day"
                        class="relative border-l"
                        :class="dayIndex === 1 && 'bg-(--surface-sunken)/60'"
                        :style="{ height: `${hourHeight * hours.length}rem` }"
                    >
                        <div
                            v-for="(hour, index) in hours"
                            :key="hour"
                            class="absolute inset-x-0 border-t border-dashed border-border/70"
                            :style="{ top: `${index * hourHeight}rem` }"
                        />

                        <!-- Live "now" indicator on Tuesday -->
                        <div
                            v-if="dayIndex === 1"
                            class="pointer-events-none absolute inset-x-0 z-10 flex items-center"
                            :style="{ top: `${1.65 * hourHeight}rem` }"
                        >
                            <span
                                class="-ml-1 size-2 rounded-full bg-rose-500"
                            />
                            <span class="h-[1.5px] flex-1 bg-rose-500/80" />
                        </div>

                        <div
                            v-for="event in weekEvents.filter(
                                (item) => item.day === dayIndex,
                            )"
                            :key="event.title + event.start"
                            class="absolute inset-x-1 flex flex-col gap-0.5 overflow-hidden rounded-md border-l-[3px] px-1.5 py-1 leading-tight transition-all duration-300"
                            :class="[
                                `member-color-${event.color}`,
                                event.isDaily
                                    ? 'border-dotted'
                                    : 'border-solid',
                                event.status === 'done' && 'opacity-70',
                                selectedPractitioner !== null &&
                                    selectedPractitioner !== event.color &&
                                    'scale-[0.98] opacity-20 saturate-0',
                            ]"
                            :style="{
                                top: `${event.start * hourHeight + 0.15}rem`,
                                height: `${event.hours * hourHeight - 0.3}rem`,
                                borderColor: 'var(--member-color)',
                                background: event.isDaily
                                    ? 'color-mix(in oklab, var(--member-color) 11%, var(--card))'
                                    : 'color-mix(in oklab, var(--member-color) 18%, var(--card))',
                                boxShadow:
                                    event.status === 'ongoing'
                                        ? '0 0 0 1.5px var(--member-color)'
                                        : undefined,
                            }"
                        >
                            <div
                                class="flex items-center justify-between gap-1"
                            >
                                <span class="truncate font-semibold">{{
                                    event.title
                                }}</span>
                                <span
                                    v-if="event.isDaily"
                                    class="shrink-0 rounded-full bg-primary/12 px-1.5 py-0.5 text-[9px] font-medium text-primary"
                                >
                                    Daily
                                </span>
                            </div>
                            <span
                                class="truncate text-[10px] text-muted-foreground"
                                >{{ event.who }}</span
                            >
                            <span
                                v-if="event.hours >= 1.25"
                                class="mt-auto flex items-center justify-between pt-0.5"
                            >
                                <span class="flex -space-x-1">
                                    <span
                                        v-for="patient in event.patients.slice(
                                            0,
                                            3,
                                        )"
                                        :key="patient"
                                        class="flex size-4.5 items-center justify-center rounded-full border border-card text-[8px] font-semibold text-white"
                                        :style="{
                                            background: 'var(--member-color)',
                                        }"
                                    >
                                        {{ initials(patient) || '+' }}
                                    </span>
                                </span>
                                <span
                                    v-if="event.room"
                                    class="text-[10px] text-muted-foreground"
                                >
                                    {{ event.room }}
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </MockFrame>
        </div>

        <!-- Plane 2a: Top-Right Floating Invoice Card (Independent Parallax) -->
        <div
            :style="invoicePlane"
            class="absolute -top-9 right-4 z-20 hidden items-center gap-3 rounded-xl border bg-card/95 py-2.5 pr-3.5 pl-2.5 text-xs shadow-[0_18px_40px_-12px_rgb(0_0_0/0.22)] backdrop-blur-md md:flex dark:shadow-[0_20px_44px_-12px_rgb(0_0_0/0.75)]"
            aria-hidden="true"
        >
            <span
                class="flex size-8 items-center justify-center rounded-lg bg-primary/12 text-primary"
            >
                <ReceiptText class="size-4" />
            </span>
            <div>
                <div class="flex items-center gap-2">
                    <span class="font-semibold tabular-nums"
                        >Invoice #000214</span
                    >
                    <span
                        class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 font-medium text-emerald-700 dark:text-emerald-300"
                    >
                        <CheckCircle2 class="size-3" /> Paid
                    </span>
                    <span class="font-semibold tabular-nums">1,200.00</span>
                </div>
                <div class="mt-0.5 text-[11px] text-muted-foreground">
                    Marco Santos · Price locked to bill
                </div>
            </div>
        </div>

        <!-- Plane 2b: Bottom-Left Floating Daily Session Card (Independent Parallax) -->
        <div
            :style="dailyToastPlane"
            class="absolute -bottom-8 -left-5 z-20 hidden w-80 items-start gap-3 rounded-xl border bg-card/95 p-3.5 text-xs shadow-[0_18px_40px_-12px_rgb(0_0_0/0.22)] backdrop-blur-md md:flex dark:shadow-[0_20px_44px_-12px_rgb(0_0_0/0.75)]"
            aria-hidden="true"
        >
            <span
                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground"
            >
                <CalendarSync class="size-4" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-semibold">Morning rehab · Daily</span>
                    <span
                        class="rounded-full bg-primary/12 px-2 py-0.5 text-[11px] font-medium text-primary tabular-nums"
                    >
                        8 of 9 plotted
                    </span>
                </div>
                <p class="mt-0.5 text-muted-foreground">
                    Sam Whitfield · 6 prepaid, 1 moved for today only
                </p>
                <div class="mt-2 flex gap-1.5 text-[11px] font-medium">
                    <span
                        class="rounded-full bg-teal-500/15 px-2 py-0.5 text-teal-800 dark:text-teal-300"
                        >Regular plan</span
                    >
                    <span
                        class="rounded-full bg-amber-500/15 px-2 py-0.5 text-amber-800 dark:text-amber-300"
                        >Today only</span
                    >
                    <span
                        class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-emerald-800 dark:text-emerald-300"
                        >Paid</span
                    >
                </div>
            </div>
        </div>
    </div>
</template>
