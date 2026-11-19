<script setup lang="ts">
import type {
    CalendarOptions,
    DateSelectArg,
    DatesSetArg,
    EventChangeArg,
    EventClickArg,
    EventContentArg,
    EventInput,
} from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import type { DateClickArg } from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import timeGridPlugin from '@fullcalendar/timegrid';
import FullCalendar from '@fullcalendar/vue3';
import { computed, useTemplateRef } from 'vue';
import { useClinic } from '@/composables/useClinic';
import { fromCalendarTime, toCalendarTime } from '@/lib/clinicTime';
import type { CalendarView } from '@/stores/schedule';

/**
 * Thin wrapper around FullCalendar. Loaded only in the browser (see
 * pages/admin/clinic/Schedule.vue): FullCalendar injects its own CSS and
 * needs `document`. Colours and typography come from resources/css/app.css.
 *
 * Event IDs say what an event is: `session-12` (a session) or `slot-34`
 * (a patient plotted into a daily session).
 */
type Props = {
    events: EventInput[];
    initialView: CalendarView;
    initialDate?: string | null;
    canCreate: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    range: [
        range: {
            start: Date;
            end: Date;
            title: string;
            view: CalendarView;
            currentStart: Date;
        },
    ];
    select: [range: { start: Date; end: Date }];
    open: [eventId: string];
    move: [
        change: {
            eventId: string;
            start: Date;
            end: Date;
            revert: () => void;
        },
    ];
}>();

const calendar = useTemplateRef<InstanceType<typeof FullCalendar>>('calendar');

const { schedule, openHours, context } = useClinic();
const timezone = computed(() => context.value.clinic.timezone);

const pad = (hour: number) => String(hour).padStart(2, '0');

/**
 * The grid spans the clinic's opening hours rather than a fixed working day,
 * so a clinic that opens at 07:00 sees 07:00 and one that closes at 15:00 is
 * not scrolling past empty evening rows. A clinic that allows booking
 * outside its hours gets an hour of slack either side, because that is
 * where those bookings land.
 */
const slack = computed(() => (schedule.value.allow_outside_hours ? 1 : 0));

const slotMinTime = computed(
    () => `${pad(Math.max(0, openHours.value.start - slack.value))}:00:00`,
);
const slotMaxTime = computed(
    () => `${pad(Math.min(24, openHours.value.end + slack.value))}:00:00`,
);

/*
 * The calendar draws the clinic's clock, not the viewer's laptop clock. A
 * session at 09:30 in a UTC clinic has to read 09:30 for everyone, the way
 * the day planner and the daily-session pages already show it.
 *
 * FullCalendar only knows "local" and "UTC" without a timezone plugin, so
 * events go in as wall-clock strings already converted to clinic time and
 * it is told they are UTC: it then shifts nothing. Everything crossing back
 * out (drags, selections, the now line) is converted here, so callers keep
 * working in real instants and never see this trick.
 */
const events = computed<EventInput[]>(() =>
    props.events.map((event) => ({
        ...event,
        start: event.start
            ? toCalendarTime(event.start as string | Date, timezone.value)
            : event.start,
        end: event.end
            ? toCalendarTime(event.end as string | Date, timezone.value)
            : event.end,
    })),
);

const toInstant = (value: Date) => fromCalendarTime(value, timezone.value);

const options = computed<CalendarOptions>(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin, listPlugin],
    initialView: props.initialView,
    initialDate: props.initialDate ?? undefined,
    headerToolbar: false,
    height: '100%',
    events: events.value,
    timeZone: 'UTC',
    // The now line has to follow the clinic's clock too, which means feeding
    // it the clinic's wall time rather than the browser's.
    now: () => toCalendarTime(new Date(), timezone.value),
    nowIndicator: true,
    allDaySlot: false,
    slotMinTime: slotMinTime.value,
    slotMaxTime: slotMaxTime.value,
    scrollTime: `${pad(openHours.value.start)}:00:00`,
    slotDuration: '00:30:00',
    // Grey out the days the clinic does not open, so an empty Sunday reads
    // as "closed" rather than "nothing booked".
    businessHours: {
        daysOfWeek: schedule.value.open_weekdays.map((day) => day % 7),
        startTime: schedule.value.opens_at ?? '08:00',
        endTime: schedule.value.closes_at ?? '17:00',
    },
    expandRows: true,
    dayMaxEvents: 3,
    firstDay: 1,
    selectable: props.canCreate,
    selectMirror: true,
    eventDurationEditable: true,
    // "9 – 10:30am" style: drop ":00" so the range fits narrow week columns.
    eventTimeFormat: {
        hour: 'numeric',
        minute: '2-digit',
        meridiem: 'short',
        omitZeroMinute: true,
    },
    slotLabelFormat: { hour: 'numeric', meridiem: 'short' },
    dayHeaderFormat: { weekday: 'short', day: 'numeric' },
    datesSet: (arg: DatesSetArg) =>
        emit('range', {
            start: toInstant(arg.start),
            end: toInstant(arg.end),
            title: arg.view.title,
            view: arg.view.type as CalendarView,
            currentStart: toInstant(arg.view.currentStart),
        }),
    select: (arg: DateSelectArg) => {
        emit('select', {
            start: toInstant(arg.start),
            end: toInstant(arg.end),
        });
        arg.view.calendar.unselect();
    },
    dateClick: (arg: DateClickArg) => {
        // In month view a click selects a whole day: suggest a one-hour slot at 9:00 instead.
        if (props.canCreate && arg.view.type === 'dayGridMonth') {
            // Clinic 09:00, so the suggestion matches the clinic's clock.
            const start = new Date(arg.date);
            start.setUTCHours(9, 0, 0, 0);
            const instant = toInstant(start);

            emit('select', {
                start: instant,
                end: new Date(instant.getTime() + 60 * 60 * 1000),
            });
        }
    },
    eventClick: (arg: EventClickArg) => emit('open', arg.event.id),
    eventChange: (arg: EventChangeArg) =>
        emit('move', {
            eventId: arg.event.id,
            start: toInstant(arg.event.start!),
            end: toInstant(arg.event.end ?? arg.event.start!),
            revert: arg.revert,
        }),
}));

const api = () => calendar.value?.getApi();

defineExpose({
    prev: () => api()?.prev(),
    next: () => api()?.next(),
    today: () => api()?.today(),
    changeView: (view: CalendarView) => api()?.changeView(view),
});

defineSlots<{ event(props: { arg: EventContentArg }): unknown }>();
</script>

<template>
    <FullCalendar ref="calendar" :options="options" class="session-calendar">
        <template #eventContent="arg">
            <slot name="event" :arg="arg" />
        </template>
    </FullCalendar>
</template>
