<script setup lang="ts">
import { FileUp, Plus, Upload, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AlertError from '@/components/AlertError.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError } from '@/lib/api';
import { fieldTypeHasOptions, patientFormFieldTypeLabels } from '@/lib/format';
import { cn } from '@/lib/utils';
import { store as extractStore } from '@/routes/clinic/patient-form/extract';
import { bulk } from '@/routes/clinic/patient-form/fields';
import type {
    ExtractedFormField,
    FormExtraction,
    PatientFormField,
    PatientFormFieldType,
    Wrapped,
} from '@/types';

/**
 * Reads a form the clinic already uses and suggests the fields in it.
 *
 * Reading is a separate step from saving: the upload is parsed in the
 * request and thrown away, and nothing reaches the patient form until the
 * review step is confirmed.
 */
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [fields: PatientFormField[]] }>();

const { clinicId } = useClinic();

const accept = '.csv,.tsv,.json,.html,.htm,.docx,.pdf,.txt,.md';
const maxBytes = 10 * 1024 * 1024;

const fieldTypes = Object.entries(patientFormFieldTypeLabels) as Array<
    [PatientFormFieldType, string]
>;

const confidenceTones: Record<ReviewRow['confidence'] & string, string> = {
    high: 'bg-teal-100 text-teal-800 dark:bg-teal-500/15 dark:text-teal-300',
    medium: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    low: 'bg-muted text-muted-foreground',
};

type ReviewRow = {
    /** Local identity, so editing a row does not re-key the list. */
    uid: number;
    selected: boolean;
    key: string;
    label: string;
    type: PatientFormFieldType;
    options: string[];
    section: string;
    is_required: boolean;
    confidence: ExtractedFormField['confidence'] | null;
    source_line: string | null;
    existing_field_id: number | null;
    existing_label: string | null;
    existing_is_archived: boolean;
};

const step = ref<'upload' | 'review'>('upload');
const file = ref<File | null>(null);
const text = ref('');
const dropActive = ref(false);
const reading = ref(false);
const saving = ref(false);
const warning = ref<string | null>(null);
const errors = ref<Record<string, string[]>>({});
const rows = ref<ReviewRow[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);

let nextUid = 0;

const chosen = computed(() =>
    rows.value.filter((row) => row.selected && row.existing_field_id === null),
);
const errorMessages = computed(() => Object.values(errors.value).flat());

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    step.value = 'upload';
    file.value = null;
    text.value = '';
    warning.value = null;
    errors.value = {};
    rows.value = [];
    dropActive.value = false;
});

function toRow(field: ExtractedFormField): ReviewRow {
    return {
        uid: nextUid++,
        // A key the clinic already has cannot be added twice.
        selected: field.existing_field_id === null,
        key: field.key,
        label: field.label,
        type: field.type,
        options: field.options,
        section: field.section ?? 'Patient details',
        is_required: field.is_required,
        confidence: field.confidence,
        source_line: field.source_line,
        existing_field_id: field.existing_field_id,
        existing_label: field.existing_label,
        existing_is_archived: field.existing_is_archived,
    };
}

function chooseFile(files: FileList | null): void {
    const picked = files?.[0] ?? null;

    if (picked && picked.size > maxBytes) {
        toast.error('That file is larger than 10 MB.');

        return;
    }

    file.value = picked;
    errors.value = {};
}

function onDrop(event: DragEvent): void {
    dropActive.value = false;
    chooseFile(event.dataTransfer?.files ?? null);
}

/** Clear the input afterwards, so picking the same file again still fires. */
function onPick(event: Event): void {
    const input = event.target as HTMLInputElement;

    chooseFile(input.files);
    input.value = '';
}

async function read(): Promise<void> {
    reading.value = true;
    errors.value = {};
    warning.value = null;

    try {
        let payload: FormData | { text: string };

        if (file.value) {
            payload = new FormData();
            payload.append('file', file.value);
        } else {
            payload = { text: text.value };
        }

        const response = await api.post<Wrapped<FormExtraction>>(
            extractStore.url(clinicId.value),
            payload,
        );

        warning.value = response.data.warning;
        rows.value = response.data.fields.map(toRow);
        step.value = 'review';
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.errors;
            toast.error(error.firstMessage);
        }
    } finally {
        reading.value = false;
    }
}

function addRow(): void {
    rows.value = [
        ...rows.value,
        {
            uid: nextUid++,
            selected: true,
            key: '',
            label: '',
            type: 'TEXT',
            options: [],
            section: 'Patient details',
            is_required: false,
            confidence: null,
            source_line: null,
            existing_field_id: null,
            existing_label: null,
            existing_is_archived: false,
        },
    ];
}

function removeRow(uid: number): void {
    rows.value = rows.value.filter((row) => row.uid !== uid);
}

async function save(): Promise<void> {
    saving.value = true;
    errors.value = {};

    try {
        const response = await api.post<{ data: PatientFormField[] }>(
            bulk.url(clinicId.value),
            {
                fields: chosen.value.map((row) => ({
                    key: row.key,
                    label: row.label,
                    type: row.type,
                    options: fieldTypeHasOptions(row.type)
                        ? row.options
                        : undefined,
                    section: row.section || 'Patient details',
                    is_required: row.is_required,
                })),
            },
        );

        emit('saved', response.data);
        open.value = false;
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.errors;
            toast.error(error.firstMessage);
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <FileUp class="size-5 text-teal-600" />
                    Import from a file
                </DialogTitle>
                <DialogDescription>
                    Upload the form you use today, or paste it in. We read it to
                    suggest fields and show you the list first —
                    <span class="font-medium text-foreground"
                        >nothing is saved until you confirm</span
                    >, and the file itself is never stored.
                </DialogDescription>
            </DialogHeader>

            <AlertError
                v-if="errorMessages.length"
                :errors="errorMessages"
                title="That could not be imported."
            />

            <template v-if="step === 'upload'">
                <div
                    :class="
                        cn(
                            'flex flex-col items-center gap-2 rounded-lg border border-dashed px-6 py-10 text-center transition-colors',
                            dropActive
                                ? 'border-teal-600 bg-teal-50/60 dark:bg-teal-500/10'
                                : 'bg-muted/30',
                        )
                    "
                    @dragover.prevent="dropActive = true"
                    @dragleave.self="dropActive = false"
                    @drop.prevent="onDrop"
                >
                    <Upload class="size-6 text-muted-foreground" />
                    <p class="text-sm font-medium">
                        Drop a file here, or pick one
                    </p>
                    <p class="text-xs text-muted-foreground">
                        CSV, TSV, JSON, HTML, Word (.docx), PDF, text or
                        Markdown. Up to 10 MB.
                    </p>
                    <input
                        ref="fileInput"
                        type="file"
                        class="sr-only"
                        :accept="accept"
                        @change="onPick"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-1"
                        @click="fileInput?.click()"
                    >
                        Choose a file
                    </Button>
                    <p
                        v-if="file"
                        class="mt-1 flex items-center gap-2 text-sm font-medium"
                    >
                        {{ file.name }}
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-6"
                            aria-label="Remove the chosen file"
                            @click="file = null"
                        >
                            <X />
                        </Button>
                    </p>
                </div>

                <FormField
                    label="Or paste the form"
                    for="import-text"
                    :error="errors.text?.[0]"
                    hint="One question per line. This is the way round a scanned PDF: copy the text out of it and paste it here."
                >
                    <Textarea
                        id="import-text"
                        v-model="text"
                        rows="6"
                        :disabled="file !== null"
                        placeholder="First name:&#10;Date of birth:&#10;Allergies:"
                    />
                </FormField>
            </template>

            <template v-else>
                <Alert v-if="warning">
                    <AlertTitle>{{
                        rows.length
                            ? 'Only part of that was read'
                            : 'Nothing to review'
                    }}</AlertTitle>
                    <AlertDescription>{{ warning }}</AlertDescription>
                </Alert>

                <EmptyState
                    v-if="rows.length === 0"
                    title="No fields were found"
                    description="Go back and paste the text of the form instead, or add the questions by hand."
                >
                    <Button variant="outline" @click="step = 'upload'">
                        Back
                    </Button>
                </EmptyState>

                <div v-else class="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-10" />
                                <TableHead>Label</TableHead>
                                <TableHead>Key</TableHead>
                                <TableHead class="w-40">Type</TableHead>
                                <TableHead class="w-10" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="row in rows"
                                :key="row.uid"
                                :class="
                                    row.existing_field_id !== null &&
                                    'opacity-60'
                                "
                            >
                                <TableCell class="align-top">
                                    <Checkbox
                                        :model-value="row.selected"
                                        :disabled="
                                            row.existing_field_id !== null
                                        "
                                        :aria-label="`Add ${row.label || 'this field'}`"
                                        @update:model-value="
                                            (checked) =>
                                                (row.selected =
                                                    checked === true)
                                        "
                                    />
                                </TableCell>
                                <TableCell class="align-top">
                                    <Input
                                        v-model="row.label"
                                        class="h-8"
                                        :disabled="
                                            row.existing_field_id !== null
                                        "
                                        :aria-label="`Label for ${row.key || 'the new field'}`"
                                    />
                                    <p
                                        v-if="row.source_line"
                                        class="mt-1 line-clamp-2 font-mono text-xs text-muted-foreground"
                                    >
                                        {{ row.source_line }}
                                    </p>
                                    <p
                                        v-if="row.existing_field_id !== null"
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Already on your form{{
                                            row.existing_is_archived
                                                ? ', in Removed'
                                                : ''
                                        }}.
                                    </p>
                                </TableCell>
                                <TableCell class="align-top">
                                    <Input
                                        v-model="row.key"
                                        class="h-8 font-mono"
                                        :disabled="
                                            row.existing_field_id !== null
                                        "
                                        :aria-label="`Key for ${row.label || 'the new field'}`"
                                    />
                                    <Badge
                                        v-if="row.confidence"
                                        variant="secondary"
                                        :class="
                                            cn(
                                                'mt-1 border-transparent font-normal',
                                                confidenceTones[row.confidence],
                                            )
                                        "
                                    >
                                        {{ row.confidence }} confidence
                                    </Badge>
                                </TableCell>
                                <TableCell class="align-top">
                                    <Select
                                        v-model="row.type"
                                        :disabled="
                                            row.existing_field_id !== null
                                        "
                                    >
                                        <SelectTrigger
                                            class="h-8 w-full"
                                            :aria-label="`Type for ${row.label || 'the new field'}`"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="[
                                                    value,
                                                    typeLabel,
                                                ] in fieldTypes"
                                                :key="value"
                                                :value="value"
                                            >
                                                {{ typeLabel }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </TableCell>
                                <TableCell class="align-top">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="`Remove ${row.label || 'this row'} from the list`"
                                        @click="removeRow(row.uid)"
                                    >
                                        <X />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <Button
                    v-if="rows.length"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="self-start"
                    @click="addRow"
                >
                    <Plus />
                    Add a field by hand
                </Button>
            </template>

            <DialogFooter class="items-center sm:justify-between">
                <p
                    v-if="step === 'review'"
                    class="text-sm text-muted-foreground"
                >
                    {{ chosen.length }}
                    {{ chosen.length === 1 ? 'field' : 'fields' }} will be added
                </p>
                <div class="flex gap-2 sm:ml-auto">
                    <Button
                        v-if="step === 'review'"
                        variant="outline"
                        @click="step = 'upload'"
                    >
                        Back
                    </Button>
                    <Button v-else variant="outline" @click="open = false">
                        Cancel
                    </Button>
                    <Button
                        v-if="step === 'upload'"
                        :disabled="reading || (!file && !text.trim())"
                        @click="read"
                    >
                        <Spinner v-if="reading" />
                        Read the form
                    </Button>
                    <Button
                        v-else
                        :disabled="saving || chosen.length === 0"
                        @click="save"
                    >
                        <Spinner v-if="saving" />
                        Add {{ chosen.length }}
                        {{ chosen.length === 1 ? 'field' : 'fields' }}
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
