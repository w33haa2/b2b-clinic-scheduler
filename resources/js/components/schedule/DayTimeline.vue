<script setup lang="ts">
import type {
    CalendarOptions,
    EventChangeArg,
    EventClickArg,
    EventInput,
} from '@fullcalendar/core';
import interactionPlugin, { Draggable } from '@fullcalendar/interaction';
import type {
    DateClickArg,
    DropArg,
    EventDragStopArg,
} from '@fullcalendar/interaction';
import timeGridPlugin from '@fullcalendar/timegrid';
import FullCalendar from '@fullcalendar/vue3';
import { computed, onBeforeUnmount, watch } from 'vue';
import { minutesOf, timeOf } from '@/lib/clinicTime';

/**
 * A one-day timeline of a daily session's hours where patients are planned
 * by drag and drop: drag a card from `source` onto a time, drag a block to
 * move it, drag its bottom edge to change its length, or drag it onto
 * `trash` to take it off. Clicking an empty time emits `pick` (click-to-place,
 * easier than dragging on touch screens). Loaded only in the browser (FullCalendar needs
 * `document`).
 *
 * Everything is in clinic wall-clock time ("HH:MM"): the calendar runs in
 * UTC and each "HH:MM" on `date` is drawn as that UTC time, so the grid shows
 * the clinic's hours whatever the browser's timezone.
 */
export type TimelineBlock = {
    id: string;
    title: string;
    subtitle?: string;
    start: string;
    end: string;
    editable?: boolean;
    tone?: 'default' | 'changed' | 'paid';
    selected?: boolean;
};

type Props = {
    /** The day shown ("YYYY-MM-DD"); any fixed date for a regular-plan template. */
    date: string;
    window: { start: string; end: string };
    blocks: TimelineBlock[];
    /** Time taken by other things (shown greyed out). */
    busy?: Array<{ start: string; end: string }>;
    /** Container of draggable cards: `[data-drag-id]`, optional `data-minutes`. */
    source?: HTMLElement | null;
    /** Dropping a block here takes it off the timeline. */
    trash?: HTMLElement | null;
    editable?: boolean;
    /** The clinic's current time ("HH:MM") when `date` is today: draws a now line. */
    now?: string | null;
};

const props = withDefaults(defineProps<Props>(), {
    busy: () => [],
    source: null,
    trash: null,
    editable: true,
    now: null,
});

const emit = defineEmits<{
    drop: [change: { id: string; start: string; end: string }];
    change: [
        change: { id: string; start: string; end: string; revert: () => void },
    ];
    remove: [id: string];
    select: [id: string];
    pick: [time: string];
}>();

const at = (time: string) => `${props.date}T${time}:00Z`;
const wallTime = (date: Date) =>
    timeOf(date.getUTCHours() * 60 + date.getUTCMinutes());

const events = computed<EventInput[]>(() => [
    ...props.busy.map((interval, index) => ({
        id: `busy-${index}`,
        start: at(interval.start),
        end: at(interval.end),
        display: 'background',
        classNames: ['timeline-busy'],
    })),
    ...props.blocks.map((block) => ({
        id: block.id,
        title: block.title,
        start: at(block.start),
        end: at(block.end),
        editable: props.editable && block.editable !== false,
        classNames: [
            'timeline-block',
            `timeline-${block.tone ?? 'default'}`,
            block.selected ? 'timeline-selected' : '',
        ],
        extendedProps: { subtitle: block.subtitle },
    })),
]);

const options = computed<CalendarOptions>(() => ({
    plugins: [timeGridPlugin, interactionPlugin],
    initialView: 'timeGridDay',
    initialDate: props.date,
    timeZone: 'UTC',
    headerToolbar: false,
    dayHeaders: false,
    allDaySlot: false,
    height: 'auto',
    slotMinTime: `${props.window.start}:00`,
    slotMaxTime: `${props.window.end}:00`,
    slotDuration: '00:15:00',
    slotLabelInterval: '01:00',
    snapDuration: '00:15:00',
    slotLabelFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
    eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
    eventOverlap: false,
    nowIndicator: props.now !== null,
    now: props.now !== null ? at(props.now) : undefined,
    eventLongPressDelay: 250,
    droppable: props.editable,
    editable: props.editable,
    events: events.value,
    drop: (arg: DropArg) => {
        const id = arg.draggedEl.dataset.dragId;
        const minutes = Number(arg.draggedEl.dataset.minutes || 30);

        if (!id) {
            return;
        }

        const start = wallTime(arg.date);
        const end = timeOf(
            Math.min(minutesOf(start) + minutes, minutesOf(props.window.end)),
        );

        emit('drop', { id, start, end });
    },
    eventChange: (arg: EventChangeArg) =>
        emit('change', {
            id: arg.event.id,
            start: wallTime(arg.event.start!),
            end: wallTime(arg.event.end ?? arg.event.start!),
            revert: arg.revert,
        }),
    eventDragStop: (arg: EventDragStopArg) => {
        const box = props.trash?.getBoundingClientRect();
        const { clientX, clientY } = arg.jsEvent as MouseEvent;

        if (
            box &&
            clientX >= box.left &&
            clientX <= box.right &&
            clientY >= box.top &&
            clientY <= box.bottom
        ) {
            emit('remove', arg.event.id);
        }
    },
    eventClick: (arg: EventClickArg) => emit('select', arg.event.id),
    dateClick: (arg: DateClickArg) => emit('pick', wallTime(arg.date)),
}));

// Cards in `source` can be dragged onto the grid.
let draggable: Draggable | null = null;

watch(
    () => props.source,
    (source) => {
        draggable?.destroy();
        draggable = null;

        if (source && props.editable) {
            draggable = new Draggable(source, {
                itemSelector: '[data-drag-id]',
                longPressDelay: 250,
                eventData: (el) => ({
                    title: el.dataset.title ?? '',
                    duration: { minutes: Number(el.dataset.minutes || 30) },
                    create: false,
                }),
            });
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => draggable?.destroy());
</script>

<template>
    <FullCalendar
        :key="date"
        :options="options"
        class="session-calendar day-timeline"
    >
        <template #eventContent="arg">
            <div
                class="flex h-full min-w-0 flex-col overflow-hidden px-1.5 py-0.5 text-xs leading-tight"
            >
                <span class="truncate font-semibold">{{
                    arg.event.title
                }}</span>
                <span class="truncate tabular-nums opacity-80">
                    {{ arg.timeText }}
                    <template v-if="arg.event.extendedProps.subtitle">
                        · {{ arg.event.extendedProps.subtitle }}
                    </template>
                </span>
            </div>
        </template>
    </FullCalendar>
</template>
