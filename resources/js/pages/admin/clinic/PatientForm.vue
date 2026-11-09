<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    FileUp,
    GripVertical,
    Lock,
    MoreHorizontal,
    Pencil,
    Plus,
    Printer,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ActivityLogList from '@/components/activity/ActivityLogList.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import DatePicker from '@/components/form/DatePicker.vue';
import PageHeader from '@/components/PageHeader.vue';
import FormFieldDialog from '@/components/patients/FormFieldDialog.vue';
import FormImportWizard from '@/components/patients/FormImportWizard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError } from '@/lib/api';
import { downloadFile } from '@/lib/download';
import { formatDate, patientFormFieldTypeLabels } from '@/lib/format';
import { cn } from '@/lib/utils';
import { patientForm } from '@/routes/admin/clinics';
import { index as activityIndex } from '@/routes/clinic/activity';
import { pdf, reorder, show as showForm } from '@/routes/clinic/patient-form';
import {
    destroy,
    index as fieldsIndex,
    restore,
    usage,
} from '@/routes/clinic/patient-form/fields';
import type {
    ActivityLogPage,
    PatientForm as PatientFormDefinition,
    PatientFormField,
    PatientFormFieldType,
    PatientFormFieldUsage,
    Wrapped,
} from '@/types';

const { clinicId, isAdmin, can } = useClinic();

setLayoutProps({
    breadcrumbs: [{ title: 'Patient form', href: patientForm(clinicId.value) }],
});

type Tab = 'form' | 'removed' | 'history';

const canAddFields = computed(() => can('clinic.patient-form.fields.store'));
const canEditFields = computed(() => can('clinic.patient-form.fields.update'));
const canRemoveFields = computed(() =>
    can('clinic.patient-form.fields.destroy'),
);
const canRestoreFields = computed(() =>
    can('clinic.patient-form.fields.restore'),
);
const canReorder = computed(() => can('clinic.patient-form.reorder'));
const canImport = computed(() => can('clinic.patient-form.extract.store'));
// The activity log is the clinic's own record: a Clinic Admin always sees
// it, another member only with the permission for it.
const canSeeHistory = computed(
    () => isAdmin.value || can('clinic.activity.index'),
);

const tab = ref<Tab>('form');

/** Keep the open tab in the URL, so a reload and the back button land back on it. */
watch(tab, (value) => {
    const query = new URLSearchParams(window.location.search);

    if (value === 'form') {
        query.delete('tab');
    } else {
        query.set('tab', value);
    }

    const search = query.toString();
    window.history.replaceState(
        window.history.state,
        '',
        `${window.location.pathname}${search ? `?${search}` : ''}`,
    );
});

onMounted(() => {
    const requested = new URLSearchParams(window.location.search).get('tab');

    if (requested === 'removed') {
        tab.value = 'removed';
    } else if (requested === 'history' && canSeeHistory.value) {
        tab.value = 'history';
    }
});

const {
    data: definition,
    loading: definitionLoading,
    refresh: refreshDefinition,
} = useApiQuery<Wrapped<PatientFormDefinition>>(() =>
    showForm.url(clinicId.value),
);

/**
 * The live order, held locally so a drag redraws at once and rolls back if
 * the API refuses it.
 */
const fields = ref<PatientFormField[]>([]);

watch(definition, (value) => {
    fields.value = value ? [...value.data.fields] : [];
});

/** Fields under the heading they are drawn beneath, in form order. */
const sections = computed(() => {
    const groups: Array<{ name: string; fields: PatientFormField[] }> = [];

    for (const field of fields.value) {
        const group = groups.find(
            (existing) => existing.name === field.section,
        );

        if (group) {
            group.fields.push(field);
        } else {
            groups.push({ name: field.section, fields: [field] });
        }
    }

    return groups;
});

const sectionNames = computed(() => sections.value.map((group) => group.name));

const { data: archived, refresh: refreshArchived } = useApiQuery<{
    data: PatientFormField[];
}>(() =>
    tab.value === 'removed'
        ? fieldsIndex.url(clinicId.value, {
              query: { state: 'archived', with_usage: 1 },
          })
        : null,
);

const { data: history, loading: historyLoading } = useApiQuery<ActivityLogPage>(
    () =>
        tab.value === 'history' && canSeeHistory.value
            ? activityIndex.url(clinicId.value, {
                  query: { category: 'patients', per_page: 30 },
              })
            : null,
);

// Editing
const fieldDialogOpen = ref(false);
const editing = ref<PatientFormField | null>(null);
const importOpen = ref(false);

function openField(field: PatientFormField | null): void {
    editing.value = field;
    fieldDialogOpen.value = true;
}

// Reordering
const draggingId = ref<number | null>(null);
const reordering = ref(false);

/** Send the whole order; the API writes one activity entry for the move. */
async function persistOrder(next: PatientFormField[]): Promise<void> {
    const previous = fields.value;

    fields.value = next;
    reordering.value = true;

    try {
        const response = await api.put<Wrapped<PatientFormDefinition>>(
            reorder.url(clinicId.value),
            {
                order: next.map((field, position) => ({
                    id: field.id,
                    position,
                    section: field.section,
                })),
            },
        );
        fields.value = [...response.data.fields];
    } catch (error) {
        fields.value = previous;
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not save the new order.',
        );
    } finally {
        reordering.value = false;
    }
}

/** Firefox only starts a drag once the event carries some data. */
function startDrag(event: DragEvent, field: PatientFormField): void {
    if (!canReorder.value || !event.dataTransfer) {
        event.preventDefault();

        return;
    }

    draggingId.value = field.id;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(field.id));
}

function dropOnField(target: PatientFormField): void {
    const sourceId = draggingId.value;
    draggingId.value = null;

    if (sourceId === null || sourceId === target.id || !canReorder.value) {
        return;
    }

    const next = [...fields.value];
    const from = next.findIndex((field) => field.id === sourceId);
    const to = next.findIndex((field) => field.id === target.id);

    if (from === -1 || to === -1) {
        return;
    }

    // Dropping onto a row takes that row's place, and its section with it.
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, { ...moved, section: target.section });

    void persistOrder(next);
}

function dropOnSection(name: string): void {
    const sourceId = draggingId.value;
    draggingId.value = null;
    const moving = fields.value.find((field) => field.id === sourceId);

    if (!moving || !canReorder.value || moving.section === name) {
        return;
    }

    const next = fields.value.filter((field) => field.id !== sourceId);
    const last = next.reduce(
        (position, field, index) => (field.section === name ? index : position),
        -1,
    );

    next.splice(last + 1, 0, { ...moving, section: name });

    void persistOrder(next);
}

/** The same move without a mouse: one step, taking on the neighbour's section. */
function move(field: PatientFormField, delta: number): void {
    const from = fields.value.findIndex((item) => item.id === field.id);
    const to = from + delta;

    if (from === -1 || to < 0 || to >= fields.value.length) {
        return;
    }

    const next = [...fields.value];
    const neighbourSection = next[to].section;
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, { ...moved, section: neighbourSection });

    void persistOrder(next);
}

function indexOf(field: PatientFormField): number {
    return fields.value.findIndex((item) => item.id === field.id);
}

// Removal. Answers survive a removal, so the prompt names how many there are.
const removing = ref<PatientFormField | null>(null);
const removeOpen = ref(false);
const removeProcessing = ref(false);
const answerCount = ref<number | null>(null);

async function confirmRemove(field: PatientFormField): Promise<void> {
    removing.value = field;
    answerCount.value = null;
    removeOpen.value = true;

    try {
        const response = await api.get<Wrapped<PatientFormFieldUsage>>(
            usage.url({ clinic: clinicId.value, patientFormField: field.id }),
        );

        if (removing.value?.id === field.id) {
            answerCount.value = response.data.answer_count;
        }
    } catch {
        // The count is only there to word the warning; the API still refuses
        // an unconfirmed removal, so a failed count changes nothing.
    }
}

const removeDescription = computed(() => {
    const label = removing.value?.label ?? 'This field';

    if (answerCount.value === null) {
        return `Checking how many patients have answered “${label}”…`;
    }

    if (answerCount.value === 0) {
        return `Nobody has answered “${label}” yet. It moves to the Removed tab, where you can put it back.`;
    }

    return `${answerCount.value} ${answerCount.value === 1 ? 'patient has' : 'patients have'} answered this. Their answers are kept and come back if you restore it.`;
});

async function remove(): Promise<void> {
    const field = removing.value;

    if (!field) {
        return;
    }

    removeProcessing.value = true;

    const url = (confirm: boolean) =>
        destroy.url(
            { clinic: clinicId.value, patientFormField: field.id },
            confirm ? { query: { confirm: 1 } } : undefined,
        );

    try {
        try {
            await api.delete(url(false));
        } catch (error) {
            // The API refuses to hide a question patients have answered until
            // it is told the answers are understood. They just were.
            if (
                error instanceof ApiError &&
                error.status === 422 &&
                'patient_form_field' in error.errors
            ) {
                await api.delete(url(true));
            } else {
                throw error;
            }
        }

        toast.success(`${field.label} removed. Its answers are kept.`);
        removeOpen.value = false;
        await refreshDefinition();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not remove the field.',
        );
    } finally {
        removeProcessing.value = false;
    }
}

const restoring = ref<number[]>([]);

async function restoreField(field: PatientFormField): Promise<void> {
    restoring.value = [...restoring.value, field.id];

    try {
        await api.post(
            restore.url({ clinic: clinicId.value, patientFormField: field.id }),
        );
        toast.success(`${field.label} is back on the form, with its answers.`);
        await Promise.all([refreshDefinition(), refreshArchived()]);
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not restore the field.',
        );
    } finally {
        restoring.value = restoring.value.filter((id) => id !== field.id);
    }
}

// The blank, printable form, built from this same definition.
const printing = ref(false);

async function printBlank(): Promise<void> {
    printing.value = true;

    try {
        await downloadFile(pdf.url(clinicId.value), 'patient-form.pdf');
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not download the form.',
        );
    } finally {
        printing.value = false;
    }
}

/** What the preview draws for the plain, single-line types. */
function previewInputType(type: PatientFormFieldType): string {
    return type === 'EMAIL'
        ? 'email'
        : type === 'PHONE'
          ? 'tel'
          : type === 'NUMBER'
            ? 'number'
            : 'text';
}
</script>

<template>
    <Head title="Patient form" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Patient form"
            description="This defines what every patient record holds — the questions staff answer in the patient dialog, what the intake link asks for, and what the printable form prints. Built-in fields are real columns on the record, so the schedule, invoices and patient search read them by name."
        >
            <template #actions>
                <Button
                    v-if="canImport"
                    variant="outline"
                    @click="importOpen = true"
                >
                    <FileUp />
                    Import from a file
                </Button>
                <Button
                    variant="outline"
                    :disabled="printing"
                    @click="printBlank"
                >
                    <Spinner v-if="printing" />
                    <Printer v-else />
                    Print blank form
                </Button>
                <Button v-if="canAddFields" @click="openField(null)">
                    <Plus />
                    Add field
                </Button>
            </template>
        </PageHeader>

        <Tabs v-model="tab">
            <TabsList>
                <TabsTrigger value="form">Form</TabsTrigger>
                <TabsTrigger value="removed">Removed</TabsTrigger>
                <TabsTrigger v-if="canSeeHistory" value="history"
                    >History</TabsTrigger
                >
            </TabsList>

            <TabsContent value="form" class="mt-6">
                <div class="grid gap-6 lg:grid-cols-2">
                    <Card
                        class="animate__animated animate__fadeInUp gap-0 overflow-hidden py-0"
                        style="--animate-duration: 0.5s"
                    >
                        <div
                            class="flex items-center justify-between gap-2 border-b px-4 py-3"
                        >
                            <div class="space-y-0.5">
                                <h2 class="text-sm font-semibold">Fields</h2>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        canReorder
                                            ? 'Drag a field to reorder it, or drop it on a heading to move it to that section.'
                                            : 'The order patients and staff see these questions in.'
                                    }}
                                </p>
                            </div>
                            <Spinner v-if="reordering" class="shrink-0" />
                        </div>

                        <div
                            v-if="definitionLoading && !definition"
                            class="space-y-3 p-4"
                        >
                            <Skeleton
                                v-for="row in 7"
                                :key="row"
                                class="h-11"
                            />
                        </div>

                        <div v-else class="divide-y">
                            <section
                                v-for="section in sections"
                                :key="section.name"
                            >
                                <h3
                                    class="border-b bg-muted/60 px-4 py-2 text-xs font-medium text-muted-foreground"
                                    @dragover.prevent
                                    @drop.prevent="dropOnSection(section.name)"
                                >
                                    {{ section.name }}
                                </h3>
                                <ul class="divide-y">
                                    <li
                                        v-for="field in section.fields"
                                        :key="field.id"
                                        :draggable="canReorder"
                                        :class="
                                            cn(
                                                'flex items-center gap-3 px-3 py-2.5 transition-colors',
                                                draggingId === field.id &&
                                                    'opacity-50',
                                                canReorder &&
                                                    'hover:bg-accent/40',
                                            )
                                        "
                                        @dragstart="startDrag($event, field)"
                                        @dragend="draggingId = null"
                                        @dragover.prevent
                                        @drop.prevent="dropOnField(field)"
                                    >
                                        <GripVertical
                                            v-if="canReorder"
                                            class="size-4 shrink-0 cursor-grab text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <div class="min-w-0 flex-1">
                                            <p
                                                class="flex flex-wrap items-center gap-1.5 text-sm font-medium"
                                            >
                                                {{ field.label }}
                                                <Badge
                                                    v-if="field.is_built_in"
                                                    variant="secondary"
                                                    class="gap-1 font-normal"
                                                >
                                                    <Lock class="size-3" />
                                                    Built-in
                                                </Badge>
                                                <Badge
                                                    v-if="field.is_required"
                                                    variant="outline"
                                                    class="font-normal"
                                                >
                                                    Required
                                                </Badge>
                                            </p>
                                            <p
                                                class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                                            >
                                                <span
                                                    class="font-mono text-xs"
                                                    >{{ field.key }}</span
                                                >
                                                <span aria-hidden="true"
                                                    >·</span
                                                >
                                                <span>{{
                                                    patientFormFieldTypeLabels[
                                                        field.type
                                                    ]
                                                }}</span>
                                            </p>
                                        </div>

                                        <DropdownMenu
                                            v-if="
                                                canEditFields ||
                                                canReorder ||
                                                (canRemoveFields &&
                                                    !field.is_built_in)
                                            "
                                        >
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    class="size-8 shrink-0"
                                                    :aria-label="`Actions for ${field.label}`"
                                                >
                                                    <MoreHorizontal />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    v-if="canEditFields"
                                                    @select="openField(field)"
                                                >
                                                    <Pencil />
                                                    Edit
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="canReorder"
                                                    :disabled="
                                                        indexOf(field) === 0
                                                    "
                                                    @select="move(field, -1)"
                                                >
                                                    <ArrowUp />
                                                    Move up
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="canReorder"
                                                    :disabled="
                                                        indexOf(field) ===
                                                        fields.length - 1
                                                    "
                                                    @select="move(field, 1)"
                                                >
                                                    <ArrowDown />
                                                    Move down
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="
                                                        canRemoveFields &&
                                                        !field.is_built_in
                                                    "
                                                    variant="destructive"
                                                    @select="
                                                        confirmRemove(field)
                                                    "
                                                >
                                                    <Trash2 />
                                                    Remove
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </li>
                                </ul>
                            </section>
                        </div>
                    </Card>

                    <Card
                        class="animate__animated animate__fadeInUp h-fit lg:sticky lg:top-6"
                        style="--animate-duration: 0.5s"
                    >
                        <CardHeader>
                            <CardTitle>Live preview</CardTitle>
                            <CardDescription>
                                The form as a patient sees it. Nothing here can
                                be typed in — it is drawn from the definition on
                                the left.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div
                                v-if="definitionLoading && !definition"
                                class="space-y-4"
                            >
                                <Skeleton
                                    v-for="row in 5"
                                    :key="row"
                                    class="h-14"
                                />
                            </div>
                            <fieldset v-else disabled class="grid gap-6">
                                <legend class="sr-only">
                                    Preview of the patient form
                                </legend>
                                <div
                                    v-for="section in sections"
                                    :key="section.name"
                                    class="grid gap-4"
                                >
                                    <h4
                                        class="border-b pb-1 text-sm font-semibold tracking-tight"
                                    >
                                        {{ section.name }}
                                    </h4>
                                    <div
                                        v-for="field in section.fields"
                                        :key="field.id"
                                        class="grid gap-2"
                                    >
                                        <Label :for="`preview-${field.id}`">
                                            {{ field.label }}
                                            <span
                                                v-if="field.is_required"
                                                class="text-red-600 dark:text-red-500"
                                                aria-hidden="true"
                                                >*</span
                                            >
                                        </Label>

                                        <Textarea
                                            v-if="field.type === 'TEXTAREA'"
                                            :id="`preview-${field.id}`"
                                            rows="3"
                                            :placeholder="
                                                field.placeholder ?? ''
                                            "
                                        />
                                        <DatePicker
                                            v-else-if="field.type === 'DATE'"
                                            :id="`preview-${field.id}`"
                                            disabled
                                            :placeholder="
                                                field.placeholder ||
                                                'Pick a date'
                                            "
                                        />
                                        <Select
                                            v-else-if="field.type === 'SELECT'"
                                            disabled
                                        >
                                            <SelectTrigger
                                                :id="`preview-${field.id}`"
                                                class="w-full"
                                            >
                                                <SelectValue
                                                    :placeholder="
                                                        field.placeholder ||
                                                        'Choose one'
                                                    "
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="option in field.options"
                                                    :key="option"
                                                    :value="option"
                                                >
                                                    {{ option }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <div
                                            v-else-if="field.type === 'RADIO'"
                                            class="grid gap-1.5"
                                        >
                                            <label
                                                v-for="option in field.options"
                                                :key="option"
                                                class="flex items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="radio"
                                                    :name="`preview-${field.id}`"
                                                    class="size-4 accent-teal-600"
                                                />
                                                {{ option }}
                                            </label>
                                        </div>
                                        <div
                                            v-else-if="
                                                field.type === 'MULTISELECT'
                                            "
                                            class="grid gap-1.5"
                                        >
                                            <span
                                                v-for="option in field.options"
                                                :key="option"
                                                class="flex items-center gap-2 text-sm"
                                            >
                                                <Checkbox />
                                                {{ option }}
                                            </span>
                                        </div>
                                        <span
                                            v-else-if="
                                                field.type === 'CHECKBOX'
                                            "
                                            class="flex items-center gap-2 text-sm"
                                        >
                                            <Checkbox
                                                :id="`preview-${field.id}`"
                                            />
                                            {{
                                                field.placeholder || field.label
                                            }}
                                        </span>
                                        <Input
                                            v-else
                                            :id="`preview-${field.id}`"
                                            :type="previewInputType(field.type)"
                                            :placeholder="
                                                field.placeholder ?? ''
                                            "
                                        />

                                        <p
                                            v-if="field.help_text"
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ field.help_text }}
                                        </p>
                                    </div>
                                </div>
                            </fieldset>
                        </CardContent>
                    </Card>
                </div>
            </TabsContent>

            <TabsContent value="removed" class="mt-6">
                <Card
                    class="animate__animated animate__fadeInUp overflow-hidden py-0"
                    style="--animate-duration: 0.5s"
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Field</TableHead>
                                <TableHead>Key</TableHead>
                                <TableHead class="hidden md:table-cell"
                                    >Type</TableHead
                                >
                                <TableHead>Answers</TableHead>
                                <TableHead class="hidden md:table-cell"
                                    >Removed</TableHead
                                >
                                <TableHead class="w-28" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <template v-if="!archived">
                                <TableRow v-for="row in 3" :key="row">
                                    <TableCell colspan="6">
                                        <Skeleton class="h-6 w-full" />
                                    </TableCell>
                                </TableRow>
                            </template>
                            <TableRow
                                v-for="field in archived?.data ?? []"
                                :key="field.id"
                            >
                                <TableCell class="font-medium">{{
                                    field.label
                                }}</TableCell>
                                <TableCell
                                    class="font-mono text-xs text-muted-foreground"
                                    >{{ field.key }}</TableCell
                                >
                                <TableCell
                                    class="hidden text-muted-foreground md:table-cell"
                                    >{{
                                        patientFormFieldTypeLabels[field.type]
                                    }}</TableCell
                                >
                                <TableCell class="text-muted-foreground">
                                    {{ field.answer_count ?? 0 }}
                                    {{
                                        (field.answer_count ?? 0) === 1
                                            ? 'answer'
                                            : 'answers'
                                    }}
                                    kept
                                </TableCell>
                                <TableCell
                                    class="hidden text-muted-foreground md:table-cell"
                                    >{{
                                        formatDate(field.archived_at)
                                    }}</TableCell
                                >
                                <TableCell class="text-right">
                                    <Button
                                        v-if="canRestoreFields"
                                        size="sm"
                                        variant="outline"
                                        :disabled="restoring.includes(field.id)"
                                        @click="restoreField(field)"
                                    >
                                        <Spinner
                                            v-if="restoring.includes(field.id)"
                                        />
                                        <RotateCcw v-else />
                                        Restore
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <EmptyState
                        v-if="archived && archived.data.length === 0"
                        title="Nothing has been removed from this form."
                        description="Removing a question only hides it. The answers patients already gave stay in the record and come back if you restore it."
                    />
                </Card>
            </TabsContent>

            <TabsContent v-if="canSeeHistory" value="history" class="mt-6">
                <div class="flex flex-col gap-4">
                    <p class="text-sm text-muted-foreground">
                        Who changed the form and what changed — fields added,
                        renamed, retyped, moved, removed and restored. Entries
                        cannot be edited or deleted.
                    </p>
                    <Card
                        class="animate__animated animate__fadeInUp gap-0 overflow-hidden py-0"
                        style="--animate-duration: 0.5s"
                    >
                        <ActivityLogList
                            :logs="history?.data ?? null"
                            :loading="historyLoading"
                            hide-support-names
                        />
                        <EmptyState
                            v-if="history && history.data.length === 0"
                            title="No changes yet"
                            description="Every edit to the patient form is recorded here, along with who made it."
                        />
                    </Card>
                </div>
            </TabsContent>
        </Tabs>
    </div>

    <FormFieldDialog
        v-model:open="fieldDialogOpen"
        :field="editing"
        :sections="sectionNames"
        @saved="
            (field) => {
                toast.success(`${field.label} saved.`);
                refreshDefinition();
            }
        "
    />
    <FormImportWizard
        v-if="canImport"
        v-model:open="importOpen"
        @saved="
            (added) => {
                toast.success(
                    `${added.length} ${added.length === 1 ? 'field' : 'fields'} added to the form.`,
                );
                refreshDefinition();
            }
        "
    />
    <ConfirmDialog
        v-model:open="removeOpen"
        :title="`Remove “${removing?.label}” from the form?`"
        :description="removeDescription"
        confirm-label="Remove"
        :processing="removeProcessing"
        @confirm="remove"
    />
</template>
