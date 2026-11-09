<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Clock, Link2Off, Lock, ShieldOff } from '@lucide/vue';
import { computed } from 'vue';
import calendarCheck from '@/animations/calendar-check.json';
import LottieAnimation from '@/components/LottieAnimation.vue';
import DynamicPatientForm from '@/components/patients/DynamicPatientForm.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { submit } from '@/routes/patient-form';
import type { PatientFormField, PatientFormSection } from '@/types';

/**
 * The page a patient intake link opens. No sign-in: the token in the link is
 * the proof, and it works once. Everything here is written for someone on a
 * phone who has never seen the app.
 */
type Props = {
    state: 'invalid' | 'expired' | 'revoked' | 'submitted' | 'open';
    token: string | null;
    clinic: { name: string } | null;
    invitation: {
        name: string | null;
        email: string | null;
        is_update: boolean;
        expires_at: string;
        submitted_at: string | null;
    } | null;
    form?: { sections: PatientFormSection[] };
    answers?: Record<string, unknown>;
};

const props = defineProps<Props>();

type AnswerValue = string | boolean | string[] | null;

const clinicName = computed(() => props.clinic?.name ?? 'The clinic');
const firstName = computed(() => props.invitation?.name?.split(' ')[0] ?? '');
const sections = computed(() => props.form?.sections ?? []);

/** A saved answer as the control holds it, or a type-appropriate empty. */
function controlValue(field: PatientFormField, stored: unknown): AnswerValue {
    if (field.type === 'MULTISELECT') {
        return Array.isArray(stored)
            ? (stored as unknown[]).map((item) => String(item))
            : [];
    }

    if (field.type === 'CHECKBOX') {
        return stored === true || stored === 1;
    }

    return stored === null || stored === undefined ? '' : String(stored);
}

// The whole form arrives with the page, so the body is seeded straight away.
const submission = useForm<Record<string, AnswerValue>>(
    Object.fromEntries(
        sections.value
            .flatMap((section) => section.fields)
            .map((field) => [
                field.key,
                controlValue(field, props.answers?.[field.key]),
            ]),
    ),
);

// An emptied box clears the answer rather than saving "".
submission.transform((data) =>
    Object.fromEntries(
        Object.entries(data).map(([key, value]) => [
            key,
            value === '' ? null : value,
        ]),
    ),
);

/** The form writes straight into the submission, which is keyed the same way. */
const answers = computed(
    () => submission as unknown as Record<string, unknown>,
);

/** Errors come back keyed by field key, which this body only learns at runtime. */
const errors = computed(
    () => submission.errors as unknown as Record<string, string | undefined>,
);

const closedCopy = computed(() => {
    switch (props.state) {
        case 'expired':
            return {
                title: 'This form has closed',
                description: 'Intake links stay open for a limited time.',
            };
        case 'revoked':
            return {
                title: 'This form was withdrawn',
                description: `${clinicName.value} cancelled this link.`,
            };
        default:
            return {
                title: 'We couldn’t open this form',
                description: 'The link may be incomplete or already used.',
            };
    }
});

function send(): void {
    if (!props.token) {
        return;
    }

    submission.post(submit.url(props.token), { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto max-w-2xl px-4 py-10">
        <Head title="Patient form">
            <meta head-key="robots" name="robots" content="noindex, nofollow" />
        </Head>

        <!-- The form itself -->
        <div v-if="state === 'open' && token" class="grid gap-8">
            <header class="grid gap-3">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Patient form
                </p>
                <h1
                    class="font-display text-3xl font-normal text-balance sm:text-4xl"
                >
                    {{ clinicName }} would like you to complete this form
                </h1>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    <template v-if="firstName">{{ firstName }}, this</template>
                    <template v-else>This</template> takes a few minutes, and
                    the link works once — so please finish it in one go.
                    <template v-if="invitation?.is_update">
                        Your details are already filled in; correct anything
                        that is out of date.
                    </template>
                    <template v-else>
                        Fields marked * are the ones the clinic needs.
                    </template>
                </p>
                <p v-if="invitation" class="text-xs text-muted-foreground">
                    Open until {{ formatDate(invitation.expires_at) }}.
                </p>
            </header>

            <form class="grid gap-8" @submit.prevent="send">
                <DynamicPatientForm
                    :model-value="answers"
                    :sections="sections"
                    :errors="errors"
                    :disabled="submission.processing"
                />

                <div class="grid gap-3">
                    <Button
                        type="submit"
                        size="lg"
                        class="h-12 w-full font-medium"
                        :disabled="submission.processing"
                    >
                        <Spinner v-if="submission.processing" />
                        Send to {{ clinicName }}
                    </Button>
                    <p
                        class="flex items-center justify-center gap-1.5 text-center text-xs text-muted-foreground"
                    >
                        <Lock class="size-3.5 shrink-0" />
                        Your answers go straight to {{ clinicName }}.
                    </p>
                </div>
            </form>
        </div>

        <!-- Done: nothing left to do -->
        <div
            v-else-if="state === 'submitted'"
            class="animate__animated animate__fadeInUp grid gap-5 py-6 text-center"
            style="--animate-duration: 0.5s"
        >
            <div class="mx-auto size-32">
                <LottieAnimation
                    :animation-data="calendarCheck"
                    :loop="false"
                />
            </div>
            <h1 class="font-display text-3xl font-normal text-balance">
                Thank you{{ firstName ? `, ${firstName}` : '' }}
            </h1>
            <p class="text-sm leading-relaxed text-muted-foreground">
                {{ clinicName }} has your answers and will take it from here.
                There is nothing else to fill in — you can close this page.
            </p>
        </div>

        <!-- Expired, withdrawn or unreadable: say so kindly -->
        <div v-else class="grid gap-5 py-6 text-center">
            <div
                class="mx-auto flex size-14 items-center justify-center rounded-full bg-(--surface-sunken)"
            >
                <Clock
                    v-if="state === 'expired'"
                    class="size-6 text-muted-foreground"
                />
                <ShieldOff
                    v-else-if="state === 'revoked'"
                    class="size-6 text-muted-foreground"
                />
                <Link2Off v-else class="size-6 text-muted-foreground" />
            </div>
            <h1 class="font-display text-3xl font-normal text-balance">
                {{ closedCopy.title }}
            </h1>
            <p class="text-sm leading-relaxed text-muted-foreground">
                {{ closedCopy.description }} Nothing has gone wrong on your
                side. Ask the clinic for a new link and it will open straight
                away.
            </p>
        </div>
    </div>
</template>
