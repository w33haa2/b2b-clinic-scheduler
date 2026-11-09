<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import DynamicPatientForm from '@/components/patients/DynamicPatientForm.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { show as showForm } from '@/routes/clinic/patient-form';
import { store, update } from '@/routes/clinic/patients';
import type { Patient, PatientForm, PatientFormField, Wrapped } from '@/types';

/**
 * Adds or edits a patient using the clinic's own patient form: which
 * questions appear, in what order and which must be answered all come from
 * the form definition, so a field added in the builder shows up here without
 * a change in code.
 */
const props = defineProps<{ patient?: Patient | null }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [patient: Patient] }>();

const { clinicId } = useClinic();

type AnswerValue = string | boolean | string[] | null;

/** The payload the API wants: one flat object keyed by field key. */
type Answers = Record<string, AnswerValue>;

// Nothing is fetched until the dialog is opened; a null URL skips the request.
const {
    data: definition,
    loading,
    error,
} = useApiQuery<Wrapped<PatientForm>>(() =>
    open.value ? showForm.url(clinicId.value) : null,
);

const sections = computed(() => definition.value?.data.sections ?? []);

const form = useHttp<Answers, Wrapped<Patient>>({});

// An emptied box clears the answer rather than saving "".
form.transform((data) =>
    Object.fromEntries(
        Object.entries(data).map(([key, value]) => [
            key,
            value === '' ? null : value,
        ]),
    ),
);

/** The form writes straight into the useHttp data, which is keyed the same way. */
const answers = computed(() => form as unknown as Record<string, unknown>);

/** The API answers with field keys, which this body only learns at runtime. */
const errors = computed(
    () => form.errors as unknown as Record<string, string | undefined>,
);

/**
 * What the patient record already says for a field. Built-in fields are
 * columns on the patient; the rest are answers kept under `fields`.
 */
function storedAnswer(field: PatientFormField, patient: Patient): unknown {
    return field.is_built_in
        ? (patient as unknown as Record<string, unknown>)[field.key]
        : patient.fields?.[field.key];
}

/** A stored answer as the control holds it, or a type-appropriate empty. */
function controlValue(field: PatientFormField, stored: unknown): AnswerValue {
    if (field.type === 'MULTISELECT') {
        return Array.isArray(stored)
            ? (stored as unknown[]).map((item) => String(item))
            : [];
    }

    if (field.type === 'CHECKBOX') {
        return stored === true || stored === 1;
    }

    return stored === null || stored === undefined ? '' : String(stored);
}

/** Seeds every key the form defines, so `form.data()` sends the whole body. */
function seed(): void {
    const patient = props.patient ?? null;
    const values: Answers = {};

    for (const section of sections.value) {
        for (const field of section.fields) {
            values[field.key] = controlValue(
                field,
                patient ? storedAnswer(field, patient) : undefined,
            );
        }
    }

    form.defaults(values).reset().clearErrors();
}

// Re-seed when the dialog opens, and again once the definition arrives.
watch([open, definition], ([isOpen]) => {
    if (isOpen) {
        seed();
    }
});

async function submit(): Promise<void> {
    const options = {
        onSuccess: (response: Wrapped<Patient>) => {
            emit('saved', response.data);
            open.value = false;
        },
    };

    if (props.patient) {
        await form.patch(
            update.url({ clinic: clinicId.value, patient: props.patient.id }),
            options,
        );
    } else {
        await form.post(store.url(clinicId.value), options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{
                    patient ? 'Edit patient' : 'New patient'
                }}</DialogTitle>
                <DialogDescription>
                    These are the questions on your clinic's patient form.
                    Fields marked * must be answered.
                </DialogDescription>
            </DialogHeader>

            <div v-if="loading && !definition" class="grid gap-4">
                <Skeleton v-for="row in 6" :key="row" class="h-14" />
            </div>

            <Alert v-else-if="error" variant="destructive">
                <AlertDescription>
                    {{ error.firstMessage }} The patient form could not be
                    loaded, so there is nothing to fill in yet.
                </AlertDescription>
            </Alert>

            <form v-else id="patient-form" @submit.prevent="submit">
                <DynamicPatientForm
                    :model-value="answers"
                    :sections="sections"
                    :errors="errors"
                    :disabled="form.processing"
                />
            </form>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    type="submit"
                    form="patient-form"
                    :disabled="form.processing || sections.length === 0"
                >
                    <Spinner v-if="form.processing" />
                    {{ patient ? 'Save changes' : 'Add patient' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
