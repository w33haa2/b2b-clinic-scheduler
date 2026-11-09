<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { MoreHorizontal, Pencil, Plus, Trash2, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import AttendanceFormDialog from '@/components/clinic/AttendanceFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import DatePicker from '@/components/form/DatePicker.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { usePagination } from '@/composables/usePagination';
import { api, ApiError, fetchAllPages } from '@/lib/api';
import { clinicParts } from '@/lib/clinicTime';
import {
    attendanceStatusLabels,
    formatDate,
    formatMinutes,
    formatTime,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { attendance } from '@/routes/admin/clinics';
import { destroy, index } from '@/routes/clinic/attendance';
import * as members from '@/routes/clinic/members';
import type {
    Attendance,
    AttendancePage,
    AttendanceStatus,
    ClinicMember,
} from '@/types';

const { clinicId, context, isAdmin, can } = useClinic();

setLayoutProps({
    breadcrumbs: [{ title: 'Attendance', href: attendance(clinicId.value) }],
});

const myMemberId = computed(() => context.value.member.id);

/** Today in the clinic's timezone: work dates are clinic-local dates. */
const today = clinicParts(new Date(), context.value.clinic.timezone).date;
const [thisYear, thisMonth] = today.split('-').map(Number);
const monthStart = `${today.slice(0, 7)}-01`;
const monthEnd = new Date(Date.UTC(thisYear, thisMonth, 0))
    .toISOString()
    .slice(0, 10);

const status = ref<AttendanceStatus | 'ALL'>('ALL');
// A member who is not a Clinic Admin starts on their own days.
const memberId = ref(isAdmin.value ? 'all' : String(myMemberId.value));
const from = ref(monthStart);
const to = ref(monthEnd);

const { page, perPage, query } = usePagination(25, [
    status,
    memberId,
    from,
    to,
]);

const { data, loading, refresh } = useApiQuery<AttendancePage>(() =>
    index.url(clinicId.value, {
        query: {
            status: status.value === 'ALL' ? undefined : status.value,
            clinic_user_id:
                memberId.value === 'all' ? undefined : Number(memberId.value),
            from: from.value || undefined,
            to: to.value || undefined,
            ...query.value,
        },
    }),
);

const cards = computed(() => {
    const summary = data.value?.meta.summary;

    return (
        [
            {
                key: 'PRESENT',
                hint: 'Counts as worked',
                tone: 'text-emerald-700 dark:text-emerald-300',
            },
            {
                key: 'LATE',
                hint: 'Counts as worked',
                tone: 'text-amber-700 dark:text-amber-300',
            },
            {
                key: 'ABSENT',
                hint: 'Not paid out',
                tone: 'text-rose-700 dark:text-rose-300',
            },
            {
                key: 'EXCUSED',
                hint: 'Not paid out',
                tone: 'text-muted-foreground',
            },
        ] as const
    ).map((card) => ({
        ...card,
        label: attendanceStatusLabels[card.key],
        value: summary?.[card.key],
    }));
});

// The staff list for the filter and the form.
const staff = ref<ClinicMember[]>([]);

onMounted(async () => {
    if (!can('clinic.members.index')) {
        return;
    }

    staff.value = await fetchAllPages<ClinicMember>((pageNumber) =>
        members.index.url(clinicId.value, {
            query: { per_page: 100, page: pageNumber },
        }),
    ).catch(() => []);
});

/** The caller is always pickable, even when they may not list the members. */
const staffOptions = computed(() => {
    const options = staff.value.map((member) => ({
        id: member.id,
        name: member.user?.name ?? 'Unknown member',
    }));

    return options.some((option) => option.id === myMemberId.value)
        ? options
        : [{ id: myMemberId.value, name: 'Me' }, ...options];
});

const isFiltered = computed(
    () =>
        status.value !== 'ALL' ||
        memberId.value !== (isAdmin.value ? 'all' : String(myMemberId.value)) ||
        from.value !== monthStart ||
        to.value !== monthEnd,
);

function resetFilters(): void {
    status.value = 'ALL';
    memberId.value = isAdmin.value ? 'all' : String(myMemberId.value);
    from.value = monthStart;
    to.value = monthEnd;
}

// Recording, editing and deleting
const formOpen = ref(false);
const editing = ref<Attendance | null>(null);
const deleting = ref<Attendance | null>(null);
const deleteOpen = ref(false);
const deleteProcessing = ref(false);

/** Only a Clinic Admin may write attendance for someone else. */
function mayWrite(row: Attendance): boolean {
    return isAdmin.value || row.member_id === myMemberId.value;
}

const canEdit = (row: Attendance): boolean =>
    can('clinic.attendance.update') && mayWrite(row);

const canDelete = (row: Attendance): boolean =>
    can('clinic.attendance.destroy') && mayWrite(row);

function openForm(row: Attendance | null): void {
    editing.value = row;
    formOpen.value = true;
}

function confirmDelete(row: Attendance): void {
    deleting.value = row;
    deleteOpen.value = true;
}

async function remove(): Promise<void> {
    if (!deleting.value) {
        return;
    }

    deleteProcessing.value = true;

    try {
        await api.delete(
            destroy.url({
                clinic: clinicId.value,
                attendance: deleting.value.id,
            }),
        );
        toast.success('Attendance deleted.');
        deleteOpen.value = false;
        void refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not delete the attendance record.',
        );
    } finally {
        deleteProcessing.value = false;
    }
}

/** "Mon" for a plain "YYYY-MM-DD" work date, read as a local date. */
function weekdayOf(workDate: string): string {
    return new Date(`${workDate}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'long',
    });
}

const memberName = (row: Attendance): string =>
    row.member?.user?.name ?? 'Unknown member';
</script>

<template>
    <Head title="Attendance" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Attendance"
            description="One record per staff member per working day, dated in the clinic's timezone. Present and Late count as worked; Absent and Excused do not — when the clinic requires attendance for payouts, only the days that count as worked are paid."
        >
            <template #actions>
                <Button
                    v-if="can('clinic.attendance.store')"
                    @click="openForm(null)"
                >
                    <Plus />
                    Record attendance
                </Button>
            </template>
        </PageHeader>

        <!-- Days per status (for the current filters); click to filter. -->
        <div
            class="animate__animated animate__fadeInUp grid grid-cols-2 gap-3 lg:grid-cols-4"
            style="--animate-duration: 0.4s"
        >
            <button
                v-for="card in cards"
                :key="card.key"
                type="button"
                :aria-pressed="status === card.key"
                :class="
                    cn(
                        'rounded-xl border bg-card p-4 text-left shadow-sm transition-colors hover:bg-accent/50',
                        status === card.key && 'ring-2 ring-teal-600',
                    )
                "
                @click="status = status === card.key ? 'ALL' : card.key"
            >
                <div class="text-sm text-muted-foreground">
                    {{ card.label }}
                </div>
                <div
                    :class="
                        cn(
                            'mt-1 text-2xl font-semibold tabular-nums',
                            card.tone,
                        )
                    "
                >
                    <Skeleton
                        v-if="card.value === undefined"
                        class="h-8 w-16"
                    />
                    <template v-else>{{ card.value }}</template>
                </div>
                <div class="text-xs text-muted-foreground">
                    {{ card.hint }}
                </div>
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Select v-model="memberId">
                <SelectTrigger class="w-56" aria-label="Staff member">
                    <SelectValue placeholder="All staff" />
                </SelectTrigger>
                <SelectContent class="max-h-72">
                    <SelectItem value="all">All staff</SelectItem>
                    <SelectItem
                        v-for="option in staffOptions"
                        :key="option.id"
                        :value="String(option.id)"
                        >{{ option.name }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <div class="flex items-center gap-2">
                <DatePicker
                    v-model="from"
                    placeholder="From"
                    clearable
                    class="w-40"
                />
                <span class="text-muted-foreground">–</span>
                <DatePicker
                    v-model="to"
                    placeholder="To"
                    clearable
                    class="w-40"
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

        <Card
            class="animate__animated animate__fadeInUp overflow-hidden py-0"
            style="--animate-duration: 0.5s"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Date</TableHead>
                        <TableHead>Staff member</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Check in</TableHead>
                        <TableHead>Check out</TableHead>
                        <TableHead class="text-right">Hours</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Notes</TableHead
                        >
                        <TableHead class="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading && !data">
                        <TableRow v-for="row in 5" :key="row">
                            <TableCell colspan="8"
                                ><Skeleton class="h-6 w-full"
                            /></TableCell>
                        </TableRow>
                    </template>
                    <TableRow
                        v-for="row in data?.data ?? []"
                        :key="row.id"
                        :class="!row.counts_as_worked && 'bg-muted/30'"
                    >
                        <TableCell class="whitespace-nowrap">
                            <div class="font-medium">
                                {{ formatDate(row.work_date) }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ weekdayOf(row.work_date) }}
                            </div>
                        </TableCell>
                        <TableCell>{{ memberName(row) }}</TableCell>
                        <TableCell>
                            <StatusBadge
                                kind="attendance"
                                :status="row.status"
                            />
                        </TableCell>
                        <TableCell class="whitespace-nowrap tabular-nums">{{
                            row.checked_in_at
                                ? formatTime(row.checked_in_at)
                                : '—'
                        }}</TableCell>
                        <TableCell class="whitespace-nowrap tabular-nums">{{
                            row.checked_out_at
                                ? formatTime(row.checked_out_at)
                                : '—'
                        }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{
                            formatMinutes(row.worked_minutes)
                        }}</TableCell>
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                        >
                            <div
                                class="max-w-48 truncate"
                                :title="row.notes ?? undefined"
                            >
                                {{ row.notes || '—' }}
                            </div>
                        </TableCell>
                        <TableCell>
                            <DropdownMenu v-if="canEdit(row) || canDelete(row)">
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="`Actions for ${memberName(row)} on ${formatDate(row.work_date)}`"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-if="canEdit(row)"
                                        @select="openForm(row)"
                                    >
                                        <Pencil />
                                        Edit
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="canDelete(row)"
                                        variant="destructive"
                                        @select="confirmDelete(row)"
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
                v-if="data && data.data.length === 0"
                title="No attendance for these days"
                description="Record a day for each staff member who worked. Widen the dates or clear the filters to see more."
            />
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>

    <AttendanceFormDialog
        v-model:open="formOpen"
        :attendance="editing"
        :members="staff"
        @saved="
            (saved) => {
                toast.success(
                    `Attendance saved for ${formatDate(saved.work_date)}.`,
                );
                refresh();
            }
        "
    />
    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Delete this attendance record?"
        :description="
            deleting
                ? `${memberName(deleting)}'s day on ${formatDate(deleting.work_date)} will no longer be recorded. This cannot be undone.`
                : ''
        "
        :processing="deleteProcessing"
        @confirm="remove"
    />
</template>
