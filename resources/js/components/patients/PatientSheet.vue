<script setup lang="ts">
import {
    CalendarDays,
    ChevronRight,
    Download,
    History,
    Minus,
    Pencil,
    TrendingDown,
    TrendingUp,
    UserRound,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import StatusBadge from '@/components/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { ApiError } from '@/lib/api';
import { downloadFile } from '@/lib/download';
import {
    ageFrom,
    formatDate,
    formatDateOnly,
    formatMoney,
    formatTime,
    patientName,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { pdf, show } from '@/routes/clinic/patients';
import { index as historyIndex } from '@/routes/clinic/patients/history';
import type {
    Patient,
    PatientHistory,
    PatientHistoryEntry,
    Wrapped,
} from '@/types';

/**
 * One patient, read-only: the record as the clinic's own form defines it,
 * and everything that has happened to them in one timeline.
 */
type Props = {
    patientId: number | null;
};

const props = defineProps<Props>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ changed: []; edit: [patient: Patient] }>();

const { clinicId, can } = useClinic();

const tab = ref('details');
const downloading = ref(false);

const {
    data: record,
    loading,
    error,
    refresh: refreshRecord,
} = useApiQuery<Wrapped<Patient>>(() =>
    props.patientId
        ? show.url({ clinic: clinicId.value, patient: props.patientId })
        : null,
);

/** The answer for *this* patient: the query keeps the last one it loaded. */
const patient = computed(() =>
    record.value?.data.id === props.patientId ? record.value.data : null,
);

// The timeline is a second round-trip, so it waits until the tab is opened.
const {
    data: history,
    loading: historyLoading,
    refresh: refreshHistory,
} = useApiQuery<Wrapped<PatientHistory>>(() =>
    props.patientId && tab.value === 'history'
        ? historyIndex.url({ clinic: clinicId.value, patient: props.patientId })
        : null,
);

const entries = computed(() =>
    history.value?.data.patient_id === props.patientId
        ? history.value.data.entries
        : [],
);

watch(
    () => props.patientId,
    () => (tab.value = 'details'),
);

const fullName = computed(() =>
    patient.value
        ? [
              patient.value.first_name,
              patient.value.middle_name,
              patient.value.last_name,
          ]
              .filter(Boolean)
              .join(' ')
        : '',
);

const subtitle = computed(() => {
    if (!patient.value) {
        return '';
    }

    const age = ageFrom(patient.value.birth_date);

    return (
        [age === null ? null : `${age} years old`, patient.value.sex]
            .filter(Boolean)
            .join(' · ') || 'No age or sex on record'
    );
});

/** "emergency_contact" reads as "Emergency contact" when we have no label. */
function humanize(key: string): string {
    const words = key.replaceAll('_', ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

const isPlainDate = /^\d{4}-\d{2}-\d{2}$/;

/** An answer of any shape as one readable line. */
function formatAnswer(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.length
            ? value.map((item) => formatAnswer(item)).join(', ')
            : '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'string') {
        return isPlainDate.test(value) ? formatDateOnly(value) : value;
    }

    return String(value);
}

type Detail = { label: string; value: string };

const builtIn = computed<Detail[]>(() => {
    const person = patient.value;

    if (!person) {
        return [];
    }

    const age = ageFrom(person.birth_date);

    return [
        { label: 'First name', value: person.first_name },
        { label: 'Middle name', value: formatAnswer(person.middle_name) },
        { label: 'Last name', value: person.last_name },
        {
            label: 'Birth date',
            value: person.birth_date
                ? `${formatDateOnly(person.birth_date)} (${age})`
                : '—',
        },
        { label: 'Sex', value: formatAnswer(person.sex) },
        { label: 'Email', value: formatAnswer(person.email) },
        { label: 'Phone', value: formatAnswer(person.phone) },
        { label: 'Address', value: formatAnswer(person.address) },
        { label: 'Notes', value: formatAnswer(person.notes) },
        { label: 'Added', value: formatDate(person.created_at) },
    ];
});

const custom = computed<Detail[]>(() =>
    Object.entries(patient.value?.fields ?? {}).map(([key, value]) => ({
        label: humanize(key),
        value: formatAnswer(value),
    })),
);

const archived = computed<Detail[]>(() =>
    (patient.value?.archived_fields ?? []).map((field) => ({
        label: field.label,
        value: formatAnswer(field.value),
    })),
);

/** The timeline comes back newest first, so days fall out in order. */
const days = computed(() => {
    const groups: Array<{
        key: string;
        label: string;
        entries: PatientHistoryEntry[];
    }> = [];

    for (const entry of entries.value) {
        const at = new Date(entry.at);
        const key = `${at.getFullYear()}-${at.getMonth()}-${at.getDate()}`;
        const current = groups.at(-1);

        if (current?.key === key) {
            current.entries.push(entry);
        } else {
            groups.push({ key, label: formatDate(entry.at), entries: [entry] });
        }
    }

    return groups;
});

function entryIcon(entry: PatientHistoryEntry): Component {
    if (entry.type === 'session') {
        return CalendarDays;
    }

    if (entry.type === 'record_change') {
        return History;
    }

    const direction = entry.direction ?? 0;

    if (direction > 0) {
        return TrendingUp;
    }

    return direction < 0 ? TrendingDown : Minus;
}

function entryTone(entry: PatientHistoryEntry): string {
    if (entry.type === 'session') {
        return 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300';
    }

    if (entry.type === 'record_change') {
        return 'bg-muted text-muted-foreground';
    }

    const direction = entry.direction ?? 0;

    if (direction > 0) {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300';
    }

    if (direction < 0) {
        return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300';
    }

    return 'bg-muted text-muted-foreground';
}

async function downloadRecord(): Promise<void> {
    if (!patient.value) {
        return;
    }

    downloading.value = true;

    try {
        await downloadFile(
            pdf.url({ clinic: clinicId.value, patient: patient.value.id }),
            `${patientName(patient.value)}.pdf`,
        );
    } catch (caught) {
        toast.error(
            caught instanceof ApiError
                ? caught.firstMessage
                : 'Could not download the record.',
        );
    } finally {
        downloading.value = false;
    }
}

/** Pick up an edit made elsewhere, then let the list follow suit. */
async function reload(): Promise<void> {
    await Promise.all([refreshRecord(), refreshHistory()]);
    emit('changed');
}

defineExpose({ reload });
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 overflow-y-auto sm:max-w-xl">
            <SheetHeader class="border-b">
                <template v-if="patient">
                    <SheetTitle class="text-xl">{{ fullName }}</SheetTitle>
                    <SheetDescription
                        class="flex items-center gap-1.5 capitalize"
                    >
                        <UserRound class="size-4" aria-hidden="true" />
                        {{ subtitle }}
                    </SheetDescription>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="downloading"
                            @click="downloadRecord"
                        >
                            <Spinner v-if="downloading" />
                            <Download v-else />
                            Download record
                        </Button>
                        <Button
                            v-if="can('clinic.patients.update')"
                            size="sm"
                            @click="emit('edit', patient)"
                        >
                            <Pencil />
                            Edit
                        </Button>
                    </div>
                </template>
                <template v-else>
                    <SheetTitle><Skeleton class="h-6 w-48" /></SheetTitle>
                    <SheetDescription as-child>
                        <Skeleton class="h-4 w-32" />
                    </SheetDescription>
                </template>
            </SheetHeader>

            <p v-if="error && !patient" class="p-4 text-sm text-destructive">
                {{ error.firstMessage }}
            </p>

            <div v-else-if="loading && !patient" class="space-y-3 p-4">
                <Skeleton v-for="row in 6" :key="row" class="h-10" />
            </div>

            <Tabs v-else-if="patient" v-model="tab" class="gap-0">
                <div class="px-4 pt-4">
                    <TabsList>
                        <TabsTrigger value="details">Details</TabsTrigger>
                        <TabsTrigger value="history">History</TabsTrigger>
                    </TabsList>
                </div>

                <TabsContent value="details" class="space-y-6 p-4">
                    <dl class="grid gap-x-4 gap-y-3 sm:grid-cols-2">
                        <div v-for="field in builtIn" :key="field.label">
                            <dt class="text-xs text-muted-foreground">
                                {{ field.label }}
                            </dt>
                            <dd class="text-sm break-words whitespace-pre-line">
                                {{ field.value }}
                            </dd>
                        </div>
                    </dl>

                    <div v-if="custom.length" class="space-y-3">
                        <h3
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            From the patient form
                        </h3>
                        <dl class="grid gap-x-4 gap-y-3 sm:grid-cols-2">
                            <div v-for="field in custom" :key="field.label">
                                <dt class="text-xs text-muted-foreground">
                                    {{ field.label }}
                                </dt>
                                <dd
                                    class="text-sm break-words whitespace-pre-line"
                                >
                                    {{ field.value }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Kept on purpose: an answer the patient gave outlives the question. -->
                    <Collapsible v-if="archived.length" class="group/archived">
                        <CollapsibleTrigger
                            class="flex w-full items-center gap-1.5 rounded-md text-xs font-semibold tracking-wide text-muted-foreground uppercase hover:text-foreground"
                        >
                            <ChevronRight
                                class="size-3.5 transition-transform group-data-[state=open]/archived:rotate-90 motion-reduce:transition-none"
                                aria-hidden="true"
                            />
                            No longer on the form ({{ archived.length }})
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <dl
                                class="mt-3 grid gap-x-4 gap-y-3 rounded-md bg-muted/50 p-3 text-muted-foreground sm:grid-cols-2"
                            >
                                <div
                                    v-for="field in archived"
                                    :key="field.label"
                                >
                                    <dt class="text-xs">{{ field.label }}</dt>
                                    <dd
                                        class="text-sm break-words whitespace-pre-line"
                                    >
                                        {{ field.value }}
                                    </dd>
                                </div>
                            </dl>
                        </CollapsibleContent>
                    </Collapsible>
                </TabsContent>

                <TabsContent value="history" class="space-y-4 p-4">
                    <div
                        v-if="historyLoading && !days.length"
                        class="space-y-3"
                    >
                        <Skeleton v-for="row in 5" :key="row" class="h-14" />
                    </div>

                    <p
                        v-else-if="!days.length"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Nothing has happened to this record yet. Sessions,
                        progress notes and changes will show up here.
                    </p>

                    <template v-else>
                        <section
                            v-for="day in days"
                            :key="day.key"
                            class="space-y-2"
                        >
                            <h3
                                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase tabular-nums"
                            >
                                {{ day.label }}
                            </h3>
                            <ol class="flex flex-col">
                                <li
                                    v-for="(entry, index) in day.entries"
                                    :key="`${entry.type}-${entry.at}-${index}`"
                                    class="group flex gap-3"
                                >
                                    <div
                                        class="flex w-7 shrink-0 flex-col items-center"
                                    >
                                        <span
                                            :class="
                                                cn(
                                                    'flex size-7 items-center justify-center rounded-full [&>svg]:size-3.5',
                                                    entryTone(entry),
                                                )
                                            "
                                            aria-hidden="true"
                                        >
                                            <component :is="entryIcon(entry)" />
                                        </span>
                                        <span
                                            v-if="
                                                index < day.entries.length - 1
                                            "
                                            class="mt-1 w-px flex-1 bg-border"
                                            aria-hidden="true"
                                        />
                                    </div>

                                    <div
                                        class="min-w-0 flex-1 pb-5 group-last:pb-0"
                                    >
                                        <div
                                            class="flex flex-wrap items-center gap-x-2 gap-y-1"
                                        >
                                            <span
                                                class="text-sm font-medium break-words"
                                                >{{ entry.title }}</span
                                            >
                                            <StatusBadge
                                                v-if="
                                                    entry.type === 'session' &&
                                                    entry.status
                                                "
                                                kind="session"
                                                :status="entry.status"
                                            />
                                            <Badge
                                                v-else-if="entry.progress_label"
                                                variant="secondary"
                                                :class="
                                                    cn(
                                                        'border-transparent',
                                                        entryTone(entry),
                                                    )
                                                "
                                                >{{
                                                    entry.progress_label
                                                }}</Badge
                                            >
                                            <!-- A note is dated, not timed: its instant is the end of that day. -->
                                            <span
                                                v-if="
                                                    entry.type !==
                                                    'progress_note'
                                                "
                                                class="ml-auto text-xs text-muted-foreground tabular-nums"
                                                >{{
                                                    formatTime(entry.at)
                                                }}</span
                                            >
                                        </div>

                                        <template
                                            v-if="entry.type === 'session'"
                                        >
                                            <p
                                                v-if="entry.items?.length"
                                                class="mt-1 text-sm text-muted-foreground"
                                            >
                                                {{ entry.items.join(' · ') }}
                                            </p>
                                            <div
                                                class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                                            >
                                                <StatusBadge
                                                    v-if="entry.payment_status"
                                                    kind="payment"
                                                    :status="
                                                        entry.payment_status
                                                    "
                                                />
                                                <span
                                                    v-if="entry.amount"
                                                    class="tabular-nums"
                                                    >{{
                                                        formatMoney(
                                                            entry.amount,
                                                        )
                                                    }}</span
                                                >
                                                <span v-if="entry.held_by"
                                                    >Held by
                                                    {{ entry.held_by }}</span
                                                >
                                            </div>
                                        </template>

                                        <p
                                            v-else-if="
                                                entry.type ===
                                                    'record_change' &&
                                                (entry.changes?.length ||
                                                    entry.author)
                                            "
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            <template
                                                v-if="entry.changes?.length"
                                                >changed:
                                                {{
                                                    entry.changes
                                                        .map((change) =>
                                                            change.replaceAll(
                                                                '_',
                                                                ' ',
                                                            ),
                                                        )
                                                        .join(', ')
                                                }}<template v-if="entry.author">
                                                    ·
                                                </template></template
                                            >
                                            <template v-if="entry.author">{{
                                                entry.author
                                            }}</template>
                                        </p>

                                        <p
                                            v-else-if="entry.author"
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            {{ entry.author }}
                                        </p>
                                    </div>
                                </li>
                            </ol>
                        </section>

                        <p
                            class="border-t pt-3 text-xs text-muted-foreground italic"
                        >
                            Changes to personal details are recorded as changed
                            without keeping what they changed to — the audit
                            trail should not become a second copy of the record.
                        </p>
                    </template>
                </TabsContent>
            </Tabs>
        </SheetContent>
    </Sheet>
</template>
