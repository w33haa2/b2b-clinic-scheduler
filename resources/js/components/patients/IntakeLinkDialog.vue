<script setup lang="ts">
import { Copy, Link2, RotateCw, TriangleAlert, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
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
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError } from '@/lib/api';
import { formatDate, patientName } from '@/lib/format';
import { cn } from '@/lib/utils';
import * as intakeLinks from '@/routes/clinic/patient-form-invitations';
import type {
    Paginated,
    Patient,
    PatientFormInvitation,
    Wrapped,
} from '@/types';

/**
 * Hand the patient form to the patient. The link lets them fill it in
 * themselves without an account; the clinic sends it on however it normally
 * reaches them. Only a hash of the token is stored, so the link exists in
 * exactly one response and then only in the message you sent.
 */
const props = defineProps<{ patient?: Patient | null }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();

const { clinicId, can } = useClinic();

const name = ref('');
const email = ref('');
const addressToPatient = ref(true);
const errors = ref<Record<string, string[]>>({});
const issuing = ref(false);
const busyId = ref<number | null>(null);
const cancelling = ref<PatientFormInvitation | null>(null);

/** The plain link, from the one response that carries it. */
const issuedUrl = ref<string | null>(null);
const issuedFor = ref('');

const canList = can('clinic.patient-form-invitations.index');

const {
    data,
    loading,
    refresh: refreshList,
} = useApiQuery<Paginated<PatientFormInvitation>>(() =>
    open.value && canList
        ? intakeLinks.index.url(clinicId.value, {
              query: { patient_id: props.patient?.id, per_page: 10 },
          })
        : null,
);

/** Guards the moment between opening for a patient and their links arriving. */
const invitations = computed(() => {
    const rows = data.value?.data ?? [];
    const patientId = props.patient?.id;

    return patientId
        ? rows.filter((row) => row.patient_id === patientId)
        : rows;
});

const statusTone: Record<PatientFormInvitation['status'], string> = {
    pending:
        'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    submitted:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    expired: 'bg-muted text-muted-foreground',
    revoked: 'bg-muted text-muted-foreground line-through',
};

const statusLabel: Record<PatientFormInvitation['status'], string> = {
    pending: 'Waiting',
    submitted: 'Filled in',
    expired: 'Expired',
    revoked: 'Cancelled',
};

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    name.value = '';
    email.value = '';
    addressToPatient.value = true;
    errors.value = {};
    issuedUrl.value = null;
    issuedFor.value = '';
});

/** Who a link was meant for, for the list and the revealed-link caption. */
function recipient(invitation: PatientFormInvitation): string {
    if (invitation.patient) {
        return patientName(invitation.patient);
    }

    return invitation.name || invitation.email || 'Someone new';
}

function reveal(invitation: PatientFormInvitation): void {
    issuedUrl.value = invitation.url ?? null;
    issuedFor.value = recipient(invitation);
}

async function issue(): Promise<void> {
    issuing.value = true;
    errors.value = {};

    try {
        const response = await api.post<Wrapped<PatientFormInvitation>>(
            intakeLinks.store.url(clinicId.value),
            {
                patient_id: props.patient?.id ?? null,
                name: props.patient ? null : name.value || null,
                email:
                    (props.patient
                        ? addressToPatient.value
                            ? props.patient.email
                            : null
                        : email.value) || null,
            },
        );

        reveal(response.data);
        toast.success('Link ready — copy it now.');
        emit('saved');
        await refreshList();
    } catch (caught) {
        if (caught instanceof ApiError) {
            errors.value = caught.errors;
            toast.error(caught.firstMessage);
        }
    } finally {
        issuing.value = false;
    }
}

async function resend(invitation: PatientFormInvitation): Promise<void> {
    busyId.value = invitation.id;

    try {
        const response = await api.post<Wrapped<PatientFormInvitation>>(
            intakeLinks.resend.url({
                clinic: clinicId.value,
                patientFormInvitation: invitation.id,
            }),
        );

        reveal(response.data);
        toast.success('A new link is ready. The old one no longer works.');
        emit('saved');
        await refreshList();
    } catch (caught) {
        toast.error(
            caught instanceof ApiError
                ? caught.firstMessage
                : 'Could not make a new link.',
        );
    } finally {
        busyId.value = null;
    }
}

async function cancel(): Promise<void> {
    const invitation = cancelling.value;

    if (!invitation) {
        return;
    }

    busyId.value = invitation.id;

    try {
        await api.delete(
            intakeLinks.destroy.url({
                clinic: clinicId.value,
                patientFormInvitation: invitation.id,
            }),
        );
        toast.success(`The link for ${recipient(invitation)} was cancelled.`);
        cancelling.value = null;
        emit('saved');
        await refreshList();
    } catch (caught) {
        toast.error(
            caught instanceof ApiError
                ? caught.firstMessage
                : 'Could not cancel the link.',
        );
    } finally {
        busyId.value = null;
    }
}

async function copyLink(): Promise<void> {
    if (!issuedUrl.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(issuedUrl.value);
        toast.success('Link copied');
    } catch {
        toast.error('Could not copy. Select the link and copy it by hand.');
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Link2 class="size-5 text-teal-600" />
                    Send an intake link
                </DialogTitle>
                <DialogDescription>
                    Send this link to the patient and they fill the form in
                    themselves — no account, no app. It works once and expires
                    after 14 days.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="issuedUrl"
                class="animate__animated animate__fadeIn space-y-2 rounded-md border border-teal-200 bg-teal-50/60 p-3 dark:border-teal-500/30 dark:bg-teal-500/10"
                style="--animate-duration: 0.35s"
            >
                <div class="text-sm font-medium">Link for {{ issuedFor }}</div>
                <div class="flex gap-2">
                    <Input
                        :model-value="issuedUrl"
                        readonly
                        aria-label="Intake link"
                        class="bg-background font-mono text-xs"
                        @focus="
                            (event: FocusEvent) =>
                                (event.target as HTMLInputElement).select()
                        "
                    />
                    <Button variant="outline" @click="copyLink">
                        <Copy />
                        Copy link
                    </Button>
                </div>
                <p
                    class="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400"
                >
                    <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                    Copy this now — it is shown once and cannot be recovered.
                </p>
            </div>

            <form
                id="intake-link-form"
                class="grid gap-4"
                @submit.prevent="issue"
            >
                <template v-if="patient">
                    <p class="text-sm">
                        Updating
                        <span class="font-medium"
                            >{{ patientName(patient) }}’s</span
                        >
                        record. Whatever they fill in replaces what is on file.
                    </p>
                    <div v-if="patient.email" class="grid gap-1.5">
                        <label
                            class="flex cursor-pointer items-center gap-3 text-sm"
                        >
                            <Checkbox
                                :model-value="addressToPatient"
                                @update:model-value="
                                    (value) =>
                                        (addressToPatient = value === true)
                                "
                            />
                            Address it to {{ patient.email }}
                        </label>
                        <p class="pl-7 text-xs text-muted-foreground">
                            Kept on the link so you can see where it went. You
                            still send the link yourself.
                        </p>
                    </div>
                    <p v-else class="text-xs text-muted-foreground">
                        No email on their record, so the link is unlabelled.
                        Copy it below and send it however you reach them.
                    </p>
                </template>

                <template v-else>
                    <p class="text-sm text-muted-foreground">
                        A new record is created from whatever they fill in. Both
                        fields are optional and only help you tell your links
                        apart — they do not identify anyone.
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Name"
                            for="intake-name"
                            :error="errors.name?.[0]"
                        >
                            <Input
                                id="intake-name"
                                v-model="name"
                                v-focus
                                autocomplete="off"
                                placeholder="Who is it for?"
                            />
                        </FormField>
                        <FormField
                            label="Email"
                            for="intake-email"
                            :error="errors.email?.[0]"
                        >
                            <Input
                                id="intake-email"
                                v-model="email"
                                type="email"
                                autocomplete="off"
                                placeholder="name@example.com"
                            />
                        </FormField>
                    </div>
                </template>
            </form>

            <template v-if="canList">
                <Separator />

                <div class="space-y-2">
                    <h3 class="text-sm font-semibold">
                        {{
                            patient ? 'Links for this patient' : 'Recent links'
                        }}
                    </h3>

                    <div v-if="loading && !data" class="space-y-2">
                        <Skeleton v-for="row in 3" :key="row" class="h-10" />
                    </div>

                    <p
                        v-else-if="!invitations.length"
                        class="rounded-md border border-dashed px-3 py-6 text-center text-sm text-muted-foreground"
                    >
                        No links yet.
                    </p>

                    <ul v-else class="max-h-56 divide-y overflow-y-auto">
                        <li
                            v-for="invitation in invitations"
                            :key="invitation.id"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm"
                        >
                            <span class="min-w-0 flex-1 truncate font-medium">{{
                                recipient(invitation)
                            }}</span>
                            <span class="text-xs text-muted-foreground">
                                <template v-if="invitation.status === 'pending'"
                                    >until
                                    {{
                                        formatDate(invitation.expires_at)
                                    }}</template
                                >
                                <template
                                    v-else-if="
                                        invitation.status === 'submitted'
                                    "
                                    >filled in
                                    {{
                                        formatDate(invitation.submitted_at)
                                    }}</template
                                >
                                <template
                                    v-else-if="invitation.status === 'revoked'"
                                    >cancelled
                                    {{
                                        formatDate(invitation.revoked_at)
                                    }}</template
                                >
                                <template v-else
                                    >expired
                                    {{
                                        formatDate(invitation.expires_at)
                                    }}</template
                                >
                            </span>
                            <Badge
                                variant="secondary"
                                :class="
                                    cn(
                                        'border-transparent',
                                        statusTone[invitation.status],
                                    )
                                "
                                >{{ statusLabel[invitation.status] }}</Badge
                            >
                            <div class="flex gap-1">
                                <Button
                                    v-if="
                                        invitation.status !== 'submitted' &&
                                        can(
                                            'clinic.patient-form-invitations.resend',
                                        )
                                    "
                                    size="icon"
                                    variant="ghost"
                                    class="size-7"
                                    :aria-label="`Make a new link for ${recipient(invitation)}`"
                                    :disabled="busyId !== null"
                                    @click="resend(invitation)"
                                >
                                    <Spinner v-if="busyId === invitation.id" />
                                    <RotateCw v-else />
                                </Button>
                                <Button
                                    v-if="
                                        invitation.status === 'pending' &&
                                        can(
                                            'clinic.patient-form-invitations.destroy',
                                        )
                                    "
                                    size="icon"
                                    variant="ghost"
                                    class="size-7"
                                    :aria-label="`Cancel the link for ${recipient(invitation)}`"
                                    :disabled="busyId !== null"
                                    @click="cancelling = invitation"
                                >
                                    <X />
                                </Button>
                            </div>
                        </li>
                    </ul>
                </div>
            </template>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Close</Button>
                <Button
                    type="submit"
                    form="intake-link-form"
                    :disabled="issuing"
                >
                    <Spinner v-if="issuing" />
                    <Link2 v-else />
                    {{ issuedUrl ? 'Create another link' : 'Create link' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        :open="cancelling !== null"
        title="Cancel this intake link?"
        :description="`The link sent to ${cancelling ? recipient(cancelling) : ''} will stop working. You can make a new one at any time.`"
        confirm-label="Cancel link"
        :processing="busyId !== null"
        @update:open="(value) => !value && (cancelling = null)"
        @confirm="cancel"
    />
</template>
