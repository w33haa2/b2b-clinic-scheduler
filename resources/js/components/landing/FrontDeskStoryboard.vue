<script setup lang="ts">
import {
    ArrowRight,
    CalendarCheck,
    CheckCircle2,
    FileDown,
    Lock,
    ShieldAlert,
    Sparkles,
    Users,
} from '@lucide/vue';
import MockFrame from './MockFrame.vue';
import { initials } from './sample';

defineProps<{
    step: number;
}>();
</script>

<template>
    <div class="relative">
        <Transition
            mode="out-in"
            enter-active-class="transition duration-300 ease-[cubic-bezier(.32,.72,0,1)]"
            enter-from-class="opacity-0 translate-y-2"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <!-- Step 0: 07:45 Morning Triage & Daily Session Queue -->
            <MockFrame
                v-if="step === 0"
                key="step-0"
                title="07:45 · Day view & Daily session planner"
                label="Morning schedule at 07:45 with three practitioners side by side and unplotted daily-session patients ready to be placed."
            >
                <template #toolbar>
                    <span
                        class="rounded-full bg-primary/12 px-2 py-0.5 text-[11px] font-medium text-primary"
                    >
                        No overlaps
                    </span>
                </template>
                <div class="space-y-3 p-4 text-xs">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-(--surface-sunken) px-3 py-2"
                    >
                        <div class="flex items-center gap-2">
                            <Sparkles class="size-3.5 text-primary" />
                            <span class="font-medium"
                                >Morning Rehab · 3 patients waiting to
                                plot</span
                            >
                        </div>
                        <span
                            class="rounded-md bg-primary px-2.5 py-1 text-[11px] font-medium text-primary-foreground"
                        >
                            Fill free time
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <div class="rounded-lg border bg-card p-2.5">
                            <div class="flex items-center gap-1.5 font-medium">
                                <span
                                    class="size-2 rounded-full"
                                    style="background: var(--member-color-0)"
                                />
                                Dr. Lena Ortiz
                            </div>
                            <div
                                class="mt-2 rounded border-l-2 p-1.5 text-[11px]"
                                style="
                                    border-color: var(--member-color-0);
                                    background: color-mix(
                                        in oklab,
                                        var(--member-color-0) 14%,
                                        var(--card)
                                    );
                                "
                            >
                                <div
                                    class="text-[10px] text-muted-foreground tabular-nums"
                                >
                                    08:30 – 09:30
                                </div>
                                <div class="font-semibold">
                                    Initial assessment
                                </div>
                                <div class="text-muted-foreground">
                                    Ana Reyes
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg border bg-card p-2.5">
                            <div class="flex items-center gap-1.5 font-medium">
                                <span
                                    class="size-2 rounded-full"
                                    style="background: var(--member-color-1)"
                                />
                                Sam Whitfield
                            </div>
                            <div
                                class="mt-2 rounded border-l-2 border-dotted p-1.5 text-[11px]"
                                style="
                                    border-color: var(--member-color-1);
                                    background: color-mix(
                                        in oklab,
                                        var(--member-color-1) 12%,
                                        var(--card)
                                    );
                                "
                            >
                                <div
                                    class="text-[10px] text-muted-foreground tabular-nums"
                                >
                                    08:00 – 08:45 · Daily
                                </div>
                                <div class="font-semibold">Marco Santos</div>
                                <div class="text-muted-foreground">
                                    Usual slot plotted
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg border bg-card p-2.5">
                            <div class="flex items-center gap-1.5 font-medium">
                                <span
                                    class="size-2 rounded-full"
                                    style="background: var(--member-color-2)"
                                />
                                Priya Nair
                            </div>
                            <div
                                class="mt-2 rounded border-l-2 p-1.5 text-[11px]"
                                style="
                                    border-color: var(--member-color-2);
                                    background: color-mix(
                                        in oklab,
                                        var(--member-color-2) 14%,
                                        var(--card)
                                    );
                                "
                            >
                                <div
                                    class="text-[10px] text-muted-foreground tabular-nums"
                                >
                                    09:00 – 10:00
                                </div>
                                <div class="font-semibold">Speech therapy</div>
                                <div class="text-muted-foreground">
                                    Noah Lee
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </MockFrame>

            <!-- Step 1: 09:00 Group Physio Starts -->
            <MockFrame
                v-else-if="step === 1"
                key="step-1"
                title="09:00 · Session sheet (Group physio)"
                label="Group physio session marked in progress with three enrolled patients and automatic double-booking prevention."
            >
                <template #toolbar>
                    <span
                        class="rounded-full bg-amber-500/15 px-2.5 py-0.5 text-[11px] font-medium text-amber-800 dark:text-amber-300"
                    >
                        In progress
                    </span>
                </template>
                <div class="space-y-3 p-4 text-xs">
                    <div
                        class="flex items-center justify-between border-b pb-3"
                    >
                        <div>
                            <div class="text-sm font-semibold tabular-nums">
                                Group physio · Tue 09:00 – 10:30
                            </div>
                            <div class="text-muted-foreground">
                                Practitioner: Sam Whitfield · 3 patients
                                attending
                            </div>
                        </div>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-md border bg-(--surface-sunken) px-2.5 py-1 text-[11px]"
                        >
                            Scheduled
                            <ArrowRight class="size-3 text-primary" />
                            <strong class="font-medium text-primary"
                                >In progress</strong
                            >
                        </span>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-3">
                        <div
                            v-for="patient in [
                                'Marco Santos',
                                'Jo Kim',
                                'Ella Brooks',
                            ]"
                            :key="patient"
                            class="flex items-center gap-2 rounded-lg border bg-card p-2.5"
                        >
                            <span
                                class="flex size-7 items-center justify-center rounded-full bg-accent text-[10px] font-semibold text-accent-foreground"
                            >
                                {{ initials(patient) }}
                            </span>
                            <div class="min-w-0">
                                <div class="truncate font-medium">
                                    {{ patient }}
                                </div>
                                <div class="text-[11px] text-muted-foreground">
                                    Checked in
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-center gap-2 rounded-lg border border-dashed bg-(--surface-sunken)/60 px-3 py-2 text-[11px] text-muted-foreground"
                    >
                        <ShieldAlert class="size-3.5 shrink-0 text-primary" />
                        <span>
                            Sam Whitfield’s 09:00–10:30 window is blocked
                            against overlapping regular or daily-session slots.
                        </span>
                    </div>
                </div>
            </MockFrame>

            <!-- Step 2: 10:32 Per-Patient Billing & Price Lock -->
            <MockFrame
                v-else-if="step === 2"
                key="step-2"
                title="10:32 · Independent per-patient bills"
                label="Each patient in the group session receives their own bill with unit prices copied from the clinic catalogue."
            >
                <template #toolbar>
                    <span
                        class="inline-flex items-center gap-1 text-[11px] text-muted-foreground"
                    >
                        <Lock class="size-3 text-primary" /> Catalogue price
                        locked
                    </span>
                </template>
                <div class="space-y-2.5 p-4 text-xs">
                    <div
                        v-for="row in [
                            {
                                name: 'Marco Santos',
                                detail: 'Group physio (900.00) + Resistance band (350.00)',
                                total: '1,250.00',
                                tag: '2 services',
                            },
                            {
                                name: 'Jo Kim',
                                detail: 'Group physio (900.00)',
                                total: '900.00',
                                tag: '1 service',
                            },
                            {
                                name: 'Ella Brooks',
                                detail: 'Group physio (900.00 · Prepaid package)',
                                total: '900.00',
                                tag: 'Prepaid',
                            },
                        ]"
                        :key="row.name"
                        class="flex items-center justify-between gap-3 rounded-lg border bg-card px-3 py-2.5"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold">{{
                                    row.name
                                }}</span>
                                <span
                                    class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                                >
                                    {{ row.tag }}
                                </span>
                            </div>
                            <div
                                class="truncate text-muted-foreground tabular-nums"
                            >
                                {{ row.detail }}
                            </div>
                        </div>
                        <div class="text-right font-semibold tabular-nums">
                            {{ row.total }}
                        </div>
                    </div>
                </div>
            </MockFrame>

            <!-- Step 3: 10:35 Desk Payment & Append-Only Ledger -->
            <MockFrame
                v-else-if="step === 3"
                key="step-3"
                title="10:35 · Payment & transaction ledger"
                label="Marking Marco Santos paid writes two immutable inbound transaction rows to the clinic ledger."
            >
                <template #toolbar>
                    <span
                        class="rounded-full bg-emerald-500/15 px-2.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-300"
                    >
                        Permanent ledger
                    </span>
                </template>
                <div class="space-y-3 p-4 text-xs">
                    <div
                        class="flex items-center justify-between rounded-lg border bg-emerald-500/8 px-3 py-2.5"
                    >
                        <div class="flex items-center gap-2">
                            <CheckCircle2
                                class="size-4 text-emerald-600 dark:text-emerald-400"
                            />
                            <div>
                                <div class="font-semibold">
                                    Payment marked paid
                                </div>
                                <div class="text-[11px] text-muted-foreground">
                                    Marco Santos · Recorded by Sam Whitfield at
                                    10:35
                                </div>
                            </div>
                        </div>
                        <span class="text-sm font-semibold tabular-nums"
                            >1,250.00</span
                        >
                    </div>

                    <div class="divide-y rounded-lg border bg-card text-xs">
                        <div
                            class="grid grid-cols-[auto_1fr_auto] gap-3 px-3 py-1.5 text-[11px] font-medium text-muted-foreground"
                        >
                            <span>Entry</span>
                            <span>Service</span>
                            <span>Amount</span>
                        </div>
                        <div
                            class="grid grid-cols-[auto_1fr_auto] items-center gap-3 px-3 py-2"
                        >
                            <span
                                class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-300"
                                >Inbound</span
                            >
                            <span class="truncate">Group physio × 1</span>
                            <span class="font-medium tabular-nums"
                                >+900.00</span
                            >
                        </div>
                        <div
                            class="grid grid-cols-[auto_1fr_auto] items-center gap-3 px-3 py-2"
                        >
                            <span
                                class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-300"
                                >Inbound</span
                            >
                            <span class="truncate">Resistance band × 1</span>
                            <span class="font-medium tabular-nums"
                                >+350.00</span
                            >
                        </div>
                    </div>
                </div>
            </MockFrame>

            <!-- Step 4: 16:50 Invoice INV-000214 & PDF -->
            <MockFrame
                v-else
                key="step-4"
                title="16:50 · Invoice #000214"
                label="Invoice 000214 generated for Marco Santos from paid uninvoiced services across the week."
            >
                <template #toolbar>
                    <span
                        class="inline-flex items-center gap-1 rounded-md bg-primary px-2.5 py-0.5 text-[11px] font-medium text-primary-foreground"
                    >
                        <FileDown class="size-3" /> Download PDF
                    </span>
                </template>
                <div class="space-y-3 p-4 text-xs">
                    <div class="flex items-start justify-between border-b pb-3">
                        <div>
                            <div class="font-display text-xl">
                                Invoice #000214
                            </div>
                            <div class="text-muted-foreground">
                                Billed to Marco Santos · Issued 4 Nov, 16:50
                            </div>
                        </div>
                        <span
                            class="rounded-full bg-emerald-500/15 px-2.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-300"
                        >
                            Paid in full
                        </span>
                    </div>

                    <div class="space-y-1.5 text-xs">
                        <div class="flex justify-between">
                            <span>Mon 3 Nov · Rehab assessment</span>
                            <span class="tabular-nums">1,200.00</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Tue 4 Nov · Group physio</span>
                            <span class="tabular-nums">900.00</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Tue 4 Nov · Resistance band</span>
                            <span class="tabular-nums">350.00</span>
                        </div>
                    </div>

                    <div
                        class="flex items-baseline justify-between border-t pt-2.5"
                    >
                        <span class="text-muted-foreground"
                            >Each item locked against duplicate invoicing</span
                        >
                        <span class="text-base font-semibold tabular-nums"
                            >2,450.00</span
                        >
                    </div>
                </div>
            </MockFrame>
        </Transition>

        <!-- Bottom subtle status bar -->
        <div
            class="mt-3 flex items-center justify-between px-1 text-xs text-muted-foreground"
        >
            <span class="inline-flex items-center gap-1.5">
                <CalendarCheck class="size-3.5 text-primary" />
                Single record from booking to PDF
            </span>
            <span class="inline-flex items-center gap-1 tabular-nums">
                <Users class="size-3.5" /> Moment {{ step + 1 }} of 5
            </span>
        </div>
    </div>
</template>
