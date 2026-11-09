<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarClock,
    HeartHandshake,
    LogIn,
    MailQuestion,
    PartyPopper,
    TimerOff,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import LottieAnimation from '@/components/LottieAnimation.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import calendarCheck from '@/animations/calendar-check.json';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { login } from '@/routes';
import { home as clinicHome } from '@/routes/admin/clinics';
import { accept } from '@/routes/invitations';

/**
 * The page an invitation email links to. People who already have an account
 * have joined by the time they see it; first-time users choose a password.
 */
type Props = {
    state: 'create' | 'joined' | 'used' | 'expired' | 'revoked' | 'invalid';
    token: string | null;
    invitation: {
        email: string;
        clinic: { id: number; name: string | null };
        inviter: string | null;
        role: string;
        expires_at: string;
    } | null;
    name?: string;
    signedInAsInvitee?: boolean;
    signedInAs?: string | null;
    passwordRules?: string;
};

const props = defineProps<Props>();

const clinicName = computed(
    () => props.invitation?.clinic.name ?? 'the clinic',
);
const firstName = computed(() => props.name?.split(' ')[0] ?? '');
const invitedBy = computed(() =>
    props.invitation?.inviter
        ? `${props.invitation.inviter} invited you`
        : 'You were invited',
);

const copy = computed(() => {
    switch (props.state) {
        case 'create':
            return {
                title: `Join ${clinicName.value}`,
                description: `${invitedBy.value} to join the team. Choose a password to set up your account, and you’re in.`,
            };
        case 'joined':
            return {
                title: `Welcome to ${clinicName.value}${firstName.value ? `, ${firstName.value}` : ''}!`,
                description: 'You’re part of the team now.',
            };
        case 'used':
            return {
                title: 'You’re already in',
                description: `This invitation to ${clinicName.value} has been accepted.`,
            };
        case 'expired':
            return {
                title: 'This invitation has expired',
                description: 'Invitations work for 7 days.',
            };
        case 'revoked':
            return {
                title: 'This invitation was withdrawn',
                description: 'The clinic cancelled it.',
            };
        default:
            return {
                title: 'We couldn’t find this invitation',
                description: 'The link may be incomplete.',
            };
    }
});

setLayoutProps({
    title: copy.value.title,
    description: copy.value.description,
});
</script>

<template>
    <Head :title="copy.title" />

    <!-- First-time user: create the account -->
    <Form
        v-if="state === 'create' && token && invitation"
        v-bind="accept.form(token)"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-5">
            <div
                class="flex items-start gap-3 rounded-xl border bg-(--surface-sunken) p-4 text-sm"
            >
                <HeartHandshake class="mt-0.5 size-5 shrink-0 text-primary" />
                <p class="text-muted-foreground">
                    You’ll join
                    <strong class="text-foreground">{{ clinicName }}</strong> as
                    {{ invitation.role }}. Your admin can change your role
                    whenever it makes sense.
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="email" class="text-xs font-medium"
                    >Your email</Label
                >
                <Input
                    id="email"
                    type="email"
                    :model-value="invitation.email"
                    autocomplete="email"
                    class="h-10 bg-(--surface-sunken) text-sm text-muted-foreground"
                    readonly
                />
            </div>

            <div class="grid gap-2">
                <Label for="name" class="text-xs font-medium">
                    What should we call you?
                </Label>
                <Input
                    id="name"
                    name="name"
                    autocomplete="name"
                    class="h-10 bg-background/60"
                    placeholder="Your full name"
                    autofocus
                    required
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="password" class="text-xs font-medium">
                    Create a password
                </Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    class="h-10 bg-background/60"
                    placeholder="Something only you know"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation" class="text-xs font-medium">
                    Confirm your password
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="h-10 bg-background/60"
                    placeholder="Type it once more"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <InputError :message="errors.invitation" />

            <Button
                type="submit"
                size="lg"
                class="mt-1 h-11 w-full font-medium"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                <span>Join {{ clinicName }}</span>
                <ArrowRight v-if="!processing" class="ml-1 size-4" />
            </Button>

            <p class="text-center text-xs text-muted-foreground">
                This invitation works until
                {{ formatDate(invitation.expires_at) }}.
            </p>
        </div>
    </Form>

    <!-- Existing user: they have joined -->
    <div
        v-else-if="state === 'joined' && invitation"
        class="animate__animated animate__fadeInUp grid gap-6 text-center"
        style="--animate-duration: 0.5s"
    >
        <div class="mx-auto size-32">
            <LottieAnimation :animation-data="calendarCheck" :loop="false" />
        </div>
        <div class="grid gap-3 text-sm leading-relaxed text-muted-foreground">
            <p>
                <PartyPopper class="mr-1 inline size-4 text-primary" />
                {{ clinicName }} is now one of your clinics. Everything you do
                there sits next to your other clinics, under the same sign-in.
            </p>
            <p>
                You’re starting as {{ invitation.role
                }}<template v-if="invitation.inviter"
                    >, and {{ invitation.inviter }} can change that</template
                ><template v-else>; an admin can change that</template> whenever
                it makes sense. Welcome aboard. We’re glad you’re here.
            </p>
        </div>

        <Button
            v-if="signedInAsInvitee"
            size="lg"
            class="h-11 w-full font-medium"
            as-child
        >
            <Link :href="clinicHome(invitation.clinic.id)">
                Open {{ clinicName }}
                <ArrowRight class="ml-1 size-4" />
            </Link>
        </Button>
        <template v-else>
            <p
                v-if="signedInAs"
                class="rounded-lg border bg-(--surface-sunken) p-3 text-xs text-muted-foreground"
            >
                You’re signed in as {{ signedInAs }}. Sign in as
                {{ invitation.email }} to open {{ clinicName }}.
            </p>
            <Button size="lg" class="h-11 w-full font-medium" as-child>
                <Link :href="login()">
                    <LogIn class="mr-1 size-4" />
                    Sign in to get started
                </Link>
            </Button>
        </template>
    </div>

    <!-- Nothing to accept: say why, kindly -->
    <div v-else class="grid gap-5 text-center">
        <div
            class="mx-auto flex size-14 items-center justify-center rounded-full bg-(--surface-sunken)"
        >
            <LogIn v-if="state === 'used'" class="size-6 text-primary" />
            <TimerOff
                v-else-if="state === 'expired'"
                class="size-6 text-muted-foreground"
            />
            <CalendarClock
                v-else-if="state === 'revoked'"
                class="size-6 text-muted-foreground"
            />
            <MailQuestion v-else class="size-6 text-muted-foreground" />
        </div>
        <p class="text-sm leading-relaxed text-muted-foreground">
            <template v-if="state === 'used'">
                Good news: you’ve already joined {{ clinicName }}. Sign in with
                {{ invitation?.email }} to pick up where you left off.
            </template>
            <template v-else-if="state === 'expired'">
                No harm done. Ask
                {{ invitation?.inviter ?? 'the person who invited you' }} to
                send a fresh invitation to {{ invitation?.email }}, and you’ll
                be in within a minute.
            </template>
            <template v-else-if="state === 'revoked'">
                If you think that’s a mistake, reach out to
                {{ invitation?.inviter ?? 'the clinic' }}. They can send you a
                new one.
            </template>
            <template v-else>
                Try opening the link from the email again, or copy the whole
                link into your browser. If it still doesn’t work, ask for a new
                invitation.
            </template>
        </p>
        <Button
            v-if="state === 'used'"
            size="lg"
            class="h-11 w-full font-medium"
            as-child
        >
            <Link :href="login()">Sign in</Link>
        </Button>
    </div>
</template>
