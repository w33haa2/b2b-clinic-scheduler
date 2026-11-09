<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { useTemplateRef } from 'vue';
import RolesManager from '@/components/access/RolesManager.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { useClinic } from '@/composables/useClinic';
import { roles as rolesPage } from '@/routes/admin/clinics';
import { index as permissionsIndex } from '@/routes/clinic/permissions';
import * as roles from '@/routes/clinic/roles';

const { clinicId, isAdmin } = useClinic();

setLayoutProps({
    breadcrumbs: [
        { title: 'Roles & permissions', href: rolesPage(clinicId.value) },
    ],
});

const manager = useTemplateRef<{ openCreate: () => void }>('manager');
const endpoints = {
    list: roles.index.url(clinicId.value),
    store: roles.store.url(clinicId.value),
    role: (roleId: number) =>
        roles.update.url({ clinic: clinicId.value, clinicRole: roleId }),
    permissions: permissionsIndex.url(clinicId.value),
};
</script>

<template>
    <Head title="Roles & permissions" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Roles & permissions"
            description="Roles bundle permissions; a member gets every permission of every role they hold."
        >
            <template v-if="isAdmin" #actions>
                <Button @click="manager?.openCreate()">
                    <Plus />
                    New role
                </Button>
            </template>
        </PageHeader>

        <RolesManager
            ref="manager"
            :endpoints="endpoints"
            :can-manage="isAdmin"
        />
    </div>
</template>
