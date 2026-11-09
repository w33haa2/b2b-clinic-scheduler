<script setup lang="ts">
import MockFrame from './MockFrame.vue';
import { hours, initials, practitioners } from './sample';

const sessions = [
    {
        practitioner: 0,
        start: 0.5,
        hours: 1,
        title: 'Initial assessment',
        patients: ['Ana Reyes'],
    },
    {
        practitioner: 0,
        start: 2,
        hours: 1,
        title: 'Follow-up',
        patients: ['Ruth Adeyemi'],
    },
    {
        practitioner: 1,
        start: 0,
        hours: 1.5,
        title: 'Group physio',
        patients: ['Marco Santos', 'Jo Kim', 'Ella Brooks'],
    },
    {
        practitioner: 1,
        start: 2.5,
        hours: 1,
        title: 'Sports rehab',
        patients: ['Jo Kim'],
    },
    {
        practitioner: 2,
        start: 1,
        hours: 1,
        title: 'Speech therapy',
        patients: ['Noah Lee'],
    },
    {
        practitioner: 2,
        start: 3,
        hours: 1,
        title: 'Speech therapy',
        patients: ['Mia Tan'],
    },
];
const hourHeight = 3.5;
</script>

<template>
    <MockFrame
        title="Schedule · Tuesday 4 November"
        label="Day view of the schedule with one column per practitioner."
    >
        <div
            class="grid grid-cols-[2.75rem_repeat(3,minmax(0,1fr))] text-[11px]"
        >
            <div class="border-b" />
            <div
                v-for="person in practitioners"
                :key="person.name"
                class="flex items-center gap-1.5 border-b border-l px-2 py-2"
            >
                <span
                    class="size-2 rounded-full"
                    :style="{
                        background: `var(--member-color-${person.color})`,
                    }"
                />
                <span class="truncate font-medium">{{ person.name }}</span>
            </div>
            <div>
                <div
                    v-for="hour in hours"
                    :key="hour"
                    class="pr-1.5 text-right text-[10px] text-muted-foreground tabular-nums"
                    :style="{ height: `${hourHeight}rem` }"
                >
                    {{ hour }}
                </div>
            </div>
            <div
                v-for="(person, index) in practitioners"
                :key="person.name"
                class="relative border-l"
                :style="{ height: `${hourHeight * hours.length}rem` }"
            >
                <div
                    v-for="session in sessions.filter(
                        (item) => item.practitioner === index,
                    )"
                    :key="session.title + session.start"
                    class="absolute inset-x-1.5 rounded-md border-l-[3px] px-2 py-1 leading-tight"
                    :class="`member-color-${person.color}`"
                    :style="{
                        top: `${session.start * hourHeight + 0.15}rem`,
                        height: `${session.hours * hourHeight - 0.3}rem`,
                        borderColor: 'var(--member-color)',
                        background:
                            'color-mix(in oklab, var(--member-color) 16%, var(--card))',
                    }"
                >
                    <div class="truncate font-semibold">
                        {{ session.title }}
                    </div>
                    <div class="truncate text-muted-foreground">
                        {{ session.patients.join(', ') }}
                    </div>
                    <div v-if="session.hours > 1" class="mt-1 flex -space-x-1">
                        <span
                            v-for="patient in session.patients"
                            :key="patient"
                            class="flex size-5 items-center justify-center rounded-full border border-card text-[8px] font-semibold text-white"
                            :style="{ background: 'var(--member-color)' }"
                        >
                            {{ initials(patient) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </MockFrame>
</template>
