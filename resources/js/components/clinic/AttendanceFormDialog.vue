<script setup lang="ts">
import { useHttp, usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, watch } from 'vue';
import DatePicker from '@/components/form/DatePicker.vue';
import DateTimePicker from '@/components/form/DateTimePicker.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import {
    attendanceStatusLabels,
    formatDate,
    fromDateTimeLocal,
    toDateTimeLocal,
} from '@/lib/format';
import { store, update } from '@/routes/clinic/attendance';
import type {
    Attendance,
    AttendanceStatus,
    ClinicMember,
    Wrapped,
} from '@/types';

const props = defineProps<{
    attendance?: Attendance | null;
    members: ClinicMember[];
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [attendance: Attendance] }>();

const { clinicId, context, isAdmin } = useClinic();
const page = usePage();

/** Shown in the order a day is usually judged in, not alphabetically. */
const statuses: AttendanceStatus[] = ['PRESENT', 'LATE', 'ABSENT', 'EXCUSED'];

const myMemberId = computed(() => context.value.member.id);

/** Only a Clinic Admin recording a new day may choose whose day it is. */
const canPickMember = computed(() => !props.attendance && isAdmin.value);

/** The caller's own name, even when they may not list the clinic's members. */
const myName = computed(
    () =>
        props.members.find((member) => member.id === myMemberId.value)?.user
            ?.name ??
        page.props.auth.user?.name ??
        'You',
);

type AttendanceForm = {
    clinic_user_id: string;
    work_date: string;
    status: AttendanceStatus;
    /** datetime-local, in the viewer's timezone. */
    checked_in_at: string;
    checked_out_at: string;
    notes: string;
};

const form = useHttp<AttendanceForm, Wrapped<Attendance>>({
    clinic_user_id: '',
    work_date: '',
    status: 'PRESENT',
    checked_in_at: '',
    checked_out_at: '',
    notes: '',
});

// Times go out as instants. The member and the day are fixed once the row
// exists, so an edit never sends them.
form.transform((data) => {
    const payload: Record<string, unknown> = {
        status: data.status,
        checked_in_at: data.checked_in_at
            ? fromDateTimeLocal(data.checked_in_at)
            : null,
        checked_out_at: data.checked_out_at
            ? fromDateTimeLocal(data.checked_out_at)
            : null,
        notes: data.notes || null,
    };

    if (!props.attendance) {
        payload.clinic_user_id = data.clinic_user_id
            ? Number(data.clinic_user_id)
            : '';
        payload.work_date = data.work_date;
    }

    return payload;
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const record = props.attendance;
    const today = clinicParts(new Date(), context.value.clinic.timezone).date;

    form.defaults({
        clinic_user_id: String(
            record?.member_id ?? (isAdmin.value ? '' : myMemberId.value),
        ),
        work_date: record?.work_date ?? today,
        status: record?.status ?? 'PRESENT',
        checked_in_at: record?.checked_in_at
            ? toDateTimeLocal(record.checked_in_at)
            : '',
        checked_out_at: record?.checked_out_at
            ? toDateTimeLocal(record.checked_out_at)
            : '',
        notes: record?.notes ?? '',
    })
        .reset()
        .clearErrors();
});

async function submit(): Promise<void> {
    const options = {
        onSuccess: (response: Wrapped<Attendance>) => {
            emit('saved', response.data);
            open.value = false;
        },
    };

    if (props.attendance) {
        await form.patch(
            update.url({
                clinic: clinicId.value,
                attendance: props.attendance.id,
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
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    attendance ? 'Edit attendance' : 'Record attendance'
                }}</DialogTitle>
                <DialogDescription>
                    One record per staff member per working day. Present and
                    Late count as worked; Absent and Excused do not.
                </DialogDescription>
            </DialogHeader>

            <form
                id="attendance-form"
                class="grid gap-4 sm:grid-cols-2"
                @submit.prevent="submit"
            >
                <FormField
                    label="Staff member"
                    :for="canPickMember ? 'clinic_user_id' : undefined"
                    :error="form.errors.clinic_user_id"
                    :hint="
                        !attendance && !isAdmin
                            ? 'You can only record your own attendance.'
                            : undefined
                    "
                >
                    <Select v-if="canPickMember" v-model="form.clinic_user_id">
                        <SelectTrigger id="clinic_user_id" class="w-full">
                            <SelectValue placeholder="Choose a staff member" />
                        </SelectTrigger>
                        <SelectContent class="max-h-72">
                            <SelectItem
                                v-for="member in members"
                                :key="member.id"
                                :value="String(member.id)"
                            >
                                {{ member.user?.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <div
                        v-else
                        class="rounded-md border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {{ attendance?.member?.user?.name ?? myName }}
                    </div>
                </FormField>

                <FormField
                    label="Date"
                    :for="attendance ? undefined : 'work_date'"
                    :error="form.errors.work_date"
                    :hint="
                        attendance
                            ? undefined
                            : 'The working day, in the clinic\'s timezone.'
                    "
                >
                    <div
                        v-if="attendance"
                        class="rounded-md border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {{ formatDate(attendance.work_date) }}
                    </div>
                    <DatePicker
                        v-else
                        id="work_date"
                        v-model="form.work_date"
                    />
                </FormField>

                <FormField
                    label="Status"
                    for="status"
                    :error="form.errors.status"
                    class="sm:col-span-2"
                >
                    <Select v-model="form.status">
                        <SelectTrigger id="status" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="value in statuses"
                                :key="value"
                                :value="value"
                            >
                                {{ attendanceStatusLabels[value] }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>

                <FormField
                    label="Check in"
                    for="checked_in_at"
                    :error="form.errors.checked_in_at"
                    hint="Optional. Shown in your browser's timezone."
                    class="sm:col-span-2"
                >
                    <div class="flex items-center gap-1">
                        <DateTimePicker
                            id="checked_in_at"
                            v-model="form.checked_in_at"
                            class="flex-1"
                        />
                        <Button
                            v-if="form.checked_in_at"
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Clear check in"
                            @click="form.checked_in_at = ''"
                        >
                            <X />
                        </Button>
                    </div>
                </FormField>

                <FormField
                    label="Check out"
                    for="checked_out_at"
                    :error="form.errors.checked_out_at"
                    hint="Optional. Leave empty while the day is still running."
                    class="sm:col-span-2"
                >
                    <div class="flex items-center gap-1">
                        <DateTimePicker
                            id="checked_out_at"
                            v-model="form.checked_out_at"
                            class="flex-1"
                        />
                        <Button
                            v-if="form.checked_out_at"
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Clear check out"
                            @click="form.checked_out_at = ''"
                        >
                            <X />
                        </Button>
                    </div>
                </FormField>

                <FormField
                    label="Notes"
                    for="notes"
                    :error="form.errors.notes"
                    class="sm:col-span-2"
                >
                    <Textarea
                        id="notes"
                        v-model="form.notes"
                        rows="3"
                        maxlength="500"
                        placeholder="e.g. Left early for a clinic errand"
                    />
                </FormField>
            </form>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    type="submit"
                    form="attendance-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    {{ attendance ? 'Save changes' : 'Record attendance' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
