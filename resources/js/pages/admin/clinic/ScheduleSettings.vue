<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { RotateCcw, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import TimezoneCombobox from '@/components/form/TimezoneCombobox.vue';
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError } from '@/lib/api';
import { browserTimeZone } from '@/lib/clinicTime';
import { formatDateTime } from '@/lib/format';
import { scheduleSettings } from '@/routes/admin/clinics';
import { update as clinicUpdate } from '@/routes/clinic';
import { show, update } from '@/routes/clinic/schedule-settings';
import type { ClinicSchedule, Wrapped } from '@/types';

/**
 * When the clinic is open. Clinic Admin only.
 *
 * These hours decide what the schedule calendar shows and what the time
 * pickers offer, and sessions booked outside them are refused unless
 * overruns are allowed. Changing them never moves a session that already
 * exists.
 */
const { clinicId, context } = useClinic();

setLayoutProps({
    breadcrumbs: [
        { title: 'Schedule settings', href: scheduleSettings(clinicId.value) },
    ],
});

type Draft = {
    weekdayOpensAt: string;
    weekdayClosesAt: string;
    weekendOpensAt: string;
    weekendClosesAt: string;
    saturdayEnabled: boolean;
    sundayEnabled: boolean;
    allowOutsideHours: boolean;
};

const { data, loading } = useApiQuery<Wrapped<ClinicSchedule>>(() =>
    show.url(clinicId.value),
);

const draft = ref<Draft | null>(null);
/** What the API last told us, to compare against and to discard back to. */
const saved = ref<Draft | null>(null);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});

function toDraft(schedule: ClinicSchedule): Draft {
    return {
        weekdayOpensAt: schedule.weekday_opens_at,
        weekdayClosesAt: schedule.weekday_closes_at,
        weekendOpensAt: schedule.weekend_opens_at,
        weekendClosesAt: schedule.weekend_closes_at,
        saturdayEnabled: schedule.saturday_enabled,
        sundayEnabled: schedule.sunday_enabled,
        allowOutsideHours: schedule.allow_outside_hours,
    };
}

watch(data, (response) => {
    if (response) {
        saved.value = toDraft(response.data);
        draft.value = toDraft(response.data);
    }
});

/*
 * The timezone is the clinic's own field, not part of the hours response,
 * and saving it refreshes shared props asynchronously. Keeping it in its
 * own ref avoids the hours watcher re-hydrating a stale value over the one
 * just saved.
 */
const timezone = ref(context.value.clinic.timezone);

watch(
    () => context.value.clinic.timezone,
    (value) => (timezone.value = value),
);

const timezoneChanged = computed(
    () => timezone.value !== context.value.clinic.timezone,
);

const isDirty = computed(
    () =>
        timezoneChanged.value ||
        JSON.stringify(draft.value) !== JSON.stringify(saved.value),
);

const opensWeekend = computed(
    () => draft.value?.saturdayEnabled || draft.value?.sundayEnabled,
);

/** What the browser thinks it is, offered when the two disagree. */
const browserZone = browserTimeZone();
const differsFromBrowser = computed(() => timezone.value !== browserZone);

/** "08:00" and "17:00" → "9 hours". */
function lengthOf(from: string, to: string): string {
    const minutes = minutesOf(to) - minutesOf(from);

    if (minutes <= 0) {
        return 'ends before it starts';
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return [
        hours > 0 ? `${hours} hour${hours === 1 ? '' : 's'}` : '',
        rest > 0 ? `${rest} min` : '',
    ]
        .filter(Boolean)
        .join(' ');
}

function minutesOf(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + minutes;
}

function discard(): void {
    if (saved.value) {
        draft.value = { ...saved.value };
    }

    timezone.value = context.value.clinic.timezone;
    errors.value = {};
}

async function save(): Promise<void> {
    const current = draft.value;

    if (!current) {
        return;
    }

    saving.value = true;
    errors.value = {};

    try {
        // The timezone lives on the clinic itself, so it saves separately.
        // It goes first: the hours below are wall-clock times in it.
        if (timezoneChanged.value) {
            await api.put(clinicUpdate.url(clinicId.value), {
                timezone: timezone.value,
            });
        }

        data.value = await api.put<Wrapped<ClinicSchedule>>(
            update.url(clinicId.value),
            {
                weekday_opens_at: current.weekdayOpensAt,
                weekday_closes_at: current.weekdayClosesAt,
                weekend_opens_at: current.weekendOpensAt,
                weekend_closes_at: current.weekendClosesAt,
                saturday_enabled: current.saturdayEnabled,
                sunday_enabled: current.sundayEnabled,
                allow_outside_hours: current.allowOutsideHours,
            },
        );
        // The timezone and the hours are shared with every clinic page (the
        // calendar reads both), so refresh them rather than leaving the rest
        // of the app on the old values until the next full page load.
        router.reload({ only: ['clinicContext'] });
        toast.success('Opening hours saved.');
    } catch (error) {
        if (error instanceof ApiError && error.status === 422) {
            errors.value = error.errors ?? {};
            toast.error('Check the hours and try again.');
        } else {
            toast.error('Could not save the opening hours.');
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head title="Schedule settings" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Schedule settings"
            description="When the clinic is open. The calendar and the time pickers follow these hours, and sessions outside them are refused unless overruns are allowed."
        />

        <div v-if="loading && !draft" class="space-y-4">
            <Skeleton class="h-56 w-full" />
            <Skeleton class="h-40 w-full" />
        </div>

        <template v-else-if="draft">
            <Card>
                <CardHeader>
                    <CardTitle>Timezone</CardTitle>
                    <CardDescription>
                        The clock the whole clinic runs on. Opening hours,
                        session times, daily-session windows, attendance days
                        and payout periods are all read in it.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <FormField
                        label="Clinic timezone"
                        for="clinic_timezone"
                        :error="errors.timezone?.[0]"
                    >
                        <TimezoneCombobox
                            id="clinic_timezone"
                            v-model="timezone"
                        />
                    </FormField>

                    <p
                        v-if="differsFromBrowser"
                        class="text-sm text-muted-foreground"
                    >
                        Your browser is set to
                        <button
                            type="button"
                            class="font-medium text-teal-700 underline underline-offset-4 dark:text-teal-400"
                            @click="timezone = browserZone"
                        >
                            {{ browserZone }}</button
                        >. Times will read differently here than on your own
                        clock.
                    </p>

                    <p
                        v-if="timezoneChanged"
                        class="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0 text-amber-600"
                        />
                        <span>
                            Sessions already booked keep the exact moment they
                            were booked for, so every one of them will read at a
                            different clock time after this is saved — and any
                            that land outside the opening hours below will drop
                            off the calendar grid until they are moved. Edit
                            each daily session afterwards to re-plan its
                            upcoming days.
                        </span>
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Weekdays</CardTitle>
                    <CardDescription>
                        Monday to Friday.
                        {{
                            lengthOf(
                                draft.weekdayOpensAt,
                                draft.weekdayClosesAt,
                            )
                        }}
                        a day.
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Opens"
                        for="weekday_opens_at"
                        :error="errors.weekday_opens_at?.[0]"
                    >
                        <Input
                            id="weekday_opens_at"
                            v-model="draft.weekdayOpensAt"
                            type="time"
                            step="900"
                        />
                    </FormField>
                    <FormField
                        label="Closes"
                        for="weekday_closes_at"
                        :error="errors.weekday_closes_at?.[0]"
                    >
                        <Input
                            id="weekday_closes_at"
                            v-model="draft.weekdayClosesAt"
                            type="time"
                            step="900"
                        />
                    </FormField>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Weekend</CardTitle>
                    <CardDescription>
                        Turn on the days the clinic works. Saturday and Sunday
                        are independent, and both use the hours below.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex flex-wrap gap-6">
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="saturday_enabled"
                                v-model="draft.saturdayEnabled"
                            />
                            <Label for="saturday_enabled"
                                >Open on Saturday</Label
                            >
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="sunday_enabled"
                                v-model="draft.sundayEnabled"
                            />
                            <Label for="sunday_enabled">Open on Sunday</Label>
                        </div>
                    </div>

                    <div v-if="opensWeekend" class="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Opens"
                            for="weekend_opens_at"
                            :error="errors.weekend_opens_at?.[0]"
                        >
                            <Input
                                id="weekend_opens_at"
                                v-model="draft.weekendOpensAt"
                                type="time"
                                step="900"
                            />
                        </FormField>
                        <FormField
                            label="Closes"
                            for="weekend_closes_at"
                            :error="errors.weekend_closes_at?.[0]"
                        >
                            <Input
                                id="weekend_closes_at"
                                v-model="draft.weekendClosesAt"
                                type="time"
                                step="900"
                            />
                        </FormField>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        The clinic is closed all weekend. Nothing can be booked
                        on a Saturday or a Sunday.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Booking outside the hours</CardTitle>
                    <CardDescription>
                        Off by default, so the hours above are the hours that
                        apply.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div class="flex items-start gap-2">
                        <Checkbox
                            id="allow_outside_hours"
                            v-model="draft.allowOutsideHours"
                            class="mt-0.5"
                        />
                        <div class="space-y-1">
                            <Label for="allow_outside_hours">
                                Allow overruns
                            </Label>
                            <p class="text-sm text-muted-foreground">
                                Lets sessions be booked at any time, including
                                on days the clinic is closed. The calendar still
                                follows the hours above, with an hour of slack
                                either side.
                            </p>
                        </div>
                    </div>

                    <p
                        v-if="draft.allowOutsideHours"
                        class="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0 text-amber-600"
                        />
                        <span>
                            Nothing will be refused for falling outside the
                            opening hours while this is on.
                        </span>
                    </p>
                    <InputError :message="errors.allow_outside_hours?.[0]" />
                </CardContent>
            </Card>

            <!--
                Sticky, like the payout settings page: the cards are taller
                than the viewport once the weekend hours open, and a save
                button scrolled off the bottom is a save button nobody finds.
            -->
            <div
                class="sticky bottom-0 -mx-4 -mb-4 flex flex-wrap items-center gap-3 border-t bg-background/85 px-4 py-3 backdrop-blur md:-mx-6 md:-mb-6 md:px-6"
            >
                <p class="mr-auto text-sm text-muted-foreground">
                    <template v-if="isDirty">Unsaved changes.</template>
                    <template v-else-if="data?.data.updated_at"
                        >Last changed
                        {{ formatDateTime(data.data.updated_at) }}.</template
                    >
                    <template v-else>Nothing changed yet.</template>
                </p>
                <Button
                    v-if="isDirty"
                    variant="ghost"
                    :disabled="saving"
                    @click="discard"
                >
                    <RotateCcw />
                    Discard changes
                </Button>
                <Button :disabled="!isDirty || saving" @click="save">
                    <Spinner v-if="saving" />
                    Save changes
                </Button>
            </div>
        </template>
    </div>
</template>
