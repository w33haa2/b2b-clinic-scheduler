<script setup lang="ts">
import {
    CalendarClock,
    CheckCircle2,
    CircleSlash,
    Pencil,
    Play,
    Plus,
    Trash2,
    Undo2,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PatientPicker from '@/components/PatientPicker.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useClinic } from '@/composables/useClinic';
import { useInitials } from '@/composables/useInitials';
import { api, ApiError } from '@/lib/api';
import {
    formatDateTime,
    formatMoney,
    formatTime,
    patientName,
    sumMoney,
} from '@/lib/format';
import * as sessionRoutes from '@/routes/clinic/sessions';
import * as memberRoutes from '@/routes/clinic/sessions/members';
import * as lineItemRoutes from '@/routes/clinic/sessions/members/line-items';
import * as paymentRoutes from '@/routes/clinic/sessions/members/payment';
import * as statusRoutes from '@/routes/clinic/sessions/status';
import type {
    ClinicSession,
    LineItem,
    Patient,
    PaymentStatus,
    SessionMember,
    SessionStatus,
    Wrapped,
} from '@/types';

type Props = {
    sessionId: number | null;
    /** The clinic's billable catalog, for adding items. */
    catalog: LineItem[];
};

const props = defineProps<Props>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ changed: []; edit: [session: ClinicSession] }>();

const { clinicId, can } = useClinic();
const { getInitials } = useInitials();

const session = ref<ClinicSession | null>(null);
const loading = ref(false);
const busy = ref<string | null>(null);
const deleteOpen = ref(false);
const refundTarget = ref<SessionMember | null>(null);
const refundOpen = ref(false);
const addingItemFor = ref<number | null>(null);
const newItem = ref<{ line_item_id: string; quantity: number }>({
    line_item_id: '',
    quantity: 1,
});

const isOpenSession = computed(
    () =>
        session.value?.status === 'PENDING' ||
        session.value?.status === 'ONGOING',
);
const sessionTitle = computed(
    () =>
        session.value?.name ||
        session.value?.members
            ?.map((member) => patientName(member.patient))
            .join(', ') ||
        'Session',
);
const total = computed(() =>
    sumMoney(
        (session.value?.members ?? [])
            .flatMap((m) => m.line_items ?? [])
            .map((i) => i.amount),
    ),
);

const transitions: Record<
    SessionStatus,
    Array<{
        to: SessionStatus;
        label: string;
        icon: typeof Play;
        variant: 'default' | 'outline';
    }>
> = {
    PENDING: [
        {
            to: 'ONGOING',
            label: 'Start session',
            icon: Play,
            variant: 'default',
        },
        {
            to: 'CANCELLED',
            label: 'Cancel',
            icon: CircleSlash,
            variant: 'outline',
        },
    ],
    ONGOING: [
        {
            to: 'DONE',
            label: 'Mark done',
            icon: CheckCircle2,
            variant: 'default',
        },
        {
            to: 'CANCELLED',
            label: 'Cancel',
            icon: CircleSlash,
            variant: 'outline',
        },
    ],
    DONE: [],
    CANCELLED: [],
};

watch([open, () => props.sessionId], ([isOpen]) => {
    if (isOpen && props.sessionId) {
        session.value = null;
        void load();
    }
});

async function load(): Promise<void> {
    if (!props.sessionId) {
        return;
    }

    loading.value = true;

    try {
        const response = await api.get<Wrapped<ClinicSession>>(
            sessionRoutes.show.url({
                clinic: clinicId.value,
                clinicSession: props.sessionId,
            }),
        );
        session.value = response.data;
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not load the session.',
        );
        open.value = false;
    } finally {
        loading.value = false;
    }
}

/** Run a change, show the API's reason if it is refused, then reload the sheet and the calendar. */
async function perform(
    key: string,
    action: () => Promise<unknown>,
    success: string,
): Promise<void> {
    busy.value = key;

    try {
        await action();
        toast.success(success);
        emit('changed');
        await load();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Something went wrong.',
        );
    } finally {
        busy.value = null;
    }
}

/** Route parameters for the open session, and for one of its patients. */
const ids = () => ({
    clinic: clinicId.value,
    clinicSession: session.value!.id,
});
const memberIds = (member: SessionMember) => ({
    ...ids(),
    sessionMember: member.id,
});

function changeStatus(status: SessionStatus): Promise<void> {
    return perform(
        `status-${status}`,
        () => api.put(statusRoutes.update.url(ids()), { status }),
        'Session updated.',
    );
}

function addPatient(patient: Patient): Promise<void> {
    return perform(
        'add-patient',
        () =>
            api.post(memberRoutes.store.url(ids()), { patient_id: patient.id }),
        `${patientName(patient)} added.`,
    );
}

/** Unpaid billed items are removed with the patient. */
function removePatient(member: SessionMember): Promise<void> {
    return perform(
        `remove-${member.id}`,
        () => api.delete(memberRoutes.destroy.url(memberIds(member))),
        member.line_items?.length
            ? `${patientName(member.patient)} and their unpaid items removed.`
            : `${patientName(member.patient)} removed.`,
    );
}

function setPayment(
    member: SessionMember,
    payment_status: Exclude<PaymentStatus, 'PENDING'>,
): Promise<void> {
    return perform(
        `pay-${member.id}`,
        () =>
            api.put(paymentRoutes.update.url(memberIds(member)), {
                payment_status,
            }),
        payment_status === 'DONE' ? 'Payment recorded.' : 'Refund recorded.',
    );
}

async function addItem(member: SessionMember): Promise<void> {
    if (newItem.value.line_item_id === '') {
        return;
    }

    await perform(
        `item-${member.id}`,
        () =>
            api.post(lineItemRoutes.store.url(memberIds(member)), {
                ...newItem.value,
                line_item_id: Number(newItem.value.line_item_id),
            }),
        'Item billed.',
    );
    addingItemFor.value = null;
    newItem.value = { line_item_id: '', quantity: 1 };
}

function removeItem(member: SessionMember, itemId: number): Promise<void> {
    return perform(
        `item-remove-${itemId}`,
        () =>
            api.delete(
                lineItemRoutes.destroy.url({
                    ...memberIds(member),
                    sessionLineItem: itemId,
                }),
            ),
        'Item removed.',
    );
}

async function deleteSession(): Promise<void> {
    busy.value = 'delete';

    try {
        await api.delete(sessionRoutes.destroy.url(ids()));
        toast.success('Session deleted.');
        deleteOpen.value = false;
        open.value = false;
        emit('changed');
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not delete the session.',
        );
    } finally {
        busy.value = null;
    }
}

function memberTotal(member: SessionMember): string {
    return sumMoney((member.line_items ?? []).map((item) => item.amount));
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 overflow-y-auto sm:max-w-xl">
            <SheetHeader class="border-b">
                <template v-if="session">
                    <div class="flex items-center gap-2">
                        <StatusBadge kind="session" :status="session.status" />
                    </div>
                    <SheetTitle class="text-xl">{{ sessionTitle }}</SheetTitle>
                    <SheetDescription class="flex items-center gap-1.5">
                        <CalendarClock class="size-4" />
                        {{ formatDateTime(session.starts_at) }} –
                        {{ formatTime(session.ends_at) }}
                    </SheetDescription>
                </template>
                <template v-else>
                    <SheetTitle><Skeleton class="h-6 w-48" /></SheetTitle>
                    <SheetDescription
                        ><Skeleton class="h-4 w-64"
                    /></SheetDescription>
                </template>
            </SheetHeader>

            <div v-if="loading && !session" class="space-y-3 p-4">
                <Skeleton v-for="row in 4" :key="row" class="h-16" />
            </div>

            <div
                v-else-if="session"
                class="animate__animated animate__fadeIn flex flex-col gap-6 p-4"
                style="--animate-duration: 0.3s"
            >
                <!-- Status and session actions -->
                <div class="flex flex-wrap gap-2">
                    <template v-if="can('clinic.sessions.status.update')">
                        <Button
                            v-for="transition in transitions[session.status]"
                            :key="transition.to"
                            size="sm"
                            :variant="transition.variant"
                            :disabled="busy !== null"
                            @click="changeStatus(transition.to)"
                        >
                            <Spinner
                                v-if="busy === `status-${transition.to}`"
                            />
                            <component :is="transition.icon" v-else />
                            {{ transition.label }}
                        </Button>
                    </template>
                    <Button
                        v-if="isOpenSession && can('clinic.sessions.update')"
                        size="sm"
                        variant="outline"
                        @click="emit('edit', session)"
                    >
                        <Pencil />
                        Edit
                    </Button>
                    <Button
                        v-if="can('clinic.sessions.destroy')"
                        size="sm"
                        variant="ghost"
                        class="text-destructive"
                        @click="deleteOpen = true"
                    >
                        <Trash2 />
                        Delete
                    </Button>
                </div>

                <!-- Assigned staff member -->
                <div
                    class="flex items-center gap-3 rounded-lg border bg-muted/40 p-3"
                >
                    <div
                        class="flex size-9 items-center justify-center rounded-full bg-teal-600 text-white"
                    >
                        <UserRound class="size-4" />
                    </div>
                    <div class="text-sm">
                        <div class="text-muted-foreground">Held by</div>
                        <div class="font-medium">
                            {{
                                session.clinic_user?.user?.name ??
                                'Former staff member'
                            }}
                        </div>
                    </div>
                </div>

                <!-- Patients, their bills and payments -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold">
                            Patients
                            <span class="text-muted-foreground"
                                >({{ session.members?.length ?? 0 }})</span
                            >
                        </h3>
                        <span
                            v-if="Number(total) > 0"
                            class="text-sm text-muted-foreground"
                            >Billed {{ formatMoney(total) }}</span
                        >
                    </div>

                    <p
                        v-if="!session.members?.length"
                        class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
                    >
                        No patients in this session yet.
                    </p>

                    <div
                        v-for="(member, position) in session.members"
                        :key="member.id"
                        class="animate__animated animate__fadeInUp rounded-lg border"
                        :style="{
                            animationDelay: `${position * 60}ms`,
                            '--animate-duration': '0.35s',
                        }"
                    >
                        <div class="flex items-center gap-3 p-3">
                            <Avatar class="size-8">
                                <AvatarFallback class="text-xs">{{
                                    getInitials(patientName(member.patient))
                                }}</AvatarFallback>
                            </Avatar>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">
                                    {{ patientName(member.patient) }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ member.patient?.phone }}
                                </div>
                            </div>
                            <StatusBadge
                                kind="payment"
                                :status="member.payment_status ?? 'PENDING'"
                                :past-due="
                                    session.status !== 'CANCELLED' &&
                                    new Date(session.ends_at) < new Date()
                                "
                            />
                            <Button
                                v-if="
                                    isOpenSession &&
                                    can('clinic.sessions.members.destroy') &&
                                    member.payment_status === 'PENDING'
                                "
                                variant="ghost"
                                size="icon"
                                class="size-7"
                                :aria-label="`Remove ${patientName(member.patient)}`"
                                :disabled="busy !== null"
                                @click="removePatient(member)"
                            >
                                <X />
                            </Button>
                        </div>

                        <Separator />

                        <div class="space-y-1 p-3 text-sm">
                            <div
                                v-for="item in member.line_items"
                                :key="item.id"
                                class="flex items-center gap-2"
                            >
                                <span class="flex-1 truncate">
                                    {{ item.line_item?.name }}
                                    <span class="text-muted-foreground"
                                        >× {{ item.quantity }}</span
                                    >
                                </span>
                                <span class="tabular-nums">{{
                                    formatMoney(item.amount)
                                }}</span>
                                <Button
                                    v-if="
                                        member.payment_status === 'PENDING' &&
                                        can(
                                            'clinic.sessions.members.line-items.destroy',
                                        )
                                    "
                                    variant="ghost"
                                    size="icon"
                                    class="size-6"
                                    aria-label="Remove item"
                                    :disabled="busy !== null"
                                    @click="removeItem(member, item.id)"
                                >
                                    <X class="size-3" />
                                </Button>
                            </div>
                            <p
                                v-if="!member.line_items?.length"
                                class="text-muted-foreground"
                            >
                                Nothing billed yet.
                            </p>

                            <form
                                v-if="addingItemFor === member.id"
                                class="flex items-center gap-2 pt-2"
                                @submit.prevent="addItem(member)"
                            >
                                <Select v-model="newItem.line_item_id">
                                    <SelectTrigger
                                        size="sm"
                                        class="min-w-0 flex-1"
                                        aria-label="Service"
                                    >
                                        <SelectValue
                                            placeholder="Choose a service"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="item in catalog"
                                            :key="item.id"
                                            :value="String(item.id)"
                                        >
                                            {{ item.name }} ·
                                            {{ formatMoney(item.price) }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Input
                                    v-model.number="newItem.quantity"
                                    type="number"
                                    min="1"
                                    class="h-8 w-16"
                                    aria-label="Quantity"
                                />
                                <Button
                                    size="sm"
                                    type="submit"
                                    :disabled="busy !== null"
                                    >Add</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    type="button"
                                    @click="addingItemFor = null"
                                    >Cancel</Button
                                >
                            </form>

                            <div class="flex flex-wrap items-center gap-2 pt-2">
                                <Button
                                    v-if="
                                        addingItemFor !== member.id &&
                                        member.payment_status === 'PENDING' &&
                                        session.status !== 'CANCELLED' &&
                                        can(
                                            'clinic.sessions.members.line-items.store',
                                        )
                                    "
                                    size="sm"
                                    variant="outline"
                                    @click="addingItemFor = member.id"
                                >
                                    <Plus />
                                    Bill a service
                                </Button>
                                <template
                                    v-if="
                                        can(
                                            'clinic.sessions.members.payment.update',
                                        )
                                    "
                                >
                                    <Button
                                        v-if="
                                            member.payment_status ===
                                                'PENDING' &&
                                            member.line_items?.length &&
                                            session.status !== 'CANCELLED'
                                        "
                                        size="sm"
                                        :disabled="busy !== null"
                                        @click="setPayment(member, 'DONE')"
                                    >
                                        <Spinner
                                            v-if="busy === `pay-${member.id}`"
                                        />
                                        <CheckCircle2 v-else />
                                        Mark paid ·
                                        {{ formatMoney(memberTotal(member)) }}
                                    </Button>
                                    <Button
                                        v-if="member.payment_status === 'DONE'"
                                        size="sm"
                                        variant="outline"
                                        :disabled="busy !== null"
                                        @click="
                                            refundTarget = member;
                                            refundOpen = true;
                                        "
                                    >
                                        <Undo2 />
                                        Refund
                                    </Button>
                                </template>
                            </div>
                        </div>
                    </div>

                    <PatientPicker
                        v-if="
                            isOpenSession &&
                            can('clinic.sessions.members.store')
                        "
                        single
                        placeholder="Add a patient"
                        :exclude-ids="
                            session.members?.map(
                                (member) => member.patient?.id ?? 0,
                            ) ?? []
                        "
                        @pick="addPatient"
                    />
                </div>
            </div>
        </SheetContent>
    </Sheet>

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Delete this session?"
        description="This cannot be undone. Sessions with billed items cannot be deleted; cancel them instead."
        :processing="busy === 'delete'"
        @confirm="deleteSession"
    />
    <ConfirmDialog
        v-model:open="refundOpen"
        title="Refund this payment?"
        :description="`A refund of ${formatMoney(refundTarget ? memberTotal(refundTarget) : 0)} will be recorded for ${patientName(refundTarget?.patient)}. This cannot be undone.`"
        confirm-label="Refund"
        :processing="busy?.startsWith('pay-') ?? false"
        @confirm="
            refundTarget && setPayment(refundTarget, 'REFUNDED');
            refundOpen = false;
        "
    />
</template>
