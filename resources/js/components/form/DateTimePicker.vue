<script setup lang="ts">
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import DatePicker from './DatePicker.vue';

/**
 * Date + time in 15-minute steps. v-model uses the datetime-local format in the
 * viewer's timezone ("2026-11-04T09:30"); convert with fromDateTimeLocal() from
 * '@/lib/format' before sending it to the API.
 */
type Props = {
    id?: string;
    /** First and last selectable hour of the day. */
    startHour?: number;
    endHour?: number;
};

const props = withDefaults(defineProps<Props>(), { startHour: 6, endHour: 22 });
const model = defineModel<string>({ default: '' });

const date = computed({
    get: () => model.value.slice(0, 10),
    set: (value: string) =>
        (model.value = value ? `${value}T${time.value || '09:00'}` : ''),
});

const time = computed({
    get: () => model.value.slice(11, 16),
    set: (value: string) => (model.value = `${date.value}T${value}`),
});

const pad = (n: number) => String(n).padStart(2, '0');

/** 15-minute slots, plus the current value if it is off the grid (e.g. 09:10). */
const times = computed(() => {
    const slots: string[] = [];

    for (let hour = props.startHour; hour <= props.endHour; hour++) {
        for (const minute of [0, 15, 30, 45]) {
            slots.push(`${pad(hour)}:${pad(minute)}`);
        }
    }

    if (time.value && !slots.includes(time.value)) {
        slots.push(time.value);
        slots.sort();
    }

    return slots;
});

const timeLabel = (value: string): string => {
    const [hours, minutes] = value.split(':').map(Number);

    return new Date(2000, 0, 1, hours, minutes).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
};
</script>

<template>
    <div class="flex gap-2">
        <DatePicker :id="id" v-model="date" class="flex-1" />
        <Select v-model="time" :disabled="!date">
            <SelectTrigger class="w-32 shrink-0" aria-label="Time">
                <SelectValue placeholder="Time">{{
                    time ? timeLabel(time) : 'Time'
                }}</SelectValue>
            </SelectTrigger>
            <SelectContent class="max-h-64">
                <SelectItem v-for="slot in times" :key="slot" :value="slot">{{
                    timeLabel(slot)
                }}</SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
