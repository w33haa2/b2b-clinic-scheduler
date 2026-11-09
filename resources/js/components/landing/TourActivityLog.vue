<script setup lang="ts">
import { ArrowRight, EyeOff, ShieldCheck } from '@lucide/vue';
import MockFrame from './MockFrame.vue';

const entries = [
    {
        time: '10:35',
        category: 'Billing',
        actor: 'Sam Whitfield',
        sentence:
            'Recorded payment from Marco Santos for session Group physio on Tue 4 Nov, 09:00',
        changes: [
            {
                field: 'Payment status',
                old: 'Unpaid',
                new: 'Paid · 1,250.00',
                redacted: false,
            },
        ],
    },
    {
        time: '09:14',
        category: 'Patients',
        actor: 'Dr. Lena Ortiz',
        sentence: 'Updated patient Ana Reyes',
        changes: [
            {
                field: 'Phone',
                old: '',
                new: 'Changed (hidden for privacy)',
                redacted: true,
            },
            {
                field: 'Clinical notes',
                old: '',
                new: 'Changed (hidden for privacy)',
                redacted: true,
            },
        ],
    },
    {
        time: '09:00',
        category: 'Schedule',
        actor: 'Automatic scheduler',
        sentence:
            'Changed status of daily session Morning Rehab on Tue 4 Nov from Scheduled to In progress',
        changes: [
            {
                field: 'Status',
                old: 'Scheduled',
                new: 'In progress',
                redacted: false,
            },
        ],
    },
];
</script>

<template>
    <MockFrame
        title="Activity log · Audit trail"
        label="The clinic activity log showing timestamped changes, old-to-new diffs, and automatic privacy redaction for personal patient details."
    >
        <template #toolbar>
            <span
                class="inline-flex items-center gap-1 rounded-full bg-primary/12 px-2.5 py-0.5 text-[11px] font-medium text-primary"
            >
                <ShieldCheck class="size-3" /> Append-only history
            </span>
        </template>

        <div class="divide-y p-3.5 text-xs">
            <div
                v-for="entry in entries"
                :key="entry.time"
                class="py-3 first:pt-0 last:pb-0"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span
                            class="rounded-full border bg-(--surface-sunken) px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
                        >
                            {{ entry.category }}
                        </span>
                        <span class="font-medium">{{ entry.actor }}</span>
                    </div>
                    <span
                        class="text-[11px] text-muted-foreground tabular-nums"
                    >
                        Today, {{ entry.time }}
                    </span>
                </div>

                <p class="mt-1.5 leading-snug text-foreground/90">
                    {{ entry.sentence }}
                </p>

                <div
                    class="mt-2 space-y-1.5 rounded-lg border bg-(--surface-sunken)/60 p-2.5 text-[11px]"
                >
                    <div
                        v-for="change in entry.changes"
                        :key="change.field"
                        class="flex flex-wrap items-center gap-1.5"
                    >
                        <span class="text-muted-foreground"
                            >{{ change.field }}:</span
                        >
                        <template v-if="change.redacted">
                            <span
                                class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                            >
                                <EyeOff class="size-3 text-primary" />
                                {{ change.new }}
                            </span>
                        </template>
                        <template v-else>
                            <span
                                class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground line-through"
                            >
                                {{ change.old }}
                            </span>
                            <ArrowRight class="size-3 text-muted-foreground" />
                            <span
                                class="rounded-full bg-primary/12 px-2 py-0.5 text-[11px] font-medium text-primary tabular-nums"
                            >
                                {{ change.new }}
                            </span>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </MockFrame>
</template>
