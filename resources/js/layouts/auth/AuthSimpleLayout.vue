<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    CalendarCheck2,
    CheckCircle2,
    Lock,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage();
const appName = computed(() => page.props.name ?? 'Clinic App');
</script>

<template>
    <div class="landing flex min-h-svh flex-col bg-background text-foreground">
        <!-- Top header bar -->
        <header class="border-b bg-background/85 backdrop-blur-md">
            <div
                class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-4 md:px-8"
            >
                <div class="flex items-center gap-6">
                    <Link
                        :href="home()"
                        class="flex items-center gap-2 font-semibold tracking-tight"
                    >
                        <AppLogoIcon class="size-6 fill-current text-primary" />
                        <span>{{ appName }}</span>
                    </Link>
                    <span
                        class="hidden h-4 w-px bg-border sm:inline-block"
                        aria-hidden="true"
                    />
                    <Link
                        :href="home()"
                        class="group hidden items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground sm:inline-flex"
                    >
                        <ArrowLeft
                            class="size-3.5 transition-transform group-hover:-translate-x-0.5"
                        />
                        Back to home
                    </Link>
                </div>

                <div class="flex items-center gap-3">
                    <ThemeToggle />
                </div>
            </div>
        </header>

        <!-- Main split stage -->
        <main class="relative flex flex-1 items-center">
            <div
                class="mx-auto grid w-full max-w-6xl items-center gap-12 px-4 py-12 md:px-8 lg:grid-cols-[1.05fr_0.95fr] lg:gap-16 lg:py-16"
            >
                <!-- Left column: Warm editorial clinic context (Desktop) -->
                <div class="hidden lg:block">
                    <p class="text-sm font-medium text-primary">
                        Staff workspace
                    </p>

                    <h2
                        class="mt-3 font-display text-4xl leading-[1.08] font-normal tracking-tight text-balance xl:text-[2.75rem]"
                    >
                        One sign-in across every clinic
                        <em class="font-normal text-primary italic"
                            >you practice in.</em
                        >
                    </h2>

                    <p
                        class="mt-4 max-w-md text-base leading-relaxed text-muted-foreground"
                    >
                        Open your day schedule, plot recurring daily-session
                        rosters, and record per-patient payments with your role
                        permissions already applied.
                    </p>

                    <!-- Workspace Preview Card -->
                    <div
                        class="relative mt-8 overflow-hidden rounded-xl border bg-card shadow-[0_1px_0_0_var(--border),0_24px_48px_-24px_rgb(0_0_0/0.16)] dark:shadow-[0_24px_48px_-24px_rgb(0_0_0/0.6)]"
                        aria-hidden="true"
                    >
                        <div
                            class="flex h-10 items-center justify-between border-b bg-(--surface-sunken) px-4 text-xs text-muted-foreground"
                        >
                            <span class="font-medium text-foreground">
                                Your clinics
                            </span>
                            <span>Switch anytime from the sidebar</span>
                        </div>

                        <div class="divide-y p-4 text-xs">
                            <div class="pb-3.5">
                                <div class="grid gap-2.5 sm:grid-cols-2">
                                    <div
                                        class="flex items-center gap-2.5 rounded-lg border bg-(--surface-sunken)/60 p-3"
                                    >
                                        <span
                                            class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/12 text-primary"
                                        >
                                            <Building2 class="size-4" />
                                        </span>
                                        <div class="min-w-0">
                                            <div class="truncate font-semibold">
                                                Northside Physio
                                            </div>
                                            <div
                                                class="text-[11px] text-muted-foreground"
                                            >
                                                Clinic Admin · Full access
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        class="flex items-center gap-2.5 rounded-lg border bg-card p-3"
                                    >
                                        <span
                                            class="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"
                                        >
                                            <CalendarCheck2 class="size-4" />
                                        </span>
                                        <div class="min-w-0">
                                            <div class="truncate font-semibold">
                                                Eastside Rehab
                                            </div>
                                            <div
                                                class="text-[11px] text-muted-foreground"
                                            >
                                                Practitioner · Schedule &amp;
                                                daily sessions
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="grid grid-cols-3 gap-3 pt-3.5 text-xs text-muted-foreground"
                            >
                                <div class="flex items-center gap-1.5">
                                    <ShieldCheck
                                        class="size-3.5 shrink-0 text-primary"
                                    />
                                    <span>Isolated per clinic</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <Lock
                                        class="size-3.5 shrink-0 text-primary"
                                    />
                                    <span>Role permissions</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <CheckCircle2
                                        class="size-3.5 shrink-0 text-primary"
                                    />
                                    <span>Activity trail</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right column: Clean, human Auth form card -->
                <div class="mx-auto w-full max-w-md lg:max-w-none">
                    <div
                        class="animate__animated animate__revealUp rounded-2xl border bg-card p-6 shadow-[0_1px_0_0_var(--border),0_28px_60px_-24px_rgb(0_0_0/0.16)] sm:p-9 dark:shadow-[0_1px_0_0_var(--border),0_32px_64px_-24px_rgb(0_0_0/0.6)]"
                    >
                        <div class="mb-7 space-y-2">
                            <h1
                                class="font-display text-2xl font-normal tracking-tight sm:text-3xl"
                            >
                                {{ title }}
                            </h1>
                            <p
                                v-if="description"
                                class="text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ description }}
                            </p>
                        </div>

                        <slot />
                    </div>

                    <div
                        class="mt-4 flex items-center justify-between px-1 text-xs text-muted-foreground lg:hidden"
                    >
                        <Link
                            :href="home()"
                            class="inline-flex items-center gap-1.5 hover:text-foreground"
                        >
                            <ArrowLeft class="size-3.5" />
                            Back to home
                        </Link>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
