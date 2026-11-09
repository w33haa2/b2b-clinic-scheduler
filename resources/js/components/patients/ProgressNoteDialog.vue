<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import DatePicker from '@/components/form/DatePicker.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useClinic } from '@/composables/useClinic';
import { clinicParts } from '@/lib/clinicTime';
import { progressRatingLabels } from '@/lib/format';
import { store, update } from '@/routes/clinic/patients/progress-notes';
import type { PatientProgressNote, ProgressRating, Wrapped } from '@/types';

const props = withDefaults(
    defineProps<{
        note?: PatientProgressNote | null;
        patientId: number;
        /** The patient has no notes yet, so this one sets the baseline. */
        isFirstNote?: boolean;
    }>(),
    { note: null, isFirstNote: false },
);
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [note: PatientProgressNote] }>();

const { clinicId, context } = useClinic();

/** Ordered as a clinician judges a visit, not alphabetically. */
const ratings: ProgressRating[] = [
    'BASELINE',
    'IMPROVED',
    'UNCHANGED',
    'DECLINED',
    'RESOLVED',
];

const ratingHints: Record<ProgressRating, string> = {
    BASELINE: 'Where the patient started. Nothing to compare with yet.',
    IMPROVED: 'Better than the note before this one.',
    UNCHANGED: 'No real change since the note before this one.',
    DECLINED: 'Worse than the note before this one.',
    RESOLVED: 'The problem the patient came in with is gone.',
};

/** A clinic may track at most 20 measurements on one note. */
const maxMetrics = 20;

type MetricRow = { name: string; value: string; unit: string };

/**
 * The rating is held as `rating`: a form object already owns `progress`
 * (the upload progress), so a field of that name would be swallowed. It is
 * renamed to `progress` on the way out, and the API reports its errors
 * under that name too.
 */
type NoteForm = {
    recorded_on: string;
    rating: ProgressRating;
    summary: string;
    body: string;
    plan: string;
    metrics: MetricRow[];
};

const form = useHttp<NoteForm, Wrapped<PatientProgressNote>>({
    recorded_on: '',
    rating: 'UNCHANGED',
    summary: '',
    body: '',
    plan: '',
    metrics: [],
});

// Rows go out exactly as they are shown, blanks included, so a
// `metrics.0.name` error lands on the row that caused it.
form.transform((data) => ({
    recorded_on: data.recorded_on,
    progress: data.rating,
    summary: data.summary,
    body: data.body || null,
    plan: data.plan || null,
    metrics: data.metrics.map((metric) => ({
        name: metric.name,
        value: metric.value === '' ? '' : Number(metric.value),
        unit: metric.unit || null,
    })),
}));

const ratingError = computed<string | undefined>(
    () => (form.errors as Record<string, string | undefined>).progress,
);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const note = props.note;
    const today = clinicParts(new Date(), context.value.clinic.timezone).date;

    form.defaults({
        recorded_on: note?.recorded_on ?? today,
        rating:
            note?.progress ?? (props.isFirstNote ? 'BASELINE' : 'UNCHANGED'),
        summary: note?.summary ?? '',
        body: note?.body ?? '',
        plan: note?.plan ?? '',
        metrics: (note?.metrics ?? []).map((metric) => ({
            name: metric.name,
            value: String(Number(metric.value)),
            unit: metric.unit ?? '',
        })),
    })
        .reset()
        .clearErrors();
});

function addMetric(): void {
    if (form.metrics.length >= maxMetrics) {
        return;
    }

    form.metrics = [...form.metrics, { name: '', value: '', unit: '' }];
}

function removeMetric(index: number): void {
    form.metrics = form.metrics.filter((_, row) => row !== index);
}

/** The first complaint the API has about a measurement row. */
function metricError(index: number): string | undefined {
    return (
        form.errors[`metrics.${index}.name`] ??
        form.errors[`metrics.${index}.value`] ??
        form.errors[`metrics.${index}.unit`]
    );
}

async function submit(): Promise<void> {
    const options = {
        onSuccess: (response: Wrapped<PatientProgressNote>) => {
            emit('saved', response.data);
            open.value = false;
        },
    };

    if (props.note) {
        await form.patch(
            update.url({
                clinic: clinicId.value,
                patient: props.patientId,
                progressNote: props.note.id,
            }),
            options,
        );
    } else {
        await form.post(
            store.url({ clinic: clinicId.value, patient: props.patientId }),
            options,
        );
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{
                    note ? 'Edit progress note' : 'New progress note'
                }}</DialogTitle>
                <DialogDescription>
                    Rate the change since the last note, not the patient's
                    overall state. Measurements are optional and are charted
                    over time.
                </DialogDescription>
            </DialogHeader>

            <form
                id="progress-note-form"
                class="grid gap-4 sm:grid-cols-2"
                @submit.prevent="submit"
            >
                <FormField
                    label="Date"
                    for="recorded_on"
                    :error="form.errors.recorded_on"
                    hint="The day the patient was seen."
                >
                    <DatePicker id="recorded_on" v-model="form.recorded_on" />
                </FormField>

                <FormField
                    label="Progress"
                    for="progress"
                    :error="ratingError"
                    :hint="ratingHints[form.rating]"
                >
                    <Select v-model="form.rating">
                        <SelectTrigger id="progress" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="rating in ratings"
                                :key="rating"
                                :value="rating"
                            >
                                {{ progressRatingLabels[rating] }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>

                <FormField
                    label="Summary"
                    for="summary"
                    :error="form.errors.summary"
                    hint="One line a colleague can scan later."
                    class="sm:col-span-2"
                >
                    <Input
                        id="summary"
                        v-model="form.summary"
                        v-focus
                        maxlength="255"
                        required
                        placeholder="e.g. Walking without the cane for short distances"
                    />
                </FormField>

                <FormField
                    label="Notes"
                    for="body"
                    :error="form.errors.body"
                    hint="What you observed."
                    class="sm:col-span-2"
                >
                    <Textarea
                        id="body"
                        v-model="form.body"
                        rows="3"
                        maxlength="10000"
                    />
                </FormField>

                <FormField
                    label="Plan"
                    for="plan"
                    :error="form.errors.plan"
                    hint="What happens before the next visit."
                    class="sm:col-span-2"
                >
                    <Textarea
                        id="plan"
                        v-model="form.plan"
                        rows="2"
                        maxlength="5000"
                    />
                </FormField>

                <!-- Measurements: the numbers behind the rating. -->
                <div class="grid gap-2 sm:col-span-2">
                    <div class="flex items-center justify-between gap-2">
                        <Label>Measurements</Label>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="form.metrics.length >= maxMetrics"
                            @click="addMetric"
                        >
                            <Plus />
                            Add measurement
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Anything you can put a number on — Pain 6 /10, Knee
                        flexion 95 deg. Keep a name spelled the same way and it
                        is charted over time.
                    </p>

                    <div
                        v-for="(metric, index) in form.metrics"
                        :key="index"
                        class="grid gap-1"
                    >
                        <div class="flex items-center gap-2">
                            <Input
                                v-model="metric.name"
                                class="flex-1"
                                maxlength="100"
                                placeholder="Measurement"
                                :aria-label="`Measurement ${index + 1} name`"
                            />
                            <Input
                                v-model="metric.value"
                                type="number"
                                step="0.01"
                                class="w-24"
                                placeholder="Value"
                                :aria-label="`Measurement ${index + 1} value`"
                            />
                            <Input
                                v-model="metric.unit"
                                class="w-20"
                                maxlength="20"
                                placeholder="Unit"
                                :aria-label="`Measurement ${index + 1} unit`"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="shrink-0 text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove measurement ${index + 1}`"
                                @click="removeMetric(index)"
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        <InputError :message="metricError(index)" />
                    </div>

                    <InputError :message="form.errors.metrics" />
                </div>
            </form>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    type="submit"
                    form="progress-note-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    {{ note ? 'Save changes' : 'Add note' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
