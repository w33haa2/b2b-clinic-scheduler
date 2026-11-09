<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import {
    Archive,
    Download,
    Eye,
    LineChart,
    Link2,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
} from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { ref, useTemplateRef } from 'vue';
import { toast } from 'vue-sonner';
import PatientFormDialog from '@/components/clinic/PatientFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import IntakeLinkDialog from '@/components/patients/IntakeLinkDialog.vue';
import PatientSheet from '@/components/patients/PatientSheet.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useApiQuery } from '@/composables/useApiQuery';
import { usePagination } from '@/composables/usePagination';
import { useClinic } from '@/composables/useClinic';
import { useInitials } from '@/composables/useInitials';
import { api, ApiError } from '@/lib/api';
import { downloadFile } from '@/lib/download';
import { ageFrom, formatDate, patientName } from '@/lib/format';
import {
    patients as patientsPage,
    progressNotes,
} from '@/routes/admin/clinics';
import { destroy, index, pdf } from '@/routes/clinic/patients';
import type { Paginated, Patient } from '@/types';

const { clinicId, can } = useClinic();
const { getInitials } = useInitials();

setLayoutProps({
    breadcrumbs: [{ title: 'Patients', href: patientsPage(clinicId.value) }],
});

const search = ref('');
const debouncedSearch = refDebounced(search, 300);
const { page, perPage, query } = usePagination(25, [debouncedSearch]);

const { data, loading, refresh } = useApiQuery<Paginated<Patient>>(() =>
    index.url(clinicId.value, {
        query: {
            search: debouncedSearch.value || undefined,
            with_fields: 1,
            ...query.value,
        },
    }),
);

const formOpen = ref(false);
const editing = ref<Patient | null>(null);
const archiving = ref<Patient | null>(null);
const archiveOpen = ref(false);
const archiveProcessing = ref(false);

const viewingId = ref<number | null>(null);
const sheetOpen = ref(false);
const patientSheet = useTemplateRef<{ reload: () => Promise<void> }>(
    'patientSheet',
);

const intakeOpen = ref(false);
const intakeFor = ref<Patient | null>(null);

/** Patient IDs whose record PDF is being generated, for the row spinner. */
const downloading = ref<number[]>([]);

function openCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(patient: Patient): void {
    editing.value = patient;
    formOpen.value = true;
}

function openSheet(patient: Patient): void {
    viewingId.value = patient.id;
    sheetOpen.value = true;
}

function openIntake(patient: Patient | null): void {
    intakeFor.value = patient;
    intakeOpen.value = true;
}

function onSaved(patient: Patient): void {
    toast.success(
        editing.value
            ? `${patientName(patient)} updated.`
            : `${patientName(patient)} added.`,
    );

    // The open sheet reloads the record it shows, then asks us for the list.
    if (sheetOpen.value && viewingId.value === patient.id) {
        void patientSheet.value?.reload();

        return;
    }

    void refresh();
}

function confirmArchive(patient: Patient): void {
    archiving.value = patient;
    archiveOpen.value = true;
}

async function archive(): Promise<void> {
    if (!archiving.value) {
        return;
    }

    archiveProcessing.value = true;

    try {
        await api.delete(
            destroy.url({
                clinic: clinicId.value,
                patient: archiving.value.id,
            }),
        );
        toast.success(`${patientName(archiving.value)} archived.`);
        archiveOpen.value = false;

        if (viewingId.value === archiving.value.id) {
            sheetOpen.value = false;
        }

        void refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not archive the patient.',
        );
    } finally {
        archiveProcessing.value = false;
    }
}

async function downloadRecord(patient: Patient): Promise<void> {
    downloading.value = [...downloading.value, patient.id];

    try {
        await downloadFile(
            pdf.url({ clinic: clinicId.value, patient: patient.id }),
            `${patientName(patient)}.pdf`,
        );
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not download the record.',
        );
    } finally {
        downloading.value = downloading.value.filter((id) => id !== patient.id);
    }
}
</script>

<template>
    <Head title="Patients" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Patients"
            description="Everyone your clinic sees. What a record holds — beyond name and contact details — is whatever your patient form asks for."
        >
            <template #actions>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        placeholder="Search name, email, phone"
                        class="w-64 pl-8"
                    />
                </div>
                <Button
                    v-if="can('clinic.patient-form-invitations.store')"
                    variant="outline"
                    @click="openIntake(null)"
                >
                    <Link2 />
                    Send intake link
                </Button>
                <Button v-if="can('clinic.patients.store')" @click="openCreate">
                    <Plus />
                    New patient
                </Button>
            </template>
        </PageHeader>

        <Card
            class="animate__animated animate__fadeInUp overflow-hidden py-0"
            style="--animate-duration: 0.5s"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Patient</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Age / sex</TableHead
                        >
                        <TableHead class="hidden lg:table-cell"
                            >Contact</TableHead
                        >
                        <TableHead class="hidden lg:table-cell"
                            >Added</TableHead
                        >
                        <TableHead class="w-12"
                            ><span class="sr-only">Actions</span></TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading && !data">
                        <TableRow v-for="row in 5" :key="row">
                            <TableCell colspan="5"
                                ><Skeleton class="h-8 w-full"
                            /></TableCell>
                        </TableRow>
                    </template>
                    <TableRow
                        v-for="patient in data?.data ?? []"
                        :key="patient.id"
                        class="group cursor-pointer"
                        @click="openSheet(patient)"
                    >
                        <TableCell>
                            <div class="flex items-center gap-3">
                                <Avatar class="size-8">
                                    <AvatarFallback
                                        class="bg-teal-100 text-xs text-teal-800 dark:bg-teal-500/20 dark:text-teal-200"
                                    >
                                        {{ getInitials(patientName(patient)) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div>
                                    <div class="font-medium">
                                        {{ patient.first_name }}
                                        {{ patient.middle_name }}
                                        {{ patient.last_name }}
                                    </div>
                                    <div
                                        v-if="patient.notes"
                                        class="max-w-xs truncate text-xs text-muted-foreground"
                                    >
                                        {{ patient.notes }}
                                    </div>
                                </div>
                            </div>
                        </TableCell>
                        <TableCell
                            class="hidden text-muted-foreground capitalize md:table-cell"
                        >
                            {{ ageFrom(patient.birth_date) ?? '—'
                            }}<span v-if="patient.sex">
                                · {{ patient.sex }}</span
                            >
                        </TableCell>
                        <TableCell class="hidden text-sm lg:table-cell">
                            <div>{{ patient.email ?? '—' }}</div>
                            <div class="text-muted-foreground">
                                {{ patient.phone }}
                            </div>
                        </TableCell>
                        <TableCell
                            class="hidden text-muted-foreground lg:table-cell"
                        >
                            {{ formatDate(patient.created_at) }}
                        </TableCell>
                        <TableCell class="text-right" @click.stop>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8 opacity-70 transition-opacity group-hover:opacity-100"
                                        :aria-label="`Actions for ${patientName(patient)}`"
                                    >
                                        <Spinner
                                            v-if="
                                                downloading.includes(patient.id)
                                            "
                                        />
                                        <MoreHorizontal v-else />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-52">
                                    <DropdownMenuItem
                                        @select="openSheet(patient)"
                                    >
                                        <Eye />
                                        View
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="can('clinic.patients.update')"
                                        @select="openEdit(patient)"
                                    >
                                        <Pencil />
                                        Edit
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            can('clinic.progress-notes.index')
                                        "
                                        as-child
                                    >
                                        <Link
                                            class="w-full cursor-pointer"
                                            :href="
                                                progressNotes(clinicId, {
                                                    query: {
                                                        patient: patient.id,
                                                    },
                                                })
                                            "
                                        >
                                            <LineChart />
                                            Progress notes
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            can(
                                                'clinic.patient-form-invitations.store',
                                            )
                                        "
                                        @select="openIntake(patient)"
                                    >
                                        <Link2 />
                                        Send intake link
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        :disabled="
                                            downloading.includes(patient.id)
                                        "
                                        @select="downloadRecord(patient)"
                                    >
                                        <Download />
                                        Download record
                                    </DropdownMenuItem>
                                    <template
                                        v-if="can('clinic.patients.destroy')"
                                    >
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            @select="confirmArchive(patient)"
                                        >
                                            <Archive />
                                            Archive
                                        </DropdownMenuItem>
                                    </template>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <EmptyState
                v-if="data && data.data.length === 0"
                :title="
                    debouncedSearch
                        ? 'No patients match your search'
                        : 'No patients yet'
                "
                :description="
                    debouncedSearch
                        ? 'Try another name, email or phone number.'
                        : 'Add your first patient to start booking sessions.'
                "
            />
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>

    <PatientSheet
        ref="patientSheet"
        v-model:open="sheetOpen"
        :patient-id="viewingId"
        @edit="openEdit"
        @changed="refresh()"
    />
    <IntakeLinkDialog v-model:open="intakeOpen" :patient="intakeFor" />
    <PatientFormDialog
        v-model:open="formOpen"
        :patient="editing"
        @saved="onSaved"
    />
    <ConfirmDialog
        v-model:open="archiveOpen"
        title="Archive this patient?"
        :description="`${patientName(archiving ?? undefined)} will be hidden from lists. Their sessions, bills and invoices are kept.`"
        confirm-label="Archive"
        :processing="archiveProcessing"
        @confirm="archive"
    />
</template>
