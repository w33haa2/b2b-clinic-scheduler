<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DatePicker from '@/components/form/DatePicker.vue';
import FormField from '@/components/FormField.vue';
import PatientPicker from '@/components/PatientPicker.vue';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import {
    formatMoney,
    patientName,
    sumMoney,
    toDateTimeLocal,
} from '@/lib/format';
import { store } from '@/routes/clinic/invoices';
import { index as uninvoicedItems } from '@/routes/clinic/patients/uninvoiced-items';
import type { Invoice, Patient, SessionLineItem, Wrapped } from '@/types';

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [invoice: Invoice] }>();

const { clinicId } = useClinic();
const patient = ref<Patient | null>(null);

const { data: items, loading } = useApiQuery<Wrapped<SessionLineItem[]>>(() =>
    patient.value
        ? uninvoicedItems.url({
              clinic: clinicId.value,
              patient: patient.value.id,
          })
        : null,
);

const form = useHttp<
    {
        patient_id: number | null;
        issued_at: string;
        notes: string;
        session_line_item_ids: number[];
    },
    Wrapped<Invoice>
>({
    patient_id: null,
    issued_at: '',
    notes: '',
    session_line_item_ids: [],
});

const selectedTotal = computed(() =>
    sumMoney(
        (items.value?.data ?? [])
            .filter((item) => form.session_line_item_ids.includes(item.id))
            .map((item) => item.amount),
    ),
);

watch(open, (isOpen) => {
    if (isOpen) {
        patient.value = null;
        form.defaults({
            patient_id: null,
            issued_at: toDateTimeLocal(new Date()).slice(0, 10),
            notes: '',
            session_line_item_ids: [],
        })
            .reset()
            .clearErrors();
    }
});

watch(items, (loaded) => {
    // Select every uninvoiced item by default.
    form.session_line_item_ids = loaded?.data.map((item) => item.id) ?? [];
});

function pickPatient(picked: Patient): void {
    patient.value = picked;
    form.patient_id = picked.id;
}

function toggle(itemId: number, checked: boolean | 'indeterminate'): void {
    form.session_line_item_ids =
        checked === true
            ? [...form.session_line_item_ids, itemId]
            : form.session_line_item_ids.filter((id) => id !== itemId);
}

async function submit(): Promise<void> {
    await form.post(store.url(clinicId.value), {
        onSuccess: (response: Wrapped<Invoice>) => {
            emit('saved', response.data);
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>New invoice</DialogTitle>
                <DialogDescription
                    >Pick a patient, then the paid services to include. Unpaid
                    services appear here once they are marked
                    paid.</DialogDescription
                >
            </DialogHeader>

            <form id="invoice-form" class="grid gap-4" @submit.prevent="submit">
                <FormField label="Patient" :error="form.errors.patient_id">
                    <div
                        v-if="patient"
                        class="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                    >
                        <span class="font-medium">{{
                            patientName(patient)
                        }}</span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="patient = null"
                            >Change</Button
                        >
                    </div>
                    <PatientPicker
                        v-else
                        single
                        placeholder="Choose a patient"
                        @pick="pickPatient"
                    />
                </FormField>

                <FormField
                    v-if="patient"
                    label="Paid services to invoice"
                    :error="form.errors.session_line_item_ids"
                >
                    <div class="max-h-56 overflow-y-auto rounded-md border">
                        <template v-if="loading">
                            <Skeleton
                                v-for="row in 3"
                                :key="row"
                                class="m-2 h-6"
                            />
                        </template>
                        <p
                            v-else-if="!items?.data.length"
                            class="p-4 text-center text-sm text-muted-foreground"
                        >
                            Nothing paid is left to invoice for this patient.
                        </p>
                        <label
                            v-for="item in items?.data ?? []"
                            :key="item.id"
                            class="flex cursor-pointer items-center gap-3 border-b px-3 py-2 text-sm last:border-b-0 hover:bg-accent/50"
                        >
                            <Checkbox
                                :model-value="
                                    form.session_line_item_ids.includes(item.id)
                                "
                                @update:model-value="
                                    (checked) => toggle(item.id, checked)
                                "
                            />
                            <span class="flex-1">
                                {{ item.line_item?.name }}
                                <span class="text-muted-foreground"
                                    >× {{ item.quantity }}</span
                                >
                            </span>
                            <span class="tabular-nums">{{
                                formatMoney(item.amount)
                            }}</span>
                        </label>
                    </div>
                    <p v-if="items?.data.length" class="text-right text-sm">
                        Total
                        <span class="font-semibold tabular-nums">{{
                            formatMoney(selectedTotal)
                        }}</span>
                    </p>
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Issue date"
                        for="issued_at"
                        :error="form.errors.issued_at"
                    >
                        <DatePicker id="issued_at" v-model="form.issued_at" />
                    </FormField>
                </div>
                <FormField
                    label="Notes"
                    for="invoice_notes"
                    :error="form.errors.notes"
                >
                    <Textarea
                        id="invoice_notes"
                        v-model="form.notes"
                        rows="2"
                    />
                </FormField>
            </form>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    type="submit"
                    form="invoice-form"
                    :disabled="
                        form.processing ||
                        !patient ||
                        form.session_line_item_ids.length === 0
                    "
                >
                    <Spinner v-if="form.processing" />
                    Create invoice
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
