<script setup lang="ts">
import { Head, router, setLayoutProps, useHttp } from '@inertiajs/vue3';
import { Archive, MailPlus, Plus, UserPlus } from '@lucide/vue';
import { ref, useTemplateRef } from 'vue';
import { toast } from 'vue-sonner';
import InvitationsManager from '@/components/access/InvitationsManager.vue';
import MembersManager from '@/components/access/MembersManager.vue';
import RolesManager from '@/components/access/RolesManager.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import TimezoneCombobox from '@/components/form/TimezoneCombobox.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { api, ApiError } from '@/lib/api';
import {
    index as clinicsPage,
    show as clinicPage,
} from '@/routes/admin/internal/clinics';
import * as clinics from '@/routes/internal/clinics';
import * as invitations from '@/routes/internal/clinics/invitations';
import * as roles from '@/routes/internal/clinics/roles';
import * as users from '@/routes/internal/clinics/users';
import { index as permissionsIndex } from '@/routes/internal/permissions';
import type { Clinic, Wrapped } from '@/types';

const props = defineProps<{ clinic: Wrapped<Clinic> }>();
const clinic = ref(props.clinic.data);

setLayoutProps({
    breadcrumbs: [
        { title: 'Clinics', href: clinicsPage() },
        { title: clinic.value.name, href: clinicPage(clinic.value.id) },
    ],
});

const tab = ref('users');
const membersManager = useTemplateRef<{ openCreate: () => void }>(
    'membersManager',
);
const rolesManager = useTemplateRef<{ openCreate: () => void }>('rolesManager');
const invitationsManager = useTemplateRef<{ openInvite: () => void }>(
    'invitationsManager',
);

const invitationEndpoints = {
    list: (page: number, perPage: number) =>
        invitations.index.url(clinic.value.id, {
            query: { page, per_page: perPage },
        }),
    store: invitations.store.url(clinic.value.id),
    resend: (invitationId: number) =>
        invitations.resend.url({
            clinic: clinic.value.id,
            clinicInvitation: invitationId,
        }),
    invitation: (invitationId: number) =>
        invitations.destroy.url({
            clinic: clinic.value.id,
            clinicInvitation: invitationId,
        }),
};

const memberEndpoints = {
    list: (page: number, perPage: number) =>
        users.index.url(clinic.value.id, {
            query: { page, per_page: perPage },
        }),
    store: users.store.url(clinic.value.id),
    member: (memberId: number) =>
        users.update.url({ clinic: clinic.value.id, clinicUser: memberId }),
    roles: roles.index.url(clinic.value.id),
};
const roleEndpoints = {
    list: roles.index.url(clinic.value.id),
    store: roles.store.url(clinic.value.id),
    role: (roleId: number) =>
        roles.update.url({ clinic: clinic.value.id, clinicRole: roleId }),
    permissions: permissionsIndex.url(),
};

const details = useHttp<
    { name: string; address: string; timezone: string },
    Wrapped<Clinic>
>({
    name: clinic.value.name,
    address: clinic.value.address ?? '',
    timezone: clinic.value.timezone,
});

async function saveDetails(): Promise<void> {
    await details.put(clinics.update.url(clinic.value.id), {
        onSuccess: (response: Wrapped<Clinic>) => {
            clinic.value = response.data;
            toast.success('Clinic details saved.');
        },
    });
}

const archiveOpen = ref(false);
const archiving = ref(false);

async function archive(): Promise<void> {
    archiving.value = true;

    try {
        await api.delete(clinics.destroy.url(clinic.value.id));
        toast.success(`${clinic.value.name} archived.`);
        router.visit(clinicsPage());
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not archive the clinic.',
        );
        archiving.value = false;
    }
}
</script>

<template>
    <Head :title="clinic.name" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="clinic.name"
            :description="clinic.address ?? 'No address'"
        >
            <template #actions>
                <Button
                    v-if="tab === 'users'"
                    variant="outline"
                    @click="invitationsManager?.openInvite()"
                >
                    <MailPlus />
                    Invite by email
                </Button>
                <Button
                    v-if="tab === 'users'"
                    @click="membersManager?.openCreate()"
                >
                    <UserPlus />
                    Add user
                </Button>
                <Button
                    v-if="tab === 'roles'"
                    @click="rolesManager?.openCreate()"
                >
                    <Plus />
                    New role
                </Button>
            </template>
        </PageHeader>

        <Tabs v-model="tab">
            <TabsList>
                <TabsTrigger value="users">Users</TabsTrigger>
                <TabsTrigger value="roles">Roles</TabsTrigger>
                <TabsTrigger value="details">Details</TabsTrigger>
            </TabsList>
            <TabsContent value="users" class="mt-4 space-y-4">
                <InvitationsManager
                    ref="invitationsManager"
                    :endpoints="invitationEndpoints"
                    :clinic-name="clinic.name"
                />
                <MembersManager
                    ref="membersManager"
                    :endpoints="memberEndpoints"
                    can-manage
                />
            </TabsContent>
            <TabsContent value="roles" class="mt-4">
                <RolesManager
                    ref="rolesManager"
                    :endpoints="roleEndpoints"
                    can-manage
                />
            </TabsContent>
            <TabsContent value="details" class="mt-4">
                <Card class="max-w-xl">
                    <CardContent>
                        <form class="grid gap-4" @submit.prevent="saveDetails">
                            <FormField
                                label="Name"
                                for="clinic-name"
                                :error="details.errors.name"
                            >
                                <Input
                                    id="clinic-name"
                                    v-model="details.name"
                                    required
                                />
                            </FormField>
                            <FormField
                                label="Address"
                                for="clinic-address"
                                :error="details.errors.address"
                            >
                                <Input
                                    id="clinic-address"
                                    v-model="details.address"
                                />
                            </FormField>
                            <FormField
                                label="Timezone"
                                for="clinic-timezone"
                                :error="details.errors.timezone"
                            >
                                <TimezoneCombobox
                                    id="clinic-timezone"
                                    v-model="details.timezone"
                                />
                            </FormField>
                            <div class="flex flex-wrap justify-between gap-2">
                                <Button
                                    type="submit"
                                    :disabled="details.processing"
                                >
                                    <Spinner v-if="details.processing" />
                                    Save details
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    class="text-destructive"
                                    @click="archiveOpen = true"
                                >
                                    <Archive />
                                    Archive clinic
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </TabsContent>
        </Tabs>
    </div>

    <ConfirmDialog
        v-model:open="archiveOpen"
        :title="`Archive ${clinic.name}?`"
        description="Its staff lose access immediately. All of its data is kept."
        confirm-label="Archive"
        :processing="archiving"
        @confirm="archive"
    />
</template>
