<script setup lang="ts">
import { GripVertical, RotateCcw, Sparkles, UserPlus } from '@lucide/vue';
import MockFrame from './MockFrame.vue';
import { initials } from './sample';

const unplotted = [
    {
        name: 'Leo Cruz',
        service: 'Mobility rehab',
        duration: '45 min',
        usual: 'Usual 09:45 slot taken',
    },
    {
        name: 'Ruth Adeyemi',
        service: 'Post-op physio',
        duration: '30 min',
        usual: 'No usual slot set',
    },
];

const timelineSlots = [
    {
        time: '08:00 – 08:45',
        patient: 'Marco Santos',
        service: 'Rehab circuit · 900.00',
        kind: 'paid',
        badge: 'Paid · Prepaid (4 left)',
    },
    {
        time: '08:45 – 09:30',
        patient: 'Ella Brooks',
        service: 'Rehab circuit · 900.00',
        kind: 'planned',
        badge: 'Regular plan',
    },
    {
        time: '09:30 – 10:15',
        patient: 'Other session: Group physio',
        service: 'Sam Whitfield is holding a regular session',
        kind: 'busy',
        badge: 'Busy',
    },
    {
        time: '10:15 – 11:00',
        patient: 'Jo Kim',
        service: 'Sports taping + rehab · 1,150.00',
        kind: 'changed',
        badge: 'Today only · Reverts Wed',
    },
];
</script>

<template>
    <MockFrame
        title="Daily sessions · Morning Rehab (Tue 4 Nov)"
        label="The Daily Session Day Planner showing unplotted patients on the left and plotted teal, amber, emerald and striped busy blocks on the clinic timeline."
    >
        <template #toolbar>
            <span
                class="rounded-full bg-emerald-500/15 px-2.5 py-0.5 text-[11px] font-medium text-emerald-700 tabular-nums dark:text-emerald-300"
            >
                In progress · 08:00–12:00
            </span>
        </template>

        <div class="grid gap-3 p-3.5 text-xs sm:grid-cols-[0.9fr_1.35fr]">
            <!-- Left column: To plot queue -->
            <div
                class="flex flex-col justify-between rounded-lg border bg-(--surface-sunken)/70 p-2.5"
            >
                <div>
                    <div class="flex items-center justify-between gap-2 pb-2">
                        <span class="font-semibold">To plot (2)</span>
                        <span
                            class="inline-flex items-center gap-1 rounded-md bg-primary px-2 py-1 text-[11px] font-medium text-primary-foreground"
                        >
                            <Sparkles class="size-3" /> Fill free time
                        </span>
                    </div>
                    <div class="space-y-2">
                        <div
                            v-for="person in unplotted"
                            :key="person.name"
                            class="flex items-start gap-2 rounded-md border bg-card p-2 shadow-2xs"
                        >
                            <GripVertical
                                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                            />
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex items-center justify-between gap-1"
                                >
                                    <span class="truncate font-medium">{{
                                        person.name
                                    }}</span>
                                    <span
                                        class="rounded-full bg-muted px-2 py-0.5 text-[10px] text-muted-foreground tabular-nums"
                                    >
                                        {{ person.duration }}
                                    </span>
                                </div>
                                <div
                                    class="truncate text-[11px] text-muted-foreground"
                                >
                                    {{ person.service }}
                                </div>
                                <div
                                    class="mt-1 text-[11px] text-amber-700 dark:text-amber-300"
                                >
                                    {{ person.usual }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="mt-3 flex items-center justify-between border-t pt-2.5 text-[11px] text-muted-foreground"
                >
                    <span
                        class="inline-flex items-center gap-1 font-medium text-foreground"
                    >
                        <UserPlus class="size-3.5 text-primary" /> Walk-in for
                        today
                    </span>
                    <span class="tabular-nums">75 min free ahead</span>
                </div>
            </div>

            <!-- Right column: Clinic-time DayTimeline -->
            <div class="space-y-2">
                <div
                    class="flex items-center justify-between text-[11px] text-muted-foreground"
                >
                    <span>Clinic time</span>
                    <span>Scheduled 14 days ahead</span>
                </div>

                <div class="space-y-1.5">
                    <div
                        v-for="slot in timelineSlots"
                        :key="slot.time"
                        class="rounded-md border-l-[3px] px-2.5 py-2"
                        :class="[
                            slot.kind === 'paid' &&
                                'border-emerald-600 bg-emerald-500/10',
                            slot.kind === 'planned' &&
                                'border-teal-600 bg-teal-500/10',
                            slot.kind === 'changed' &&
                                'border-amber-500 bg-amber-500/12 ring-1 ring-amber-500/30',
                            slot.kind === 'busy' &&
                                'border-border bg-[repeating-linear-gradient(-45deg,var(--muted),var(--muted)_6px,transparent_6px,transparent_12px)]',
                        ]"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span
                                class="text-[11px] text-muted-foreground tabular-nums"
                            >
                                {{ slot.time }}
                            </span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[10px] font-medium"
                                :class="[
                                    slot.kind === 'paid' &&
                                        'bg-emerald-500/15 text-emerald-800 dark:text-emerald-300',
                                    slot.kind === 'planned' &&
                                        'bg-teal-500/15 text-teal-800 dark:text-teal-300',
                                    slot.kind === 'changed' &&
                                        'bg-amber-500/20 text-amber-900 dark:text-amber-200',
                                    slot.kind === 'busy' &&
                                        'bg-muted text-muted-foreground',
                                ]"
                            >
                                {{ slot.badge }}
                            </span>
                        </div>
                        <div
                            class="mt-1 flex items-center justify-between gap-2"
                        >
                            <span
                                class="flex items-center gap-1.5 font-semibold"
                                :class="
                                    slot.kind === 'busy' &&
                                    'font-normal text-muted-foreground'
                                "
                            >
                                <span
                                    v-if="slot.kind !== 'busy'"
                                    class="flex size-4.5 items-center justify-center rounded-full bg-card text-[8px] font-bold shadow-2xs"
                                >
                                    {{ initials(slot.patient) }}
                                </span>
                                {{ slot.patient }}
                            </span>
                            <span
                                v-if="slot.kind === 'changed'"
                                class="inline-flex items-center gap-1 text-[10px] text-muted-foreground"
                            >
                                <RotateCcw class="size-2.5" /> Reset to plan
                            </span>
                        </div>
                        <div
                            class="mt-0.5 text-[11px] text-muted-foreground tabular-nums"
                        >
                            {{ slot.service }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MockFrame>
</template>
