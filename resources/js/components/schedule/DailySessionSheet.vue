<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, CalendarClock, Repeat } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DayPlanner from '@/components/schedule/DayPlanner.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useClinic } from '@/composables/useClinic';
import { formatClinicDate, formatClinicRange } from '@/lib/clinicTime';
import { dailySessions } from '@/routes/admin/clinics';
import type { ClinicSession, LineItem } from '@/types';

/**
 * One day of a daily session, opened from the calendar: plot it right here
 * (`DayPlanner`). The daily session itself (patients, services, usual slots)
 * is managed on the Daily sessions page.
 */
type Props = {
    /** The clinic session of the day. */
    sessionId: number | null;
    catalog: LineItem[];
};

const props = defineProps<Props>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ changed: [] }>();

const { clinicId, can, context } = useClinic();
const timezone = computed(() => context.value.clinic.timezone);
const day = ref<ClinicSession | null>(null);

watch([open, () => props.sessionId], () => {
    day.value = null;
});
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 overflow-y-auto sm:max-w-3xl">
            <SheetHeader class="border-b">
                <template v-if="day">
                    <span
                        class="inline-flex w-fit items-center gap-1 rounded-full bg-teal-100 px-2 py-0.5 text-xs font-medium text-teal-800 dark:bg-teal-500/15 dark:text-teal-300"
                    >
                        <Repeat class="size-3" />
                        Daily session
                    </span>
                    <SheetTitle class="text-xl">{{
                        day.daily_session?.name ?? day.name
                    }}</SheetTitle>
                    <SheetDescription
                        class="flex flex-wrap items-center gap-x-3 gap-y-1"
                    >
                        <span class="flex items-center gap-1.5">
                            <CalendarClock class="size-4" />
                            {{ formatClinicDate(day.starts_at, timezone) }} ·
                            {{
                                formatClinicRange(
                                    day.starts_at,
                                    day.ends_at,
                                    timezone,
                                )
                            }}
                        </span>
                        <span
                            >with
                            {{
                                day.clinic_user?.user?.name ??
                                'former staff member'
                            }}</span
                        >
                        <span class="text-xs"
                            >Clinic time ({{ timezone }})</span
                        >
                    </SheetDescription>
                </template>
                <template v-else>
                    <SheetTitle><Skeleton class="h-6 w-48" /></SheetTitle>
                    <SheetDescription
                        ><Skeleton class="h-4 w-64"
                    /></SheetDescription>
                </template>
            </SheetHeader>

            <div v-if="sessionId" class="space-y-4 p-4">
                <DayPlanner
                    :session-id="sessionId"
                    :catalog="catalog"
                    @loaded="(loaded) => (day = loaded)"
                    @changed="emit('changed')"
                />

                <Button
                    v-if="
                        day?.daily_session && can('clinic.daily-sessions.index')
                    "
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="
                            dailySessions(clinicId, {
                                query: { session: day.daily_session.id },
                            })
                        "
                    >
                        Manage patients and regular plan
                        <ArrowUpRight />
                    </Link>
                </Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
