<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import DateTimePicker from '@/components/form/DateTimePicker.vue';
import FormField from '@/components/FormField.vue';
import PatientPicker from '@/components/PatientPicker.vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useClinic } from '@/composables/useClinic';
import { fromDateTimeLocal, toDateTimeLocal } from '@/lib/format';
import { store, update } from '@/routes/clinic/sessions';
import type { ClinicMember, ClinicSession, Patient, Wrapped } from '@/types';

type Props = {
    members: ClinicMember[];
    /** Editing an existing session (patients are managed in the session sheet). */
    session?: ClinicSession | null;
    /** Pre-filled time range when creating from a calendar selection. */
    range?: { start: Date; end: Date } | null;
};

const props = defineProps<Props>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [session: ClinicSession] }>();

const { clinicId, context } = useClinic();
const patients = ref<Patient[]>([]);

type SessionForm = {
    name: string;
    starts_at: string;
    ends_at: string;
    clinic_user_id: string;
    patient_ids: number[];
};

const form = useHttp<SessionForm, Wrapped<ClinicSession>>({
    name: '',
    starts_at: '',
    ends_at: '',
    clinic_user_id: '',
    patient_ids: [],
});

form.transform((data) => {
    const payload: Record<string, unknown> = {
        name: data.name || null,
        starts_at: data.starts_at ? fromDateTimeLocal(data.starts_at) : '',
        ends_at: data.ends_at ? fromDateTimeLocal(data.ends_at) : '',
        clinic_user_id: data.clinic_user_id ? Number(data.clinic_user_id) : '',
    };

    if (!props.session) {
        payload.patient_ids = patients.value.map((patient) => patient.id);
    }

    return payload as SessionForm;
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const start = props.session
        ? new Date(props.session.starts_at)
        : (props.range?.start ?? nextHour());
    const end = props.session
        ? new Date(props.session.ends_at)
        : (props.range?.end ?? new Date(start.getTime() + 60 * 60 * 1000));
    const ownMembership = props.members.find(
        (member) => member.id === context.value.member.id,
    );

    patients.value = [];
    form.defaults({
        name: props.session?.name ?? '',
        starts_at: toDateTimeLocal(start),
        ends_at: toDateTimeLocal(end),
        clinic_user_id: String(
            props.session?.clinic_user?.id ??
                ownMembership?.id ??
                props.members[0]?.id ??
                '',
        ),
        patient_ids: [],
    })
        .reset()
        .clearErrors();
});

// Moving the start keeps the session's length, like a calendar app. Only when
// the start alone changed (not when the form is reset with both values).
watch(
    () => [form.starts_at, form.ends_at] as const,
    ([start, end], [previousStart, previousEnd]) => {
        if (
            !start ||
            !previousStart ||
            !end ||
            start === previousStart ||
            end !== previousEnd
        ) {
            return;
        }

        const length =
            new Date(end).getTime() - new Date(previousStart).getTime();

        if (length > 0) {
            form.ends_at = toDateTimeLocal(
                new Date(new Date(start).getTime() + length),
            );
        }
    },
);

function nextHour(): Date {
    const date = new Date();
    date.setMinutes(0, 0, 0);
    date.setHours(date.getHours() + 1);

    return date;
}

async function submit(): Promise<void> {
    const options = {
        onSuccess: (response: Wrapped<ClinicSession>) => {
            emit('saved', response.data);
            open.value = false;
        },
    };

    if (props.session) {
        await form.patch(
            update.url({
                clinic: clinicId.value,
                clinicSession: props.session.id,
            }),
            options,
        );
    } else {
        await form.post(store.url(clinicId.value), options);
    }
}

const patientError = (errors: Record<string, string | undefined>) =>
    errors.patient_ids ??
    Object.entries(errors).find(([key]) => key.startsWith('patient_ids.'))?.[1];
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    session ? 'Edit session' : 'New session'
                }}</DialogTitle>
                <DialogDescription>
                    Times are shown in your browser's timezone. A staff member
                    cannot hold two sessions at once.
                </DialogDescription>
            </DialogHeader>

            <form id="session-form" class="grid gap-4" @submit.prevent="submit">
                <FormField
                    label="Name (optional)"
                    for="session-name"
                    :error="form.errors.name"
                    hint="Leave empty to show the patients' names."
                >
                    <Input
                        id="session-name"
                        v-model="form.name"
                        placeholder="e.g. Group therapy"
                    />
                </FormField>

                <div class="grid gap-4">
                    <FormField
                        label="Starts"
                        for="starts_at"
                        :error="form.errors.starts_at"
                    >
                        <DateTimePicker
                            id="starts_at"
                            v-model="form.starts_at"
                        />
                    </FormField>
                    <FormField
                        label="Ends"
                        for="ends_at"
                        :error="form.errors.ends_at"
                    >
                        <DateTimePicker id="ends_at" v-model="form.ends_at" />
                    </FormField>
                </div>

                <FormField
                    label="Held by"
                    for="clinic_user_id"
                    :error="form.errors.clinic_user_id"
                >
                    <Select v-model="form.clinic_user_id">
                        <SelectTrigger id="clinic_user_id" class="w-full">
                            <SelectValue placeholder="Choose a staff member" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="member in members"
                                :key="member.id"
                                :value="String(member.id)"
                            >
                                {{ member.user?.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>

                <FormField
                    v-if="!session"
                    label="Patients"
                    :error="
                        patientError(
                            form.errors as Record<string, string | undefined>,
                        )
                    "
                >
                    <PatientPicker v-model="patients" />
                </FormField>
            </form>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    type="submit"
                    form="session-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    {{ session ? 'Save changes' : 'Book session' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
