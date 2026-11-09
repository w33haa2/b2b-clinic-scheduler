<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, Clock, Mail } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Reset your password',
        description:
            'Enter the email address connected to your staff account and we will send a one-hour reset link.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Forgot password" />

    <div
        v-if="status"
        role="status"
        class="mb-6 flex items-start gap-2.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3.5 text-sm text-emerald-800 dark:text-emerald-300"
    >
        <CheckCircle2 class="mt-0.5 size-4 shrink-0" />
        <span>{{ status }}</span>
    </div>

    <div class="space-y-6">
        <Form
            v-bind="email.form()"
            v-slot="{ errors, processing }"
            class="space-y-5"
        >
            <div class="grid gap-2">
                <Label for="email" class="text-xs font-medium">
                    Staff email address
                </Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                    v-focus
                    placeholder="you@clinic.com"
                    class="h-10 bg-background/60"
                />
                <InputError :message="errors.email" />
            </div>

            <Button
                size="lg"
                class="h-11 w-full font-medium"
                :disabled="processing"
                data-test="email-password-reset-link-button"
            >
                <Spinner v-if="processing" />
                <Mail v-else class="mr-1.5 size-4" />
                <span>Send password reset link</span>
            </Button>
        </Form>

        <div
            class="flex items-start gap-2.5 rounded-xl border bg-(--surface-sunken)/70 p-3 text-xs text-muted-foreground"
        >
            <Clock class="mt-0.5 size-3.5 shrink-0 text-primary" />
            <p class="leading-relaxed">
                Reset links are queued immediately and expire after
                <strong class="font-medium text-foreground">60 minutes</strong>
                for account security.
            </p>
        </div>

        <div class="border-t pt-4 text-center text-sm text-muted-foreground">
            <TextLink
                :href="login()"
                class="inline-flex items-center gap-1.5 font-medium"
            >
                <ArrowLeft class="size-3.5" />
                <span>Return to staff sign in</span>
            </TextLink>
        </div>
    </div>
</template>
