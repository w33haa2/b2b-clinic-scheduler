<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ArrowRight, Building2 } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import { home } from '@/routes/admin/clinics';
import type { Clinic, Wrapped } from '@/types';

defineProps<{ clinics: Wrapped<Clinic[]> }>();

setLayoutProps({ breadcrumbs: [{ title: 'My clinics', href: dashboard() }] });
</script>

<template>
    <Head title="My clinics" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="My clinics"
            description="Choose where you are working today."
        />

        <div
            v-if="clinics.data.length"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <Link
                v-for="(clinic, position) in clinics.data"
                :key="clinic.id"
                :href="home(clinic.id)"
                class="animate__animated animate__fadeInUp group"
                :style="{
                    animationDelay: `${position * 80}ms`,
                    '--animate-duration': '0.5s',
                }"
            >
                <Card
                    class="h-full transition-all group-hover:-translate-y-0.5 group-hover:shadow-lg"
                >
                    <CardHeader>
                        <div
                            class="mb-2 flex size-10 items-center justify-center rounded-lg bg-teal-600 text-white"
                        >
                            <Building2 class="size-5" />
                        </div>
                        <CardTitle class="flex items-center justify-between">
                            {{ clinic.name }}
                            <ArrowRight
                                class="size-4 opacity-0 transition-opacity group-hover:opacity-100"
                            />
                        </CardTitle>
                        <CardDescription>{{
                            clinic.address ?? clinic.timezone
                        }}</CardDescription>
                    </CardHeader>
                </Card>
            </Link>
        </div>

        <Card v-else>
            <EmptyState
                title="You are not part of a clinic yet"
                description="Ask your clinic's admin to add you as a staff member, then sign in again."
            />
        </Card>
    </div>
</template>
