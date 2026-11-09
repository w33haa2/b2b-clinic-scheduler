<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { watch } from 'vue';
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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { ClinicRole, Permission, Wrapped } from '@/types';
import PermissionChecklist from './PermissionChecklist.vue';
import type { RoleEndpoints } from './types';

type Props = {
    endpoints: RoleEndpoints;
    permissions: Permission[];
    role?: ClinicRole | null;
};

const props = defineProps<Props>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [role: ClinicRole] }>();

const form = useHttp<
    { name: string; permission_ids: number[] },
    Wrapped<ClinicRole>
>({ name: '', permission_ids: [] });

// The Clinic Worker role keeps its name; only its permissions are sent.
form.transform(
    (data) =>
        (props.role?.key === 'worker'
            ? { permission_ids: data.permission_ids }
            : data) as typeof data,
);

watch(open, (isOpen) => {
    if (isOpen) {
        const names = props.role?.permissions ?? [];
        form.defaults({
            name: props.role?.name ?? '',
            permission_ids: props.permissions
                .filter((permission) => names.includes(permission.name))
                .map((p) => p.id),
        })
            .reset()
            .clearErrors();
    }
});

async function submit(): Promise<void> {
    const options = {
        onSuccess: (response: Wrapped<ClinicRole>) => {
            emit('saved', response.data);
            open.value = false;
        },
    };

    if (props.role) {
        await form.put(props.endpoints.role(props.role.id), options);
    } else {
        await form.post(props.endpoints.store, options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>{{
                    role ? `Edit ${role.name}` : 'New role'
                }}</DialogTitle>
                <DialogDescription>
                    Each permission unlocks one action in the clinic. Managing
                    staff and roles stays with Clinic Admins.
                </DialogDescription>
            </DialogHeader>

            <form id="role-form" class="grid gap-4" @submit.prevent="submit">
                <FormField
                    label="Name"
                    for="role-name"
                    :error="form.errors.name"
                    :hint="
                        role?.key === 'worker'
                            ? 'The built-in Clinic Worker role cannot be renamed.'
                            : undefined
                    "
                >
                    <Input
                        id="role-name"
                        v-model="form.name"
                        :disabled="role?.key === 'worker'"
                        required
                        placeholder="e.g. Cashier"
                    />
                </FormField>
                <FormField
                    label="Permissions"
                    :error="form.errors.permission_ids"
                >
                    <PermissionChecklist
                        v-model="form.permission_ids"
                        :permissions="permissions"
                    />
                </FormField>
            </form>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    type="submit"
                    form="role-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    {{ role ? 'Save role' : 'Create role' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
