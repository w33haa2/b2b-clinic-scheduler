<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Choose a new password',
        description:
            'Set a strong password for your staff account to complete the reset.',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const inputEmail = ref(props.email);
</script>

<template>
    <Head title="Reset password" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="email" class="text-xs font-medium">
                    Staff email address
                </Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-model="inputEmail"
                    class="h-10 bg-(--surface-sunken) text-sm text-muted-foreground"
                    readonly
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password" class="text-xs font-medium">
                    New password
                </Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    class="h-10 bg-background/60"
                    autofocus
                    placeholder="Create a new password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation" class="text-xs font-medium">
                    Confirm new password
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="h-10 bg-background/60"
                    placeholder="Re-enter your new password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                size="lg"
                class="mt-1 h-11 w-full font-medium"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                <KeyRound v-else class="mr-1.5 size-4" />
                <span>Save new password</span>
            </Button>
        </div>
    </Form>
</template>
