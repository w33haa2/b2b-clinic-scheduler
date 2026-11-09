<script setup lang="ts">
import { CalendarCheck2, CheckCircle2, Lock, Plus } from '@lucide/vue';
import MockFrame from './MockFrame.vue';
import { initials } from './sample';

const members = [
    {
        name: 'Marco Santos',
        status: 'Paid',
        note: 'Recorded in ledger',
        items: [
            {
                name: 'Group physio',
                qty: 1,
                amount: '900.00',
            },
            {
                name: 'Resistance band',
                qty: 1,
                amount: '350.00',
            },
        ],
    },
    {
        name: 'Jo Kim',
        status: 'Unpaid',
        note: 'Due today',
        items: [
            {
                name: 'Group physio',
                qty: 1,
                amount: '900.00',
            },
        ],
    },
    {
        name: 'Ella Brooks',
        status: 'Prepaid',
        note: 'Prepaid 10 days',
        items: [
            {
                name: 'Group physio (Catalogue price locked)',
                qty: 1,
                amount: '900.00',
            },
        ],
    },
];
</script>

<template>
    <MockFrame
        title="Group physio · Tue 9:00 – 10:30"
        label="A session with three patients, each billed separately, with locked catalogue prices and append-only ledger records."
    >
        <template #toolbar>
            <span
                class="inline-flex items-center gap-1 rounded-md border bg-card px-2 py-0.5 text-[11px] text-muted-foreground"
            >
                <CalendarCheck2 class="size-3 text-primary" /> Prepay daily
                sessions
            </span>
        </template>

        <div class="space-y-2.5 p-3.5 text-xs">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span
                        class="rounded-full bg-amber-500/15 px-2.5 py-0.5 font-medium text-amber-800 dark:text-amber-300"
                        >In progress</span
                    >
                    <span class="text-muted-foreground"
                        >Held by Sam Whitfield</span
                    >
                </div>
                <span
                    class="inline-flex items-center gap-1 text-[11px] text-muted-foreground"
                >
                    <Lock class="size-3 text-primary" /> Unit prices copied at
                    billing
                </span>
            </div>
            <div
                v-for="member in members"
                :key="member.name"
                class="rounded-md border bg-card"
            >
                <div class="flex items-center gap-2 px-3 py-2">
                    <span
                        class="flex size-6 items-center justify-center rounded-full bg-muted text-[9px] font-semibold"
                        >{{ initials(member.name) }}</span
                    >
                    <span class="flex-1 font-medium">{{ member.name }}</span>
                    <span
                        class="hidden text-[11px] text-muted-foreground sm:inline"
                        >{{ member.note }}</span
                    >
                    <span
                        class="rounded-full px-2.5 py-0.5 font-medium"
                        :class="
                            member.status === 'Unpaid'
                                ? 'bg-amber-500/12 text-amber-800 dark:text-amber-300'
                                : 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-300'
                        "
                    >
                        {{ member.status }}
                    </span>
                </div>
                <div class="space-y-1 border-t px-3 py-2">
                    <div
                        v-for="item in member.items"
                        :key="item.name"
                        class="flex items-center justify-between gap-2"
                    >
                        <span class="truncate">
                            {{ item.name }}
                            <span class="text-muted-foreground"
                                >× {{ item.qty }}</span
                            >
                        </span>
                        <span class="font-medium tabular-nums">{{
                            item.amount
                        }}</span>
                    </div>
                    <div
                        v-if="member.status === 'Unpaid'"
                        class="flex gap-1.5 pt-1.5"
                    >
                        <span
                            class="flex items-center gap-1 rounded-md border px-2 py-1"
                            ><Plus class="size-3" /> Bill a service</span
                        >
                        <span
                            class="flex items-center gap-1 rounded-md bg-primary px-2 py-1 text-primary-foreground tabular-nums"
                        >
                            <CheckCircle2 class="size-3" /> Mark paid · 900.00
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </MockFrame>
</template>
