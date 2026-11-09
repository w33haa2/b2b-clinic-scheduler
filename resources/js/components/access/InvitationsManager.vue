<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Mail, MailPlus, RotateCw, X } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import FormField from '@/components/FormField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useApiQuery } from '@/composables/useApiQuery';
import { usePagination } from '@/composables/usePagination';
import { api, ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ClinicInvitation, Paginated, Wrapped } from '@/types';
import type { InvitationEndpoints } from './types';

/**
 * Invite people to a clinic by email and follow up on the invitations:
 * resend a link, or cancel one that is still pending. Invited people join
 * as Clinic Worker; their role can be changed once they are in.
 */
type Props = {
    endpoints: InvitationEndpoints;
    clinicName: string;
};

const props = defineProps<Props>();

const { page, perPage } = usePagination(10);
const { data, refresh } = useApiQuery<Paginated<ClinicInvitation>>(() =>
    props.endpoints.list(page.value, perPage.value),
);

const dialogOpen = ref(false);
const form = useHttp<{ email: string }, Wrapped<ClinicInvitation>>({
    email: '',
});
const busyId = ref<number | null>(null);
const revoking = ref<ClinicInvitation | null>(null);

const statusTone: Record<ClinicInvitation['status'], string> = {
    pending:
        'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    accepted:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    expired: 'bg-muted text-muted-foreground',
    revoked: 'bg-muted text-muted-foreground line-through',
};
const statusLabel: Record<ClinicInvitation['status'], string> = {
    pending: 'Waiting',
    accepted: 'Joined',
    expired: 'Expired',
    revoked: 'Cancelled',
};

function openInvite(): void {
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

async function send(): Promise<void> {
    await form.post(props.endpoints.store, {
        onSuccess: (response: Wrapped<ClinicInvitation>) => {
            toast.success(`Invitation sent to ${response.data.email}.`);
            dialogOpen.value = false;
            page.value = 1;
            void refresh();
        },
    });
}

async function resend(invitation: ClinicInvitation): Promise<void> {
    busyId.value = invitation.id;

    try {
        await api.post(props.endpoints.resend(invitation.id));
        toast.success(`A new link is on its way to ${invitation.email}.`);
        await refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not resend the invitation.',
        );
    } finally {
        busyId.value = null;
    }
}

async function revoke(): Promise<void> {
    const invitation = revoking.value;

    if (!invitation) {
        return;
    }

    busyId.value = invitation.id;

    try {
        await api.delete(props.endpoints.invitation(invitation.id));
        toast.success(`Invitation for ${invitation.email} cancelled.`);
        revoking.value = null;
        await refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not cancel the invitation.',
        );
    } finally {
        busyId.value = null;
    }
}

defineExpose({ openInvite, refresh });
</script>

<template>
    <Card v-if="data && data.data.length" class="gap-0 py-0">
        <div class="flex items-center gap-2 border-b px-4 py-3">
            <Mail class="size-4 text-muted-foreground" />
            <h3 class="text-sm font-semibold">Invitations</h3>
            <Button
                size="sm"
                variant="ghost"
                class="ml-auto h-7"
                @click="openInvite"
            >
                <MailPlus />
                Invite
            </Button>
        </div>
        <ul class="divide-y">
            <li
                v-for="invitation in data.data"
                :key="invitation.id"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-sm"
            >
                <span class="min-w-0 flex-1 truncate font-medium">{{
                    invitation.email
                }}</span>
                <span class="text-xs text-muted-foreground">
                    <template v-if="invitation.status === 'accepted'"
                        >joined
                        {{ formatDate(invitation.accepted_at) }}</template
                    >
                    <template v-else-if="invitation.status === 'pending'"
                        >sent {{ formatDate(invitation.created_at) }} · until
                        {{ formatDate(invitation.expires_at) }}</template
                    >
                    <template v-else
                        >sent {{ formatDate(invitation.created_at) }}</template
                    >
                    <template v-if="invitation.invited_by">
                        by {{ invitation.invited_by.name }}</template
                    >
                </span>
                <Badge
                    variant="secondary"
                    :class="
                        cn('border-transparent', statusTone[invitation.status])
                    "
                    >{{ statusLabel[invitation.status] }}</Badge
                >
                <div class="flex gap-1">
                    <Button
                        v-if="
                            invitation.status === 'pending' ||
                            invitation.status === 'expired'
                        "
                        size="icon"
                        variant="ghost"
                        class="size-7"
                        :aria-label="`Resend the invitation to ${invitation.email}`"
                        :disabled="busyId !== null"
                        @click="resend(invitation)"
                    >
                        <Spinner v-if="busyId === invitation.id" />
                        <RotateCw v-else />
                    </Button>
                    <Button
                        v-if="invitation.status === 'pending'"
                        size="icon"
                        variant="ghost"
                        class="size-7"
                        :aria-label="`Cancel the invitation to ${invitation.email}`"
                        :disabled="busyId !== null"
                        @click="revoking = invitation"
                    >
                        <X />
                    </Button>
                </div>
            </li>
        </ul>
        <div v-if="data.meta.last_page > 1" class="border-t px-4 py-2">
            <DataPagination
                v-model:page="page"
                v-model:per-page="perPage"
                :meta="data.meta"
                :per-page-options="[10, 25, 50]"
            />
        </div>
    </Card>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <MailPlus class="size-5 text-teal-600" />
                    Invite someone to {{ clinicName }}
                </DialogTitle>
                <DialogDescription>
                    We’ll email them a link that works for 7 days. They join as
                    Clinic Worker; you can change their role once they’re in. If
                    they already use the app, the clinic simply appears next to
                    their others.
                </DialogDescription>
            </DialogHeader>
            <form id="invite-form" class="grid gap-4" @submit.prevent="send">
                <FormField
                    label="Email address"
                    for="invite-email"
                    :error="form.errors.email"
                >
                    <Input
                        id="invite-email"
                        v-model="form.email"
                        type="email"
                        autocomplete="off"
                        placeholder="name@example.com"
                        required
                    />
                </FormField>
            </form>
            <DialogFooter>
                <Button variant="outline" @click="dialogOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="invite-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <MailPlus v-else />
                    Send invitation
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        :open="revoking !== null"
        title="Cancel this invitation?"
        :description="`The link sent to ${revoking?.email} will stop working. You can invite them again later.`"
        confirm-label="Cancel invitation"
        :processing="busyId !== null"
        @update:open="(value) => !value && (revoking = null)"
        @confirm="revoke"
    />
</template>
