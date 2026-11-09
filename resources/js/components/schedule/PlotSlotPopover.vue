<script setup lang="ts">
import { Clock } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatClinicRange, formatClinicTime } from '@/lib/clinicTime';
import type { Vacancy } from '@/types';

/**
 * Pick a free slot for a patient on a day of a daily session: a length, then
 * one of the start times that fit in the staff member's vacant time.
 */
type Props = {
    vacancies: Vacancy[];
    /** Times are shown in this timezone (the clinic's). */
    timeZone: string;
    label?: string;
    disabled?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    label: 'Plot',
    disabled: false,
});
const emit = defineEmits<{
    plot: [slot: { starts_at: string; ends_at: string }];
}>();

const open = ref(false);
const minutes = ref('30');
const start = ref('');

const lengths = ['15', '30', '45', '60', '90', '120'];

/** Every 15-minute start that leaves room for the chosen length. */
const starts = computed(() => {
    const length = Number(minutes.value) * 60000;

    return props.vacancies.flatMap((vacancy) => {
        const options: string[] = [];
        const end = new Date(vacancy.ends_at).getTime();

        for (
            let time = new Date(vacancy.starts_at).getTime();
            time + length <= end;
            time += 15 * 60000
        ) {
            options.push(new Date(time).toISOString());
        }

        return options;
    });
});

watch(
    [open, starts],
    () => {
        if (!starts.value.includes(start.value)) {
            start.value = starts.value[0] ?? '';
        }
    },
    { immediate: true },
);

const end = computed(() =>
    start.value
        ? new Date(
              new Date(start.value).getTime() + Number(minutes.value) * 60000,
          ).toISOString()
        : '',
);

function submit(): void {
    if (!start.value) {
        return;
    }

    emit('plot', { starts_at: start.value, ends_at: end.value });
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                size="sm"
                variant="outline"
                class="h-7"
                :disabled="disabled"
            >
                <Clock />
                {{ label }}
            </Button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-72 space-y-3">
            <p class="text-sm font-medium">Plot into a free slot</p>
            <div class="grid grid-cols-[6rem_1fr] gap-2">
                <Select v-model="minutes">
                    <SelectTrigger size="sm" aria-label="Length">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="length in lengths"
                            :key="length"
                            :value="length"
                            >{{ length }} min</SelectItem
                        >
                    </SelectContent>
                </Select>
                <Select v-model="start" :disabled="starts.length === 0">
                    <SelectTrigger size="sm" aria-label="Start time">
                        <SelectValue placeholder="No free slot" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in starts"
                            :key="option"
                            :value="option"
                            >{{
                                formatClinicTime(option, timeZone)
                            }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <p v-if="starts.length === 0" class="text-xs text-muted-foreground">
                No free time of {{ minutes }} minutes left on this day. Try a
                shorter slot or unplot someone.
            </p>
            <p v-else-if="start" class="text-xs text-muted-foreground">
                {{ formatClinicRange(start, end, timeZone) }}
            </p>
            <Button size="sm" class="w-full" :disabled="!start" @click="submit"
                >Plot</Button
            >
        </PopoverContent>
    </Popover>
</template>
