<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { watch } from 'vue';
import FormField from '@/components/FormField.vue';
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
import { Spinner } from '@/components/ui/spinner';
import type { ClinicMember, ClinicRole, Wrapped } from '@/types';
import type { MemberEndpoints } from './types';

type Props = {
    endpoints: MemberEndpoints;
    roles: ClinicRole[];
    /** Editing a member's roles; otherwise adding a member. */
    member?: ClinicMember | null;
};

const props = defineProps<Props>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [member: ClinicMember] }>();

const form = useHttp<
    { name: string; email: string; password: string; role_ids: number[] },
    Wrapped<ClinicMember>
>({
    name: '',
    email: '',
    password: '',
    role_ids: [],
});

form.transform(
    (data) =>
        (props.member
            ? { role_ids: data.role_ids }
            : { ...data, password: data.password || null }) as typeof data,
);

watch(open, (isOpen) => {
    if (isOpen) {
        const workerRole = props.roles.find((role) => role.key === 'worker');
        form.defaults({
            name: '',
            email: '',
            password: '',
            role_ids:
                props.member?.roles?.map((role) => role.id) ??
                (workerRole ? [workerRole.id] : []),
        })
            .reset()
            .clearErrors();
    }
});

function toggle(roleId: number, checked: boolean | 'indeterminate'): void {
    form.role_ids =
        checked === true
            ? [...form.role_ids, roleId]
            : form.role_ids.filter((id) => id !== roleId);
}

async function submit(): Promise<void> {
    const options = {
        onSuccess: (response: Wrapped<ClinicMember>) => {
            emit('saved', response.data);
            open.value = false;
        },
    };

    if (props.member) {
        await form.put(props.endpoints.member(props.member.id), options);
    } else {
        await form.post(props.endpoints.store, options);
    }
}

const roleError = (errors: Record<string, string | undefined>) =>
    errors.role_ids ??
    errors.roles ??
    Object.entries(errors).find(([key]) => key.startsWith('role_ids.'))?.[1];
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    member
                        ? `Roles for ${member.user?.name}`
                        : 'Add a staff member'
                }}</DialogTitle>
                <DialogDescription v-if="!member">
                    If the email already has an account, that person is added to
                    this clinic. Without a password, they set one with “Forgot
                    password”.
                </DialogDescription>
            </DialogHeader>

            <form id="member-form" class="grid gap-4" @submit.prevent="submit">
                <template v-if="!member">
                    <FormField
                        label="Name"
                        for="member-name"
                        :error="form.errors.name"
                    >
                        <Input
                            id="member-name"
                            v-model="form.name"
                            v-focus
                            required
                        />
                    </FormField>
                    <FormField
                        label="Email"
                        for="member-email"
                        :error="form.errors.email"
                    >
                        <Input
                            id="member-email"
                            v-model="form.email"
                            type="email"
                            required
                        />
                    </FormField>
                    <FormField
                        label="Password (optional)"
                        for="member-password"
                        :error="form.errors.password"
                    >
                        <Input
                            id="member-password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                        />
                    </FormField>
                </template>

                <FormField
                    label="Roles"
                    :error="
                        roleError(
                            form.errors as Record<string, string | undefined>,
                        )
                    "
                >
                    <div class="grid gap-2 rounded-md border p-3">
                        <label
                            v-for="role in roles"
                            :key="role.id"
                            class="flex cursor-pointer items-center gap-3 text-sm"
                        >
                            <Checkbox
                                :model-value="form.role_ids.includes(role.id)"
                                @update:model-value="
                                    (checked) => toggle(role.id, checked)
                                "
                            />
                            <span class="flex-1">{{ role.name }}</span>
                            <span
                                v-if="role.key === 'admin'"
                                class="text-xs text-muted-foreground"
                                >Full access</span
                            >
                        </label>
                    </div>
                </FormField>
            </form>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    type="submit"
                    form="member-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    {{ member ? 'Save roles' : 'Add member' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
