<script setup lang="ts">
import {
    CalendarDays,
    Minus,
    Pencil,
    Trash2,
    TrendingDown,
    TrendingUp,
    UserRound,
} from '@lucide/vue';
import type { Component } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateOnly, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PatientProgressNote } from '@/types';

/**
 * A patient's progress notes, oldest first, read as one story: each entry
 * rates the change since the one above it. The rail is coloured by that
 * change, so a run of red dots is visible before a word is read.
 */
defineProps<{
    notes: PatientProgressNote[];
    /** Whether the viewer may change a note: its author, or a Clinic Admin. */
    canEdit: (note: PatientProgressNote) => boolean;
}>();

const emit = defineEmits<{
    edit: [note: PatientProgressNote];
    delete: [note: PatientProgressNote];
}>();

/** 1 better, 0 flat, -1 worse. */
function directionIcon(direction: number): Component {
    if (direction > 0) {
        return TrendingUp;
    }

    if (direction < 0) {
        return TrendingDown;
    }

    return Minus;
}

function badgeTone(direction: number): string {
    if (direction > 0) {
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300';
    }

    if (direction < 0) {
        return 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300';
    }

    return 'bg-muted text-muted-foreground';
}

function dotTone(direction: number): string {
    if (direction > 0) {
        return 'bg-emerald-500';
    }

    if (direction < 0) {
        return 'bg-rose-500';
    }

    return 'bg-muted-foreground/40';
}

const numbers = new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 });

/** "6.00" with "/10" reads as "6/10"; a worded unit keeps its space. */
function withUnit(value: string, unit: string | null): string {
    const shown = numbers.format(Number(value));

    if (!unit) {
        return shown;
    }

    return /^[a-z0-9]/i.test(unit) ? `${shown} ${unit}` : `${shown}${unit}`;
}
</script>

<template>
    <ol class="flex flex-col">
        <li
            v-for="(note, index) in notes"
            :key="note.id"
            class="group flex gap-4"
        >
            <!-- The rail: one dot per note, coloured by which way it moved. -->
            <div class="flex w-3 shrink-0 flex-col items-center">
                <span
                    :class="
                        cn(
                            'mt-1.5 size-3 shrink-0 rounded-full',
                            dotTone(note.direction),
                        )
                    "
                    aria-hidden="true"
                />
                <span
                    v-if="index < notes.length - 1"
                    class="mt-1 w-px flex-1 bg-border"
                    aria-hidden="true"
                />
            </div>

            <div class="min-w-0 flex-1 pb-6 group-last:pb-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium tabular-nums">
                        {{ formatDateOnly(note.recorded_on) }}
                    </span>
                    <Badge
                        variant="secondary"
                        :class="
                            cn(
                                'gap-1 border-transparent',
                                badgeTone(note.direction),
                            )
                        "
                    >
                        <component
                            :is="directionIcon(note.direction)"
                            aria-hidden="true"
                        />
                        {{ note.progress_label }}
                    </Badge>
                    <div
                        v-if="canEdit(note)"
                        class="ml-auto flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100"
                    >
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-7"
                            :aria-label="`Edit the note from ${formatDateOnly(note.recorded_on)}`"
                            @click="emit('edit', note)"
                        >
                            <Pencil />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-7 text-muted-foreground hover:text-destructive"
                            :aria-label="`Delete the note from ${formatDateOnly(note.recorded_on)}`"
                            @click="emit('delete', note)"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </div>

                <p class="mt-1 font-medium">{{ note.summary }}</p>
                <p
                    v-if="note.body"
                    class="mt-1 text-sm whitespace-pre-line text-muted-foreground"
                >
                    {{ note.body }}
                </p>

                <div
                    v-if="note.plan"
                    class="mt-2 rounded-md border-l-2 border-teal-500 bg-muted/50 px-3 py-2"
                >
                    <div
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Plan
                    </div>
                    <p class="mt-0.5 text-sm whitespace-pre-line">
                        {{ note.plan }}
                    </p>
                </div>

                <div
                    v-if="note.metrics?.length"
                    class="mt-2 flex flex-wrap gap-1.5"
                >
                    <Badge
                        v-for="metric in note.metrics"
                        :key="metric.id"
                        variant="outline"
                        class="gap-1 font-normal"
                    >
                        <span class="text-muted-foreground">{{
                            metric.name
                        }}</span>
                        <span class="font-medium tabular-nums">{{
                            withUnit(metric.value, metric.unit)
                        }}</span>
                    </Badge>
                </div>

                <div
                    class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                >
                    <span class="inline-flex items-center gap-1">
                        <UserRound class="size-3" aria-hidden="true" />
                        {{ note.author?.user?.name ?? 'Unknown member' }}
                    </span>
                    <span
                        v-if="note.session"
                        class="inline-flex items-center gap-1"
                    >
                        <CalendarDays class="size-3" aria-hidden="true" />
                        {{ note.session.label }} ·
                        {{ formatDateTime(note.session.starts_at) }}
                    </span>
                </div>
            </div>
        </li>
    </ol>
</template>
