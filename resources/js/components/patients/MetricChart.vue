<script setup lang="ts">
import { usePreferredReducedMotion } from '@vueuse/core';
import type { CSSProperties } from 'vue';
import { computed, onMounted, ref } from 'vue';
import type { MetricSeries } from '@/types';

/**
 * One measurement drawn over time, e.g. "Pain" from 8 down to 3 across a
 * month. The rating on a note is a judgement; a metric is the number behind
 * it, so improvement can be seen rather than taken on trust. Drawn by hand
 * in SVG — the app carries no charting library.
 */
const props = defineProps<{ series: MetricSeries }>();

/** The chart is stretched to the card's width, so these are viewBox units. */
const width = 300;
const height = 80;
const paddingX = 6;
const paddingY = 10;

const numbers = new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 });

/** "6.00" with "/10" reads as "6/10"; a worded unit keeps its space. */
function withUnit(reading: number): string {
    const shown = numbers.format(reading);
    const unit = props.series.unit;

    if (!unit) {
        return shown;
    }

    return /^[a-z0-9]/i.test(unit) ? `${shown} ${unit}` : `${shown}${unit}`;
}

/** "2026-10-01" → "1 Oct", read as a plain date (no timezone shifting). */
function shortDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
    });
}

const readings = computed(() =>
    props.series.points.map((point) => ({
        date: point.date,
        reading: Number(point.value),
    })),
);

const lowest = computed(() =>
    Math.min(...readings.value.map((point) => point.reading)),
);
const highest = computed(() =>
    Math.max(...readings.value.map((point) => point.reading)),
);

/** Every reading placed in the viewBox. A flat series sits on the midline. */
const plotted = computed(() => {
    const points = readings.value;
    const span = highest.value - lowest.value;

    return points.map((point, index) => ({
        ...point,
        x:
            points.length === 1
                ? width / 2
                : paddingX +
                  (index / (points.length - 1)) * (width - paddingX * 2),
        y:
            span === 0
                ? height / 2
                : paddingY +
                  (1 - (point.reading - lowest.value) / span) *
                      (height - paddingY * 2),
    }));
});

const polyline = computed(() =>
    plotted.value
        .map((point) => `${point.x.toFixed(1)},${point.y.toFixed(1)}`)
        .join(' '),
);

/** The drawn length, in viewBox units, so the line can be dashed into view. */
const lineLength = computed(() =>
    plotted.value.reduce((total, point, index) => {
        if (index === 0) {
            return 0;
        }

        const previous = plotted.value[index - 1];

        return total + Math.hypot(point.x - previous.x, point.y - previous.y);
    }, 0),
);

const first = computed(() => readings.value[0] ?? null);
const latest = computed(
    () => readings.value[readings.value.length - 1] ?? null,
);

const label = computed(() => {
    const start = first.value;
    const end = latest.value;
    const name = props.series.name;

    if (!start || !end) {
        return `${name}: nothing measured yet.`;
    }

    if (readings.value.length === 1) {
        return `${name}: ${withUnit(start.reading)} on ${shortDate(start.date)}.`;
    }

    if (start.reading === end.reading) {
        return `${name}: steady at ${withUnit(end.reading)} from ${shortDate(start.date)} to ${shortDate(end.date)}.`;
    }

    const way = end.reading > start.reading ? 'up' : 'down';

    return `${name}: ${withUnit(start.reading)} on ${shortDate(start.date)} ${way} to ${withUnit(end.reading)} on ${shortDate(end.date)}.`;
});

// The line draws itself in once, unless the viewer asked for less motion.
const reducedMotion = usePreferredReducedMotion();
const drawn = ref(false);

onMounted(() => requestAnimationFrame(() => (drawn.value = true)));

const lineStyle = computed<CSSProperties | undefined>(() => {
    if (reducedMotion.value === 'reduce') {
        return undefined;
    }

    return {
        strokeDasharray: lineLength.value,
        strokeDashoffset: drawn.value ? 0 : lineLength.value,
        transition: 'stroke-dashoffset 700ms ease-out',
    };
});
</script>

<template>
    <div class="rounded-xl border bg-card p-3 shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="truncate text-sm font-medium">
                    {{ series.name }}
                </div>
                <div class="text-xs text-muted-foreground">
                    {{ readings.length }}
                    {{ readings.length === 1 ? 'reading' : 'readings' }}
                </div>
            </div>
            <div v-if="latest" class="shrink-0 text-right">
                <div
                    class="text-2xl leading-none font-semibold text-teal-700 tabular-nums dark:text-teal-300"
                >
                    {{ withUnit(latest.reading) }}
                </div>
                <div class="mt-1 text-xs text-muted-foreground">Latest</div>
            </div>
        </div>

        <p
            v-if="readings.length === 0"
            class="mt-3 text-sm text-muted-foreground"
        >
            Nothing measured yet.
        </p>

        <div v-else class="mt-3 flex items-stretch gap-2">
            <!-- The scale, so the line's height means something. -->
            <div
                class="flex w-10 shrink-0 flex-col justify-between text-right text-xs text-muted-foreground tabular-nums"
                aria-hidden="true"
            >
                <span>{{ numbers.format(highest) }}</span>
                <span v-if="highest !== lowest">{{
                    numbers.format(lowest)
                }}</span>
            </div>
            <svg
                class="h-20 min-w-0 flex-1"
                viewBox="0 0 300 80"
                preserveAspectRatio="none"
                role="img"
                :aria-label="label"
            >
                <polyline
                    v-if="readings.length > 1"
                    :points="polyline"
                    fill="none"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    vector-effect="non-scaling-stroke"
                    class="stroke-teal-600 dark:stroke-teal-400"
                    :style="lineStyle"
                />
                <circle
                    v-for="(point, index) in plotted"
                    :key="index"
                    :cx="point.x"
                    :cy="point.y"
                    r="2.5"
                    class="fill-teal-600 dark:fill-teal-400"
                />
            </svg>
        </div>

        <div
            v-if="first && latest"
            class="mt-1 flex justify-between pl-12 text-xs text-muted-foreground"
            aria-hidden="true"
        >
            <span>{{ shortDate(first.date) }}</span>
            <span v-if="readings.length > 1">{{ shortDate(latest.date) }}</span>
        </div>
    </div>
</template>
