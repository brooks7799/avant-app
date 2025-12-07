<script setup lang="ts">
import { ref, watch, nextTick } from 'vue';
import {
    Info,
    CheckCircle2,
    AlertTriangle,
    XCircle,
} from 'lucide-vue-next';

interface LogEntry {
    timestamp: string;
    message: string;
    type: 'info' | 'success' | 'warning' | 'error';
    data?: Record<string, unknown>;
}

interface Props {
    entries: LogEntry[];
    autoScroll?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    autoScroll: true,
});

const logContainer = ref<HTMLElement | null>(null);

watch(
    () => props.entries.length,
    async () => {
        if (props.autoScroll && logContainer.value) {
            await nextTick();
            logContainer.value.scrollTop = logContainer.value.scrollHeight;
        }
    }
);

function getIcon(type: string) {
    switch (type) {
        case 'success':
            return CheckCircle2;
        case 'warning':
            return AlertTriangle;
        case 'error':
            return XCircle;
        case 'info':
        default:
            return Info;
    }
}

function getIconColor(type: string) {
    switch (type) {
        case 'success':
            return 'text-green-500';
        case 'warning':
            return 'text-yellow-500';
        case 'error':
            return 'text-red-500';
        case 'info':
        default:
            return 'text-blue-500';
    }
}

function getTextColor(type: string) {
    switch (type) {
        case 'success':
            return 'text-green-700 dark:text-green-400';
        case 'warning':
            return 'text-yellow-700 dark:text-yellow-400';
        case 'error':
            return 'text-red-700 dark:text-red-400';
        case 'info':
        default:
            return 'text-foreground';
    }
}

function formatRelativeTime(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diff = now.getTime() - date.getTime();

    const seconds = Math.floor(diff / 1000);
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);

    if (seconds < 5) return 'just now';
    if (seconds < 60) return `${seconds}s ago`;
    if (minutes < 60) return `${minutes}m ago`;
    if (hours < 24) return `${hours}h ago`;
    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <div
        ref="logContainer"
        class="max-h-96 overflow-y-auto rounded-lg border bg-muted/30 font-mono text-sm"
    >
        <div v-if="entries.length === 0" class="py-8 text-center text-muted-foreground">
            No log entries yet...
        </div>
        <div v-else class="space-y-0.5 p-2">
            <div
                v-for="(entry, index) in entries"
                :key="index"
                class="flex items-start gap-2 rounded px-2 py-1 hover:bg-muted/50"
            >
                <component
                    :is="getIcon(entry.type)"
                    class="mt-0.5 h-4 w-4 flex-shrink-0"
                    :class="getIconColor(entry.type)"
                />
                <div class="min-w-0 flex-1">
                    <span :class="getTextColor(entry.type)">
                        {{ entry.message }}
                    </span>
                    <span
                        v-if="entry.data?.url"
                        class="ml-1 text-muted-foreground"
                    >
                        (<a
                            :href="entry.data.url as string"
                            target="_blank"
                            class="text-blue-500 hover:underline"
                        >link</a>)
                    </span>
                </div>
                <span class="flex-shrink-0 text-xs text-muted-foreground">
                    {{ formatRelativeTime(entry.timestamp) }}
                </span>
            </div>
        </div>
    </div>
</template>
