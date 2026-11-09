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

const options = computed<CalendarOptions>(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin, listPlugin],
    initialView: props.initialView,
    initialDate: props.initialDate ?? undefined,
    headerToolbar: false,
    height: '100%',
    events: props.events,
    nowIndicator: true,
    allDaySlot: false,
    slotMinTime: '06:00:00',
    slotMaxTime: '22:00:00',
    scrollTime: '08:00:00',
    slotDuration: '00:30:00',
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
            start: arg.start,
            end: arg.end,
            title: arg.view.title,
            view: arg.view.type as CalendarView,
            currentStart: arg.view.currentStart,
        }),
    select: (arg: DateSelectArg) => {
        emit('select', { start: arg.start, end: arg.end });
        arg.view.calendar.unselect();
    },
    dateClick: (arg: DateClickArg) => {
        // In month view a click selects a whole day: suggest a one-hour slot at 9:00 instead.
        if (props.canCreate && arg.view.type === 'dayGridMonth') {
            const start = new Date(arg.date);
            start.setHours(9, 0, 0, 0);
            emit('select', {
                start,
                end: new Date(start.getTime() + 60 * 60 * 1000),
            });
        }
    },
    eventClick: (arg: EventClickArg) => emit('open', arg.event.id),
    eventChange: (arg: EventChangeArg) =>
        emit('move', {
            eventId: arg.event.id,
            start: arg.event.start!,
            end: arg.event.end ?? arg.event.start!,
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
