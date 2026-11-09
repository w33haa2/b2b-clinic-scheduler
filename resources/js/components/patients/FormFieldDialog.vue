<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Lock, Plus, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClinic } from '@/composables/useClinic';
import { fieldTypeHasOptions, patientFormFieldTypeLabels } from '@/lib/format';
import { store, update } from '@/routes/clinic/patient-form/fields';
import type { PatientFormField, PatientFormFieldType, Wrapped } from '@/types';

/**
 * Adds a question to the clinic's patient form, or edits one.
 *
 * A built-in field is a real column on the patient record, so its key and
 * type are locked here. For a custom field the API refuses a rename or a
 * retype once patients have answered it; that refusal comes back as a 422
 * and turns into the "Save anyway" prompt below.
 */
const props = withDefaults(
    defineProps<{ field?: PatientFormField | null; sections?: string[] }>(),
    { field: null, sections: () => [] },
);
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [field: PatientFormField] }>();

const { clinicId } = useClinic();

type FieldBody = {
    key: string;
    label: string;
    type: PatientFormFieldType;
    options: string[];
    section: string;
    placeholder: string;
    help_text: string;
    is_required: boolean;
    /** Held as a string so the number input can be emptied. */
    max_length: string;
    /** Accepts a change that affects answers patients have already given. */
    confirm: boolean;
};

const fieldTypes = Object.entries(patientFormFieldTypeLabels) as Array<
    [PatientFormFieldType, string]
>;

/** Two columns the database will not accept as null. */
const alwaysRequiredKeys = ['first_name', 'last_name'];

const form = useHttp<FieldBody, Wrapped<PatientFormField>>({
    key: '',
    label: '',
    type: 'TEXT',
    options: ['', ''],
    section: 'Patient details',
    placeholder: '',
    help_text: '',
    is_required: false,
    max_length: '',
    confirm: false,
});

const isBuiltIn = computed(() => props.field?.is_built_in === true);
const hasOptions = computed(() => fieldTypeHasOptions(form.type));
const hasLength = computed(
    () => form.type === 'TEXT' || form.type === 'TEXTAREA',
);
const requiredLocked = computed(
    () =>
        isBuiltIn.value && alwaysRequiredKeys.includes(props.field?.key ?? ''),
);

/** Whether the key box has been typed in, so the label stops filling it. */
const keyEdited = ref(false);

/** "Date of birth" → "date_of_birth", the shape of key the API accepts. */
function toKey(label: string): string {
    const slug = label
        .normalize('NFKD')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    if (slug === '') {
        return '';
    }

    return (/^[a-z]/.test(slug) ? slug : `field_${slug}`).slice(0, 100);
}

watch(
    () => form.label,
    (label) => {
        if (!props.field && !keyEdited.value) {
            form.key = toKey(label);
        }
    },
);

// A built-in field keeps its key and type whatever is sent, so they are left
// out entirely rather than sent back unchanged.
form.transform((data) => {
    const payload: Record<string, unknown> = {
        label: data.label,
        section: data.section.trim() || 'Patient details',
        placeholder: data.placeholder || null,
        help_text: data.help_text || null,
        is_required: data.is_required,
        confirm: data.confirm,
    };

    // Only the types that offer a length send `validation`, so a number
    // field's stored min and max are left alone.
    if (hasLength.value) {
        payload.validation = data.max_length
            ? { max_length: Number(data.max_length) }
            : {};
    }

    if (!isBuiltIn.value) {
        payload.key = data.key;
        payload.type = data.type;
    }

    if (fieldTypeHasOptions(data.type)) {
        payload.options = data.options
            .map((option) => option.trim())
            .filter((option) => option !== '');
    }

    return payload;
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const field = props.field;
    keyEdited.value = field !== null;

    form.defaults({
        key: field?.key ?? '',
        label: field?.label ?? '',
        type: field?.type ?? 'TEXT',
        options: field?.options.length ? [...field.options] : ['', ''],
        section: field?.section ?? props.sections[0] ?? 'Patient details',
        placeholder: field?.placeholder ?? '',
        help_text: field?.help_text ?? '',
        is_required: field?.is_required ?? false,
        max_length: field?.validation.max_length
            ? String(field.validation.max_length)
            : '',
        confirm: false,
    })
        .reset()
        .clearErrors();
});

/** The API answers with keys this body does not have, such as the type column. */
const errors = computed(
    () => form.errors as unknown as Record<string, string | undefined>,
);

/**
 * The backfill prompt. Renaming the key of an answered field, or changing
 * its type, is refused until the change is confirmed. A refusal about
 * answers that would not survive the new type ("not valid") can never be
 * confirmed, so it is only reported.
 */
const confirmableError = computed(() => {
    const message = errors.value.key ?? errors.value.patient_form_field_type_id;

    return message !== undefined && !/not valid/i.test(message)
        ? message
        : null;
});

const keyError = computed(() =>
    confirmableError.value === errors.value.key ? undefined : errors.value.key,
);
const typeError = computed(() =>
    confirmableError.value === errors.value.patient_form_field_type_id
        ? errors.value.type
        : (errors.value.patient_form_field_type_id ?? errors.value.type),
);
const optionsError = computed(
    () =>
        errors.value.options ??
        Object.entries(errors.value).find(([key]) =>
            key.startsWith('options.'),
        )?.[1],
);

function addOption(): void {
    form.options = [...form.options, ''];
}

function removeOption(index: number): void {
    form.options = form.options.filter((_, position) => position !== index);
}

async function submit(confirm = false): Promise<void> {
    form.confirm = confirm;

    const options = {
        onSuccess: (response: Wrapped<PatientFormField>) => {
            emit('saved', response.data);
            open.value = false;
        },
    };

    if (props.field) {
        await form.patch(
            update.url({
                clinic: clinicId.value,
                patientFormField: props.field.id,
            }),
            options,
        );
    } else {
        await form.post(store.url(clinicId.value), options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    {{ field ? 'Edit field' : 'Add field' }}
                    <TooltipProvider v-if="isBuiltIn" :delay-duration="150">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Badge
                                    tabindex="0"
                                    variant="secondary"
                                    class="gap-1 font-normal"
                                >
                                    <Lock class="size-3" />
                                    Built-in
                                </Badge>
                            </TooltipTrigger>
                            <TooltipContent class="max-w-xs">
                                This field is a real column on the patient
                                record. The schedule, invoices and patient
                                search read it by name, so its key and type are
                                fixed. Everything else is yours to change.
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </DialogTitle>
                <DialogDescription>
                    {{
                        isBuiltIn
                            ? 'You can rename this field, move it and give it help text. It can never be removed.'
                            : 'Every patient record gets this question. Patients who have already answered keep their answers.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <Alert v-if="confirmableError" variant="destructive">
                <AlertDescription>
                    {{ confirmableError }}
                </AlertDescription>
            </Alert>

            <form id="field-form" class="grid gap-4" @submit.prevent="submit()">
                <FormField
                    label="Label"
                    for="field-label"
                    :error="errors.label"
                    hint="What staff and patients read above the box."
                >
                    <Input
                        id="field-label"
                        v-model="form.label"
                        v-focus
                        required
                    />
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Key"
                        for="field-key"
                        :error="keyError"
                        :hint="
                            isBuiltIn
                                ? 'Fixed: this is the column name on the patient record.'
                                : 'How the answer is stored. Lower-case letters, numbers and underscores.'
                        "
                    >
                        <Input
                            id="field-key"
                            v-model="form.key"
                            class="font-mono"
                            :disabled="isBuiltIn"
                            :required="!isBuiltIn"
                            @input="keyEdited = true"
                        />
                    </FormField>

                    <FormField
                        label="Type"
                        for="field-type"
                        :error="typeError"
                        :hint="
                            isBuiltIn
                                ? 'Fixed: the column only holds this kind of answer.'
                                : undefined
                        "
                    >
                        <Select v-model="form.type" :disabled="isBuiltIn">
                            <SelectTrigger id="field-type" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="[value, typeLabel] in fieldTypes"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ typeLabel }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </FormField>
                </div>

                <div v-if="hasOptions" class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Choices</span>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="h-7 px-2 text-xs"
                            @click="addOption"
                        >
                            <Plus />
                            Add choice
                        </Button>
                    </div>
                    <div
                        v-for="(_, index) in form.options"
                        :key="index"
                        class="flex items-center gap-2"
                    >
                        <Input
                            v-model="form.options[index]"
                            :placeholder="`Choice ${index + 1}`"
                            :aria-label="`Choice ${index + 1}`"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="shrink-0"
                            :disabled="form.options.length <= 2"
                            :aria-label="`Remove choice ${index + 1}`"
                            @click="removeOption(index)"
                        >
                            <X />
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        At least two choices. Renaming one does not rewrite
                        answers that already use the old wording.
                    </p>
                    <InputError :message="optionsError" />
                </div>

                <FormField
                    label="Section"
                    for="field-section"
                    :error="errors.section"
                    :hint="
                        sections.length
                            ? `Already on this form: ${sections.join(', ')}.`
                            : 'The heading this question is grouped under.'
                    "
                >
                    <Input id="field-section" v-model="form.section" />
                    <div v-if="sections.length" class="flex flex-wrap gap-1">
                        <Button
                            v-for="name in sections"
                            :key="name"
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-6 px-2 text-xs font-normal"
                            @click="form.section = name"
                        >
                            {{ name }}
                        </Button>
                    </div>
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Placeholder"
                        for="field-placeholder"
                        :error="errors.placeholder"
                        hint="Faint example text inside the empty box."
                    >
                        <Input
                            id="field-placeholder"
                            v-model="form.placeholder"
                        />
                    </FormField>

                    <FormField
                        v-if="hasLength"
                        label="Longest answer"
                        for="field-max-length"
                        :error="errors['validation.max_length']"
                        hint="Characters. Leave empty for no limit."
                    >
                        <Input
                            id="field-max-length"
                            v-model="form.max_length"
                            type="number"
                            min="1"
                            step="1"
                        />
                    </FormField>
                </div>

                <FormField
                    label="Help text"
                    for="field-help"
                    :error="errors.help_text"
                    hint="A line under the box explaining what to put in it."
                >
                    <Textarea
                        id="field-help"
                        v-model="form.help_text"
                        rows="2"
                    />
                </FormField>

                <label
                    class="flex items-start gap-3 rounded-md border p-3"
                    :class="requiredLocked ? 'opacity-70' : 'cursor-pointer'"
                >
                    <Checkbox
                        :model-value="form.is_required"
                        :disabled="requiredLocked"
                        @update:model-value="
                            (checked) => (form.is_required = checked === true)
                        "
                    />
                    <span class="grid gap-1">
                        <span class="text-sm font-medium"
                            >Must be answered</span
                        >
                        <span class="text-xs text-muted-foreground">
                            {{
                                requiredLocked
                                    ? 'Every patient record needs this one, so it cannot be made optional.'
                                    : 'Nobody can save a patient without filling this in. Existing records are left alone.'
                            }}
                        </span>
                    </span>
                </label>
                <InputError :message="errors.is_required" />
            </form>

            <DialogFooter class="gap-2">
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    v-if="confirmableError"
                    variant="destructive"
                    :disabled="form.processing"
                    @click="submit(true)"
                >
                    <Spinner v-if="form.processing" />
                    Save anyway
                </Button>
                <Button
                    v-else
                    type="submit"
                    form="field-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    {{ field ? 'Save changes' : 'Add field' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
