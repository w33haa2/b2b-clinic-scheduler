<script setup lang="ts">
import { ChevronDown, Globe, ShieldCheck, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { useInitials } from '@/composables/useInitials';
import { fieldLabel, formatValue, isDelta, isRedacted } from '@/lib/activity';
import type { ActivityLog } from '@/types';

type Props = {
    logs: ActivityLog[] | null;
    loading: boolean;
    /** Internal tool: show which clinic each entry belongs to. */
    showClinic?: boolean;
    /** Clinic view: name super admins as the support team, not individuals. */
    hideSupportNames?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    showClinic: false,
    hideSupportNames: false,
});
const { getInitials } = useInitials();
const expanded = ref<number[]>([]);

const dayFormatter = new Intl.DateTimeFormat(undefined, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

function dayLabel(iso: string): string {
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) {
        return 'Today';
    }

    if (date.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return dayFormatter.format(date);
}

/** Entries grouped under a heading per day, newest first. */
const days = computed(() => {
    const groups = new Map<string, ActivityLog[]>();

    for (const log of props.logs ?? []) {
        const key = new Date(log.created_at).toDateString();
        groups.set(key, [...(groups.get(key) ?? []), log]);
    }

    return [...groups.values()].map((logs) => ({
        label: dayLabel(logs[0].created_at),
        logs,
    }));
});

function actorName(log: ActivityLog): string {
    if (!log.actor) {
        // Failed sign-ins come from someone who is not signed in.
        return log.action.startsWith('auth.') ? 'Unknown visitor' : 'System';
    }

    return props.hideSupportNames && log.actor.is_super_admin
        ? 'Clinic App support'
        : log.actor.name;
}

function toggle(id: number): void {
    expanded.value = expanded.value.includes(id)
        ? expanded.value.filter((open) => open !== id)
        : [...expanded.value, id];
}

const time = (iso: string) =>
    new Date(iso).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
const fullTime = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        dateStyle: 'full',
        timeStyle: 'medium',
    });
</script>

<template>
    <div v-if="loading && !logs" class="space-y-3 p-4">
        <Skeleton v-for="row in 6" :key="row" class="h-12" />
    </div>

    <div v-else class="divide-y">
        <section v-for="day in days" :key="day.label">
            <h3
                class="sticky top-0 z-10 border-b bg-muted/60 px-4 py-2 text-xs font-medium text-muted-foreground backdrop-blur"
            >
                {{ day.label }}
            </h3>
            <ol class="divide-y">
                <li v-for="log in day.logs" :key="log.id" class="px-4 py-3">
                    <div class="flex items-start gap-3">
                        <Avatar class="mt-0.5 size-8">
                            <AvatarFallback
                                :class="
                                    !log.actor
                                        ? 'bg-muted'
                                        : log.actor.is_super_admin
                                          ? 'bg-teal-100 text-teal-800 dark:bg-teal-500/20 dark:text-teal-200'
                                          : 'text-xs'
                                "
                            >
                                <Sparkles v-if="!log.actor" class="size-3.5" />
                                <ShieldCheck
                                    v-else-if="log.actor.is_super_admin"
                                    class="size-3.5"
                                />
                                <template v-else>{{
                                    getInitials(log.actor.name)
                                }}</template>
                            </AvatarFallback>
                        </Avatar>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm">
                                <span class="font-medium">{{
                                    actorName(log)
                                }}</span>
                                <span class="text-muted-foreground"> · </span>
                                <span>{{ log.description }}</span>
                            </p>
                            <div
                                class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                            >
                                <time
                                    :datetime="log.created_at"
                                    :title="fullTime(log.created_at)"
                                    class="tabular-nums"
                                >
                                    {{ time(log.created_at) }}
                                </time>
                                <Badge
                                    v-if="log.category"
                                    variant="secondary"
                                    class="font-normal"
                                >
                                    {{ log.category.label }}
                                </Badge>
                                <span
                                    v-if="showClinic"
                                    class="flex items-center gap-1"
                                >
                                    <Globe v-if="!log.clinic" class="size-3" />
                                    {{ log.clinic?.name ?? 'Platform' }}
                                </span>
                                <span
                                    v-if="log.ip_address"
                                    class="tabular-nums"
                                    >{{ log.ip_address }}</span
                                >
                                <button
                                    v-if="Object.keys(log.changes).length"
                                    type="button"
                                    class="flex items-center gap-0.5 text-foreground hover:underline"
                                    :aria-expanded="expanded.includes(log.id)"
                                    @click="toggle(log.id)"
                                >
                                    {{ Object.keys(log.changes).length }}
                                    {{
                                        Object.keys(log.changes).length === 1
                                            ? 'change'
                                            : 'changes'
                                    }}
                                    <ChevronDown
                                        class="size-3 transition-transform"
                                        :class="
                                            expanded.includes(log.id) &&
                                            'rotate-180'
                                        "
                                    />
                                </button>
                            </div>

                            <dl
                                v-if="expanded.includes(log.id)"
                                class="animate__animated animate__fadeIn mt-2 grid gap-x-4 gap-y-1 rounded-md border bg-muted/30 p-3 text-xs sm:grid-cols-[auto_1fr]"
                                style="--animate-duration: 0.2s"
                            >
                                <template
                                    v-for="(change, field) in log.changes"
                                    :key="field"
                                >
                                    <dt class="font-medium">
                                        {{ fieldLabel(String(field)) }}
                                    </dt>
                                    <dd class="text-muted-foreground">
                                        <template v-if="isRedacted(change)"
                                            >Changed (hidden for
                                            privacy)</template
                                        >
                                        <template
                                            v-else-if="isDelta(String(field))"
                                        >
                                            <span
                                                v-if="
                                                    formatValue(change.new) !==
                                                    'none'
                                                "
                                                >Added:
                                                {{
                                                    formatValue(change.new)
                                                }}</span
                                            >
                                            <span
                                                v-if="
                                                    formatValue(change.old) !==
                                                    'none'
                                                "
                                                class="block"
                                                >Removed:
                                                {{
                                                    formatValue(change.old)
                                                }}</span
                                            >
                                        </template>
                                        <template v-else>
                                            <span
                                                class="line-through decoration-muted-foreground/50"
                                                >{{
                                                    formatValue(change.old)
                                                }}</span
                                            >
                                            →
                                            <span class="text-foreground">{{
                                                formatValue(change.new)
                                            }}</span>
                                        </template>
                                    </dd>
                                </template>
                            </dl>
                        </div>
                    </div>
                </li>
            </ol>
        </section>
    </div>
</template>
