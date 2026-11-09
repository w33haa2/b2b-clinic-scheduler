<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

defineOptions({
    layout: {
        title: 'Confirm your password',
        description:
            'This is a protected area of the clinic workspace. Please verify your password to continue.',
    },
});
</script>

<template>
    <Head title="Confirm password" />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="space-y-5">
            <div class="grid gap-2">
                <Label htmlFor="password" class="text-xs font-medium">
                    Current password
                </Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="h-10 bg-background/60"
                    required
                    autocomplete="current-password"
                    autofocus
                />

                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center">
                <Button
                    size="lg"
                    class="h-11 w-full font-medium"
                    :disabled="processing"
                    data-test="confirm-password-button"
                >
                    <Spinner v-if="processing" />
                    <ShieldCheck v-else class="mr-1.5 size-4" />
                    <span>Confirm password</span>
                </Button>
            </div>
        </div>
    </Form>
</template>
