<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, ShieldAlert } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Sign in to your clinic',
        description:
            'Use your staff email and password to open your schedule and workspace.',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        role="status"
        class="mb-6 flex items-start gap-2.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3.5 text-sm text-emerald-800 dark:text-emerald-300"
    >
        <CheckCircle2 class="mt-0.5 size-4 shrink-0" />
        <span>{{ status }}</span>
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
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
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="you@clinic.com"
                    class="h-10 bg-background/60"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password" class="text-xs font-medium">
                        Password
                    </Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-xs text-muted-foreground hover:text-primary"
                        :tabindex="5"
                    >
                        Forgot your password?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Enter your password"
                    class="h-10 bg-background/60"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between pt-0.5">
                <Label
                    for="remember"
                    class="flex cursor-pointer items-center gap-2.5 text-sm font-normal text-muted-foreground select-none hover:text-foreground"
                >
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>Keep me signed in on this device</span>
                </Label>
            </div>

            <Button
                type="submit"
                size="lg"
                class="mt-1 h-11 w-full font-medium"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                <span>Sign in to workspace</span>
                <ArrowRight v-if="!processing" class="ml-1 size-4" />
            </Button>
        </div>

        <div
            class="mt-2 flex items-start gap-2.5 rounded-xl border bg-(--surface-sunken)/70 p-3 text-xs text-muted-foreground"
        >
            <ShieldAlert class="mt-0.5 size-3.5 shrink-0 text-primary" />
            <p class="leading-relaxed">
                Staff accounts are created by your
                <strong class="font-medium text-foreground">Clinic Admin</strong
                >. Need access to another branch? Ask an admin to add your
                existing email to their clinic.
            </p>
        </div>
    </Form>
</template>
