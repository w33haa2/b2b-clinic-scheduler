<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Minus,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    Trash2,
    TrendingDown,
    TrendingUp,
    X,
} from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import DatePicker from '@/components/form/DatePicker.vue';
import PageHeader from '@/components/PageHeader.vue';
import PatientPicker from '@/components/PatientPicker.vue';
import MetricChart from '@/components/patients/MetricChart.vue';
import ProgressNoteDialog from '@/components/patients/ProgressNoteDialog.vue';
import ProgressTimeline from '@/components/patients/ProgressTimeline.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { usePagination } from '@/composables/usePagination';
import { api, ApiError, fetchAllPages } from '@/lib/api';
import {
    formatDateOnly,
    patientName,
    progressRatingLabels,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { progressNotes } from '@/routes/admin/clinics';
import * as members from '@/routes/clinic/members';
import { show as patientShow } from '@/routes/clinic/patients';
import {
    destroy,
    index as patientNotes,
} from '@/routes/clinic/patients/progress-notes';
import { index as clinicNotes } from '@/routes/clinic/progress-notes';
import type {
    ClinicMember,
    Paginated,
    Patient,
    PatientProgressNote,
    PatientProgressNotePage,
    ProgressRating,
    Wrapped,
} from '@/types';

const { clinicId, context, isAdmin, can } = useClinic();

setLayoutProps({
    breadcrumbs: [
        { title: 'Progress notes', href: progressNotes(clinicId.value) },
    ],
});

const myMemberId = computed(() => context.value.member.id);

type ProgressFilter = 'ALL' | ProgressRating;

/**
 * The page has two modes. With no patient chosen it is the clinic's recent
 * notes; choose one and it becomes that patient's history. The choice lives
 * in the URL (`?patient=`) so the view can be shared and survives a reload.
 */
const patientId = ref<number | null>(null);
const selectedPatient = ref<Patient | null>(null);

const memberId = ref('all');
const progress = ref<ProgressFilter>('ALL');
const from = ref('');
const to = ref('');
const search = ref('');
const debouncedSearch = refDebounced(search, 300);

const { page, perPage, query } = usePagination(25, [
    patientId,
    memberId,
    progress,
    from,
    to,
    debouncedSearch,
]);

// Clinic mode: every recent note, newest first.
const {
    data: recent,
    loading: recentLoading,
    refresh: refreshRecent,
} = useApiQuery<Paginated<PatientProgressNote>>(() =>
    patientId.value === null
        ? clinicNotes.url(clinicId.value, {
              query: {
                  clinic_user_id:
                      memberId.value === 'all'
                          ? undefined
                          : Number(memberId.value),
                  progress:
                      progress.value === 'ALL' ? undefined : progress.value,
                  from: from.value || undefined,
                  to: to.value || undefined,
                  search: debouncedSearch.value || undefined,
                  ...query.value,
              },
          })
        : null,
);

// Patient mode: the whole history, oldest first, with its trend and metrics.
const {
    data: timeline,
    loading: timelineLoading,
    refresh: refreshTimeline,
} = useApiQuery<PatientProgressNotePage>(() =>
    patientId.value === null
        ? null
        : patientNotes.url(
              { clinic: clinicId.value, patient: patientId.value },
              { query: { ...query.value } },
          ),
);

const trend = computed(() => timeline.value?.meta.trend ?? null);
const metricSeries = computed(() => timeline.value?.meta.metrics ?? []);

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

function textTone(direction: number): string {
    if (direction > 0) {
        return 'text-emerald-700 dark:text-emerald-300';
    }

    if (direction < 0) {
        return 'text-rose-700 dark:text-rose-300';
    }

    return 'text-muted-foreground';
}

// Switching between the two modes
function selectPatient(patient: Patient): void {
    selectedPatient.value = patient;
    patientId.value = patient.id;
    // Forget the previous patient's history, so the page shows skeletons
    // rather than somebody else's notes while the new ones load.
    timeline.value = null;
    window.scrollTo({ top: 0 });
}

function clearPatient(): void {
    selectedPatient.value = null;
    patientId.value = null;
    timeline.value = null;
}

watch(patientId, (id) => {
    window.history.replaceState(
        window.history.state,
        '',
        id
            ? `${window.location.pathname}?patient=${id}`
            : window.location.pathname,
    );
});

/**
 * Name the patient the URL asked for. Only the heading depends on this;
 * the history below loads either way, so a refusal is not worth a toast.
 */
const lookingUpPatient = ref(false);

async function loadPatient(id: number): Promise<void> {
    lookingUpPatient.value = true;

    try {
        const response = await api.get<Wrapped<Patient>>(
            patientShow.url({ clinic: clinicId.value, patient: id }),
        );

        selectedPatient.value = response.data;
    } catch {
        // Only a label is missing; the notes speak for themselves.
    } finally {
        lookingUpPatient.value = false;
    }
}

// The staff list for the filter.
const staff = ref<ClinicMember[]>([]);

onMounted(async () => {
    // Read on mount, not during setup: the server render has no URL to read
    // and would hydrate into a different mode.
    const asked = Number(
        new URLSearchParams(window.location.search).get('patient'),
    );

    if (Number.isInteger(asked) && asked > 0) {
        patientId.value = asked;

        if (can('clinic.patients.show')) {
            void loadPatient(asked);
        }
    }

    if (can('clinic.members.index')) {
        staff.value = await fetchAllPages<ClinicMember>((pageNumber) =>
            members.index.url(clinicId.value, {
                query: { per_page: 100, page: pageNumber },
            }),
        ).catch(() => []);
    }
});

const isFiltered = computed(
    () =>
        memberId.value !== 'all' ||
        progress.value !== 'ALL' ||
        from.value !== '' ||
        to.value !== '' ||
        search.value !== '',
);

function resetFilters(): void {
    memberId.value = 'all';
    progress.value = 'ALL';
    from.value = '';
    to.value = '';
    search.value = '';
}

// Writing, editing and deleting
const canWrite = computed(() => can('clinic.patients.progress-notes.store'));

/** The author may change their own note; a Clinic Admin may change any. */
function mayWrite(note: PatientProgressNote): boolean {
    return isAdmin.value || note.author?.id === myMemberId.value;
}

const canEditNote = (note: PatientProgressNote): boolean =>
    can('clinic.patients.progress-notes.update') && mayWrite(note);

const canDeleteNote = (note: PatientProgressNote): boolean =>
    can('clinic.patients.progress-notes.destroy') && mayWrite(note);

const canChangeNote = (note: PatientProgressNote): boolean =>
    canEditNote(note) || canDeleteNote(note);

const formOpen = ref(false);
const editing = ref<PatientProgressNote | null>(null);
const deleting = ref<PatientProgressNote | null>(null);
const deleteOpen = ref(false);
const deleteProcessing = ref(false);

/** The dialog writes to the note's own patient, or the one on screen. */
const formPatientId = computed(
    () => editing.value?.patient_id ?? patientId.value ?? 0,
);

function openNote(note: PatientProgressNote | null): void {
    editing.value = note;
    formOpen.value = true;
}

function confirmDelete(note: PatientProgressNote): void {
    deleting.value = note;
    deleteOpen.value = true;
}

async function reload(): Promise<void> {
    await (patientId.value === null ? refreshRecent() : refreshTimeline());
}

async function remove(): Promise<void> {
    const note = deleting.value;

    if (!note) {
        return;
    }

    deleteProcessing.value = true;

    try {
        await api.delete(
            destroy.url({
                clinic: clinicId.value,
                patient: note.patient_id,
                progressNote: note.id,
            }),
        );
        toast.success('Progress note deleted.');
        deleteOpen.value = false;
        await reload();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not delete the progress note.',
        );
    } finally {
        deleteProcessing.value = false;
    }
}

function onSaved(): void {
    toast.success(
        editing.value ? 'Progress note saved.' : 'Progress note added.',
    );
    void reload();
}
</script>

<template>
    <Head title="Progress notes" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Progress notes"
            description="Is this patient getting better? Every note rates the change since the one before it, so the answer is written down rather than remembered. Measurements recorded with a note are charted over time."
        >
            <template #actions>
                <Button
                    v-if="patientId !== null && canWrite"
                    @click="openNote(null)"
                >
                    <Plus />
                    Add note
                </Button>
            </template>
        </PageHeader>

        <!-- Clinic mode: who has been seen lately, and who is going backwards. -->
        <template v-if="patientId === null">
            <div class="flex flex-wrap items-center gap-3">
                <div class="w-full sm:w-56">
                    <PatientPicker
                        single
                        placeholder="Open a patient's history"
                        @pick="selectPatient"
                    />
                </div>

                <Select v-if="can('clinic.members.index')" v-model="memberId">
                    <SelectTrigger class="w-48" aria-label="Written by">
                        <SelectValue placeholder="Anyone" />
                    </SelectTrigger>
                    <SelectContent class="max-h-72">
                        <SelectItem value="all">Written by anyone</SelectItem>
                        <SelectItem
                            v-for="member in staff"
                            :key="member.id"
                            :value="String(member.id)"
                            >{{
                                member.user?.name ?? 'Unknown member'
                            }}</SelectItem
                        >
                    </SelectContent>
                </Select>

                <Select v-model="progress">
                    <SelectTrigger class="w-44" aria-label="Progress">
                        <SelectValue placeholder="Any progress" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="ALL">Any progress</SelectItem>
                        <SelectItem value="BASELINE">Baseline</SelectItem>
                        <SelectItem value="IMPROVED">Improved</SelectItem>
                        <SelectItem value="UNCHANGED">No change</SelectItem>
                        <SelectItem value="DECLINED">Declined</SelectItem>
                        <SelectItem value="RESOLVED">Resolved</SelectItem>
                    </SelectContent>
                </Select>

                <div class="flex items-center gap-2">
                    <DatePicker
                        v-model="from"
                        placeholder="From"
                        clearable
                        class="w-36"
                    />
                    <span class="text-muted-foreground">–</span>
                    <DatePicker
                        v-model="to"
                        placeholder="To"
                        clearable
                        class="w-36"
                    />
                </div>

                <Button
                    v-if="isFiltered"
                    variant="ghost"
                    size="sm"
                    @click="resetFilters"
                >
                    <X />
                    Clear filters
                </Button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Tabs v-model="progress">
                    <TabsList>
                        <TabsTrigger value="ALL">All</TabsTrigger>
                        <TabsTrigger value="IMPROVED">Improved</TabsTrigger>
                        <TabsTrigger value="UNCHANGED">No change</TabsTrigger>
                        <TabsTrigger value="DECLINED">Declined</TabsTrigger>
                    </TabsList>
                </Tabs>
                <div class="relative ml-auto w-full sm:w-64">
                    <Search
                        class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        placeholder="Search patients and summaries"
                        class="pl-8"
                    />
                </div>
            </div>

            <Card
                class="animate__animated animate__fadeInUp overflow-hidden py-0"
                style="--animate-duration: 0.5s"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>Patient</TableHead>
                            <TableHead>Progress</TableHead>
                            <TableHead>Summary</TableHead>
                            <TableHead class="hidden md:table-cell"
                                >Written by</TableHead
                            >
                            <TableHead class="w-10" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <template v-if="recentLoading && !recent">
                            <TableRow v-for="row in 5" :key="row">
                                <TableCell colspan="6"
                                    ><Skeleton class="h-6 w-full"
                                /></TableCell>
                            </TableRow>
                        </template>
                        <TableRow
                            v-for="note in recent?.data ?? []"
                            :key="note.id"
                        >
                            <TableCell
                                class="font-medium whitespace-nowrap tabular-nums"
                                >{{
                                    formatDateOnly(note.recorded_on)
                                }}</TableCell
                            >
                            <TableCell>
                                <button
                                    v-if="note.patient"
                                    type="button"
                                    class="font-medium text-teal-700 hover:underline dark:text-teal-300"
                                    @click="selectPatient(note.patient)"
                                >
                                    {{ patientName(note.patient) }}
                                </button>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </TableCell>
                            <TableCell>
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
                            </TableCell>
                            <TableCell>
                                <div
                                    class="max-w-xs truncate"
                                    :title="note.summary"
                                >
                                    {{ note.summary }}
                                </div>
                            </TableCell>
                            <TableCell
                                class="hidden text-muted-foreground md:table-cell"
                                >{{ note.author?.user?.name ?? '—' }}</TableCell
                            >
                            <TableCell>
                                <DropdownMenu v-if="canChangeNote(note)">
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="size-8"
                                            :aria-label="`Actions for the note from ${formatDateOnly(note.recorded_on)}`"
                                        >
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            v-if="canEditNote(note)"
                                            @select="openNote(note)"
                                        >
                                            <Pencil />
                                            Edit
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="canDeleteNote(note)"
                                            variant="destructive"
                                            @select="confirmDelete(note)"
                                        >
                                            <Trash2 />
                                            Delete
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <EmptyState
                    v-if="recent && recent.data.length === 0"
                    title="No progress notes here"
                    description="A note is written after a visit to say whether the patient is better, the same or worse. Widen the dates or clear the filters to see more."
                />
            </Card>

            <DataPagination
                v-if="recent"
                v-model:page="page"
                v-model:per-page="perPage"
                :meta="recent.meta"
            />
        </template>

        <!-- Patient mode: one patient's history, oldest first. -->
        <template v-else>
            <div>
                <Button variant="ghost" size="sm" @click="clearPatient">
                    <ArrowLeft />
                    All progress notes
                </Button>
            </div>

            <!-- Where this patient stands right now. -->
            <Card
                class="animate__animated animate__fadeInUp px-5"
                style="--animate-duration: 0.5s"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="text-sm text-muted-foreground">
                            Progress for
                        </div>
                        <Skeleton
                            v-if="!selectedPatient && lookingUpPatient"
                            class="mt-1 h-7 w-48"
                        />
                        <h2 v-else class="truncate text-xl font-semibold">
                            {{
                                selectedPatient
                                    ? patientName(selectedPatient)
                                    : 'This patient'
                            }}
                        </h2>
                    </div>

                    <div v-if="trend" class="flex flex-col items-start gap-2">
                        <Badge
                            :class="
                                cn(
                                    'gap-2 border-transparent px-4 py-2 text-lg [&>svg]:size-6',
                                    badgeTone(trend.direction),
                                )
                            "
                        >
                            <component
                                :is="directionIcon(trend.direction)"
                                aria-hidden="true"
                            />
                            {{
                                trend.latest
                                    ? progressRatingLabels[trend.latest]
                                    : 'No notes yet'
                            }}
                        </Badge>
                        <p :class="cn('text-sm', textTone(trend.direction))">
                            <span class="font-medium tabular-nums">{{
                                trend.improved
                            }}</span>
                            improved ·
                            <span class="font-medium tabular-nums">{{
                                trend.unchanged
                            }}</span>
                            no change ·
                            <span class="font-medium tabular-nums">{{
                                trend.declined
                            }}</span>
                            declined over {{ trend.notes }}
                            {{ trend.notes === 1 ? 'note' : 'notes' }}
                        </p>
                    </div>
                    <Skeleton v-else class="h-16 w-64" />
                </div>
            </Card>

            <!-- The numbers behind the judgements. -->
            <section v-if="metricSeries.length" class="flex flex-col gap-3">
                <h3 class="text-sm font-medium text-muted-foreground">
                    Measurements over time
                </h3>
                <div class="grid gap-3 lg:grid-cols-2">
                    <MetricChart
                        v-for="series in metricSeries"
                        :key="series.name"
                        :series="series"
                    />
                </div>
            </section>

            <Card
                class="animate__animated animate__fadeInUp px-5"
                style="--animate-duration: 0.6s"
            >
                <div
                    v-if="timelineLoading && !timeline"
                    class="flex flex-col gap-4"
                >
                    <Skeleton v-for="row in 3" :key="row" class="h-20 w-full" />
                </div>
                <ProgressTimeline
                    v-else-if="timeline?.data.length"
                    :notes="timeline.data"
                    :can-edit="canChangeNote"
                    @edit="openNote"
                    @delete="confirmDelete"
                />
                <EmptyState
                    v-else
                    title="No progress notes yet"
                    description="The first note is the baseline: where this patient started. Every note after it says whether they are better, the same or worse."
                >
                    <Button v-if="canWrite" @click="openNote(null)">
                        <Plus />
                        Add the first note
                    </Button>
                </EmptyState>
            </Card>

            <DataPagination
                v-if="timeline"
                v-model:page="page"
                v-model:per-page="perPage"
                :meta="timeline.meta"
            />
        </template>
    </div>

    <ProgressNoteDialog
        v-model:open="formOpen"
        :note="editing"
        :patient-id="formPatientId"
        :is-first-note="(trend?.notes ?? 0) === 0"
        @saved="onSaved"
    />
    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Delete this progress note?"
        :description="
            deleting
                ? `The note from ${formatDateOnly(deleting.recorded_on)} will be taken out of the patient's history. Only its author or a Clinic Admin may do this.`
                : ''
        "
        :processing="deleteProcessing"
        @confirm="remove"
    />
</template>
