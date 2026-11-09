<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { MailPlus, UserPlus } from '@lucide/vue';
import { useTemplateRef } from 'vue';
import InvitationsManager from '@/components/access/InvitationsManager.vue';
import MembersManager from '@/components/access/MembersManager.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { useClinic } from '@/composables/useClinic';
import { members as membersPage } from '@/routes/admin/clinics';
import * as invitations from '@/routes/clinic/invitations';
import * as members from '@/routes/clinic/members';
import { index as rolesIndex } from '@/routes/clinic/roles';

const { clinicId, isAdmin, context } = useClinic();

setLayoutProps({
    breadcrumbs: [{ title: 'Staff', href: membersPage(clinicId.value) }],
});

const manager = useTemplateRef<{ openCreate: () => void }>('manager');
const endpoints = {
    list: (page: number, perPage: number) =>
        members.index.url(clinicId.value, {
            query: { page, per_page: perPage },
        }),
    store: members.store.url(clinicId.value),
    member: (memberId: number) =>
        members.update.url({ clinic: clinicId.value, clinicUser: memberId }),
    roles: rolesIndex.url(clinicId.value),
};

// Clinic Admins can also invite people by email (they join as Clinic Worker).
const invitationsManager = useTemplateRef<{ openInvite: () => void }>(
    'invitationsManager',
);
const invitationEndpoints = {
    list: (page: number, perPage: number) =>
        invitations.index.url(clinicId.value, {
            query: { page, per_page: perPage },
        }),
    store: invitations.store.url(clinicId.value),
    resend: (invitationId: number) =>
        invitations.resend.url({
            clinic: clinicId.value,
            clinicInvitation: invitationId,
        }),
    invitation: (invitationId: number) =>
        invitations.destroy.url({
            clinic: clinicId.value,
            clinicInvitation: invitationId,
        }),
};
</script>

<template>
    <Head title="Staff" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Staff"
            :description="
                isAdmin
                    ? 'Who works at this clinic and what they can do.'
                    : 'Who works at this clinic.'
            "
        >
            <template v-if="isAdmin" #actions>
                <Button
                    variant="outline"
                    @click="invitationsManager?.openInvite()"
                >
                    <MailPlus />
                    Invite by email
                </Button>
                <Button @click="manager?.openCreate()">
                    <UserPlus />
                    Add staff member
                </Button>
            </template>
        </PageHeader>

        <InvitationsManager
            v-if="isAdmin"
            ref="invitationsManager"
            :endpoints="invitationEndpoints"
            :clinic-name="context.clinic.name"
        />

        <MembersManager
            ref="manager"
            :endpoints="endpoints"
            :can-manage="isAdmin"
        />
    </div>
</template>
