<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ArrowUpRight, RotateCcw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';
import { payoutSettings, receivables } from '@/routes/admin/clinics';
import { show, update } from '@/routes/clinic/payout-settings';
import type {
    PayoutSettingsMember,
    PayoutSettingsPage,
    Wrapped,
} from '@/types';

/**
 * How the clinic splits what it bills and what a payout may include. Clinic
 * Admin only. Everything on the page is saved in one request; nothing here
 * touches a payout that already exists.
 */
const { clinicId, isAdmin } = useClinic();

setLayoutProps({
    breadcrumbs: [
        { title: 'Payout settings', href: payoutSettings(clinicId.value) },
    ],
});

/** A round number, so the worked example reads like real money. */
const EXAMPLE_AMOUNT = 1000;

/** The page state while it is being edited. "" = follow the clinic default. */
type Draft = {
    workerShare: string;
    includePendingPayments: boolean;
    requireAttendance: boolean;
    memberShares: Record<number, string>;
};

const { data, loading } = useApiQuery<Wrapped<PayoutSettingsPage>>(() =>
    show.url(clinicId.value),
);

const draft = ref<Draft | null>(null);
/** What the API last told us, to compare against and to discard back to. */
const saved = ref<Draft | null>(null);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});

function toDraft(page: PayoutSettingsPage): Draft {
    return {
        workerShare: page.settings.worker_share_percentage,
        includePendingPayments: page.settings.include_pending_payments,
        requireAttendance: page.settings.require_attendance,
        memberShares: Object.fromEntries(
            page.members.map((member) => [
                member.id,
                member.payout_share_percentage ?? '',
            ]),
        ),
    };
}

// Hydrate the editable copy from the read, and again after every save.
watch(data, (page) => {
    if (page) {
        saved.value = toDraft(page.data);
        draft.value = toDraft(page.data);
    }
});

const settings = computed(() => data.value?.data.settings ?? null);
const members = computed<PayoutSettingsMember[]>(
    () => data.value?.data.members ?? [],
);

const isDirty = computed(
    () => JSON.stringify(draft.value) !== JSON.stringify(saved.value),
);

/** The clinic default as a number, clamped so the read-out stays sane. */
const workerShare = computed(() => {
    const value = Number(draft.value?.workerShare);

    return Number.isFinite(value) ? Math.min(Math.max(value, 0), 100) : 0;
});
const clinicShare = computed(() => 100 - workerShare.value);

/** 60 → "60", 62.5 → "62.5". */
function formatShare(value: number | string): string {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? String(Number(parsed.toFixed(2))) : '—';
}

const exampleWorkerAmount = computed(
    () => (EXAMPLE_AMOUNT * workerShare.value) / 100,
);
const exampleClinicAmount = computed(
    () => EXAMPLE_AMOUNT - exampleWorkerAmount.value,
);

function shareOf(member: PayoutSettingsMember): string {
    return draft.value?.memberShares[member.id] ?? '';
}

function usesOwnRate(member: PayoutSettingsMember): boolean {
    return shareOf(member).trim() !== '';
}

/** What actually applies to a member right now: their rate, or the default. */
function effectiveShare(member: PayoutSettingsMember): string {
    return usesOwnRate(member)
        ? formatShare(shareOf(member))
        : formatShare(workerShare.value);
}

function setShare(member: PayoutSettingsMember, value: string): void {
    if (draft.value) {
        draft.value.memberShares[member.id] = value;
    }
}

/** 422s on `member_shares.*` come back by position, so map them to rows. */
const memberErrors = computed<Record<number, string | undefined>>(() =>
    Object.fromEntries(
        members.value.map((member, index) => [
            member.id,
            errors.value[`member_shares.${index}.share_percentage`]?.[0] ??
                errors.value[`member_shares.${index}.clinic_user_id`]?.[0],
        ]),
    ),
);

function discard(): void {
    if (saved.value) {
        draft.value = {
            ...saved.value,
            memberShares: { ...saved.value.memberShares },
        };
    }

    errors.value = {};
}

async function save(): Promise<void> {
    const current = draft.value;

    if (!current) {
        return;
    }

    saving.value = true;
    errors.value = {};

    try {
        // One request: the clinic default, both rules and every override.
        data.value = await api.put<Wrapped<PayoutSettingsPage>>(
            update.url(clinicId.value),
            {
                worker_share_percentage: current.workerShare.trim(),
                include_pending_payments: current.includePendingPayments,
                require_attendance: current.requireAttendance,
                member_shares: members.value.map((member) => ({
                    clinic_user_id: member.id,
                    share_percentage: usesOwnRate(member)
                        ? shareOf(member).trim()
                        : null,
                })),
            },
        );
        toast.success('Payout settings saved.');
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.errors;
            toast.error(error.firstMessage);

            return;
        }

        toast.error('Could not save the payout settings.');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head title="Payout settings" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Payout settings"
            description="How every billed item is split between the staff member who facilitated the session and the clinic, and what a payout is allowed to include. Changing any of this never moves a payout that already exists — each payout snapshots the split it was generated with."
        />

        <template v-if="loading && !draft">
            <Skeleton class="h-48 rounded-xl" />
            <Skeleton class="h-56 rounded-xl" />
            <Skeleton class="h-64 rounded-xl" />
        </template>

        <template v-else-if="draft">
            <Card
                class="animate__animated animate__fadeInUp"
                style="--animate-duration: 0.5s"
            >
                <CardHeader>
                    <CardTitle>The split</CardTitle>
                    <CardDescription>
                        The cut of every billed item that goes to the staff
                        member who facilitated the session. The clinic keeps the
                        rest, so the two always add up to 100%.
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-6 md:grid-cols-2 md:items-start">
                    <FormField
                        label="Staff share"
                        for="worker-share"
                        :error="errors.worker_share_percentage?.[0]"
                        hint="Anything from 0 to 100, with up to two decimals."
                    >
                        <div class="relative w-40">
                            <Input
                                id="worker-share"
                                :model-value="draft.workerShare"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                inputmode="decimal"
                                class="pr-8 tabular-nums"
                                :disabled="!isAdmin || saving"
                                @update:model-value="
                                    (value) =>
                                        draft &&
                                        (draft.workerShare = String(value))
                                "
                            />
                            <span
                                class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                                >%</span
                            >
                        </div>
                    </FormField>

                    <div class="rounded-xl border bg-muted/40 p-4">
                        <p class="text-lg font-semibold">
                            <span
                                class="text-teal-700 tabular-nums dark:text-teal-300"
                                >{{ formatShare(workerShare) }}% to staff</span
                            >
                            <span class="text-muted-foreground"> · </span>
                            <span class="tabular-nums"
                                >{{ formatShare(clinicShare) }}% to the
                                clinic</span
                            >
                        </p>
                        <div
                            class="mt-3 flex h-2 overflow-hidden rounded-full bg-muted"
                            aria-hidden="true"
                        >
                            <div
                                class="bg-teal-600"
                                :style="{ width: `${workerShare}%` }"
                            />
                            <div class="flex-1 bg-muted-foreground/25" />
                        </div>
                        <p class="mt-3 text-sm text-muted-foreground">
                            On a
                            {{ formatMoney(EXAMPLE_AMOUNT) }} service, the staff
                            member gets
                            <span
                                class="font-medium text-foreground tabular-nums"
                                >{{ formatMoney(exampleWorkerAmount) }}</span
                            >
                            and the clinic keeps
                            <span
                                class="font-medium text-foreground tabular-nums"
                                >{{ formatMoney(exampleClinicAmount) }}</span
                            >.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card
                class="animate__animated animate__fadeInUp"
                style="--animate-duration: 0.5s"
            >
                <CardHeader>
                    <CardTitle>Payout rules</CardTitle>
                    <CardDescription>
                        What a payout may include when it is generated.
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-3">
                    <div class="flex items-start gap-3 rounded-lg border p-4">
                        <Checkbox
                            id="include-pending"
                            class="mt-0.5"
                            :model-value="draft.includePendingPayments"
                            :disabled="!isAdmin || saving"
                            @update:model-value="
                                (value) =>
                                    draft &&
                                    (draft.includePendingPayments =
                                        value === true)
                            "
                        />
                        <div class="space-y-1.5">
                            <Label for="include-pending"
                                >Pay for sessions patients have not paid
                                yet</Label
                            >
                            <p class="text-sm text-muted-foreground">
                                On, a payout includes work whose patient has not
                                paid yet: the staff member is paid now and the
                                clinic carries the amount until the patient
                                pays. Off, unpaid work is simply left out of
                                payouts and waits for the next one.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                While that money is out, every advanced item
                                sits on
                                <Link
                                    :href="receivables(clinicId)"
                                    class="font-medium text-teal-700 underline-offset-4 hover:underline dark:text-teal-300"
                                    >Receivables<ArrowUpRight
                                        class="ml-0.5 inline size-3.5 align-text-top"
                                /></Link>
                                until it is collected.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 rounded-lg border p-4">
                        <Checkbox
                            id="require-attendance"
                            class="mt-0.5"
                            :model-value="draft.requireAttendance"
                            :disabled="!isAdmin || saving"
                            @update:model-value="
                                (value) =>
                                    draft &&
                                    (draft.requireAttendance = value === true)
                            "
                        />
                        <div class="space-y-1.5">
                            <Label for="require-attendance"
                                >Only pay for days marked present</Label
                            >
                            <p class="text-sm text-muted-foreground">
                                On, a payout only counts work from days the
                                member was marked Present or Late. Absent,
                                Excused and days with no attendance record are
                                skipped. Off, attendance is ignored and every
                                billed item in the period counts.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card
                class="animate__animated animate__fadeInUp gap-0 overflow-hidden pb-0"
                style="--animate-duration: 0.5s"
            >
                <CardHeader class="pb-6">
                    <CardTitle>Staff rates</CardTitle>
                    <CardDescription>
                        A member can have their own rate, which overrides the
                        clinic default of
                        {{ formatShare(workerShare) }}%. Leave a rate empty to
                        follow the default.
                    </CardDescription>
                </CardHeader>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Member</TableHead>
                            <TableHead class="hidden md:table-cell"
                                >Roles</TableHead
                            >
                            <TableHead class="w-56 text-right">Rate</TableHead>
                            <TableHead class="w-40">Applies</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="member in members" :key="member.id">
                            <TableCell>
                                <div class="font-medium">
                                    {{ member.user?.name ?? 'Unknown member' }}
                                </div>
                                <div class="text-sm text-muted-foreground">
                                    {{ member.user?.email }}
                                </div>
                            </TableCell>
                            <TableCell class="hidden md:table-cell">
                                <div class="flex flex-wrap gap-1">
                                    <Badge
                                        v-for="role in member.roles"
                                        :key="role.id"
                                        variant="secondary"
                                        >{{ role.name }}</Badge
                                    >
                                    <span
                                        v-if="!member.roles?.length"
                                        class="text-sm text-muted-foreground"
                                        >No roles</span
                                    >
                                </div>
                            </TableCell>
                            <TableCell>
                                <div
                                    class="flex items-center justify-end gap-1"
                                >
                                    <div class="relative w-28">
                                        <Input
                                            :model-value="shareOf(member)"
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            inputmode="decimal"
                                            :placeholder="
                                                formatShare(workerShare)
                                            "
                                            class="pr-7 text-right tabular-nums"
                                            :aria-label="`Rate for ${member.user?.name ?? 'this member'}`"
                                            :disabled="!isAdmin || saving"
                                            @update:model-value="
                                                (value) =>
                                                    setShare(
                                                        member,
                                                        String(value),
                                                    )
                                            "
                                        />
                                        <span
                                            class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-sm text-muted-foreground"
                                            >%</span
                                        >
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        :disabled="
                                            !isAdmin ||
                                            saving ||
                                            !usesOwnRate(member)
                                        "
                                        :aria-label="`Use the clinic default for ${member.user?.name ?? 'this member'}`"
                                        @click="setShare(member, '')"
                                        >Clear</Button
                                    >
                                </div>
                                <div class="flex justify-end">
                                    <InputError
                                        :message="memberErrors[member.id]"
                                    />
                                </div>
                            </TableCell>
                            <TableCell>
                                <Badge
                                    v-if="usesOwnRate(member)"
                                    variant="secondary"
                                    class="border-transparent bg-teal-100 text-teal-800 dark:bg-teal-500/15 dark:text-teal-300"
                                    >Uses own rate</Badge
                                >
                                <span
                                    v-else
                                    class="text-sm text-muted-foreground"
                                    >Clinic default</span
                                >
                                <div
                                    class="mt-0.5 text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ effectiveShare(member) }}% applies
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </Card>

            <div
                v-if="isAdmin"
                class="sticky bottom-0 -mx-4 -mb-4 flex flex-wrap items-center gap-3 border-t bg-background/85 px-4 py-3 backdrop-blur md:-mx-6 md:-mb-6 md:px-6"
            >
                <p class="mr-auto text-sm text-muted-foreground">
                    <template v-if="isDirty">Unsaved changes.</template>
                    <template v-else-if="settings?.updated_at"
                        >Last changed
                        {{ formatDateTime(settings.updated_at) }}.</template
                    >
                    <template v-else>Nothing changed yet.</template>
                </p>
                <Button
                    v-if="isDirty"
                    variant="ghost"
                    :disabled="saving"
                    @click="discard"
                >
                    <RotateCcw />
                    Discard changes
                </Button>
                <Button :disabled="!isDirty || saving" @click="save">
                    <Spinner v-if="saving" />
                    Save changes
                </Button>
            </div>
        </template>
    </div>
</template>
