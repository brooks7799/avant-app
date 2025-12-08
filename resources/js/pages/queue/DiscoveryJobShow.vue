<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Clock,
    XCircle,
    Loader2,
    Globe,
    ExternalLink,
    Building2,
    ArrowLeft,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import ProgressLog from '@/components/ProgressLog.vue';
import { ref, onMounted, onUnmounted, computed } from 'vue';

interface LogEntry {
    timestamp: string;
    message: string;
    type: 'info' | 'success' | 'warning' | 'error';
    data?: Record<string, unknown>;
}

interface DiscoveredUrl {
    url: string;
    detected_type: string | null;
    document_type_id: number | null;
    confidence: number;
    discovery_method: string;
    link_text?: string;
}

interface Job {
    id: number;
    website_id: number;
    website_url: string | null;
    company_name: string | null;
    company_id: number | null;
    status: string;
    urls_crawled: number;
    policies_found: number;
    discovered_urls: DiscoveredUrl[] | null;
    progress_log: LogEntry[];
    error_message: string | null;
    started_at: string | null;
    completed_at: string | null;
    duration_ms: number | null;
    created_at: string;
}

interface Props {
    job: Job;
}

const props = defineProps<Props>();

const job = ref<Job>(props.job);
let pollInterval: number | null = null;

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Queue Manager', href: '/queue' },
    { title: `Discovery #${job.value.id}`, href: `/queue/discovery/${job.value.id}` },
]);

const isRunning = computed(() => ['pending', 'running'].includes(job.value.status));

onMounted(() => {
    if (isRunning.value) {
        startPolling();
    }
});

onUnmounted(() => {
    stopPolling();
});

function startPolling() {
    pollInterval = window.setInterval(async () => {
        try {
            const response = await fetch(`/queue/discovery/${job.value.id}/status`);
            if (response.ok) {
                const data = await response.json();
                job.value = data;

                // Stop polling if job is done
                if (!['pending', 'running'].includes(data.status)) {
                    stopPolling();
                }
            }
        } catch (e) {
            console.error('Failed to fetch job status', e);
        }
    }, 1500);
}

function stopPolling() {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

function getStatusIcon(status: string) {
    switch (status) {
        case 'completed':
            return CheckCircle2;
        case 'failed':
            return XCircle;
        case 'running':
            return Loader2;
        case 'pending':
        default:
            return Clock;
    }
}

function getStatusColor(status: string) {
    switch (status) {
        case 'completed':
            return 'text-green-500';
        case 'failed':
            return 'text-red-500';
        case 'running':
            return 'text-blue-500';
        case 'pending':
        default:
            return 'text-yellow-500';
    }
}

function getStatusBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'completed':
            return 'default';
        case 'failed':
            return 'destructive';
        case 'running':
            return 'secondary';
        default:
            return 'outline';
    }
}

function formatDuration(ms: number | null): string {
    if (!ms) return '-';
    if (ms < 1000) return `${ms}ms`;
    const seconds = Math.floor(ms / 1000);
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    return `${minutes}m ${remainingSeconds}s`;
}

function formatDocumentType(type: string | null): string {
    if (!type) return 'Unknown';
    const smallWords = ['of', 'the', 'a', 'an', 'and', 'or', 'for', 'to', 'in', 'on'];
    return type
        .split('-')
        .map((word, index) => {
            if (index > 0 && smallWords.includes(word.toLowerCase())) {
                return word.toLowerCase();
            }
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        })
        .join(' ');
}
</script>

<template>
    <Head :title="`Discovery Job #${job.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Back Link -->
            <div>
                <Link href="/queue" class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft class="h-4 w-4" />
                    Back to Queue
                </Link>
            </div>

            <!-- Header -->
            <div class="flex items-start justify-between">
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-full"
                        :class="{
                            'bg-green-100 dark:bg-green-900': job.status === 'completed',
                            'bg-red-100 dark:bg-red-900': job.status === 'failed',
                            'bg-blue-100 dark:bg-blue-900': job.status === 'running',
                            'bg-yellow-100 dark:bg-yellow-900': job.status === 'pending',
                        }"
                    >
                        <component
                            :is="getStatusIcon(job.status)"
                            class="h-6 w-6"
                            :class="[
                                getStatusColor(job.status),
                                job.status === 'running' ? 'animate-spin' : ''
                            ]"
                        />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold">Discovery Job #{{ job.id }}</h1>
                        <p class="text-sm text-muted-foreground">
                            <span v-if="job.company_name">{{ job.company_name }} &middot;</span>
                            {{ job.website_url }}
                        </p>
                    </div>
                </div>
                <Badge :variant="getStatusBadgeVariant(job.status)" class="text-sm">
                    {{ job.status }}
                </Badge>
            </div>

            <!-- Stats -->
            <div class="grid gap-4 md:grid-cols-4">
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Status</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold capitalize">{{ job.status }}</div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Duration</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ formatDuration(job.duration_ms) }}</div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">URLs Crawled</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ job.urls_crawled ?? 0 }}</div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Policies Found</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold text-green-600">{{ job.policies_found ?? 0 }}</div>
                    </CardContent>
                </Card>
            </div>

            <!-- Progress Log -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        Progress Log
                        <Loader2 v-if="isRunning" class="h-4 w-4 animate-spin text-muted-foreground" />
                    </CardTitle>
                    <CardDescription>Real-time progress updates from the discovery job</CardDescription>
                </CardHeader>
                <CardContent>
                    <ProgressLog :entries="job.progress_log" :auto-scroll="true" />
                </CardContent>
            </Card>

            <!-- Error Message -->
            <Card v-if="job.error_message" class="border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30">
                <CardHeader>
                    <CardTitle class="text-red-700 dark:text-red-400">Error</CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-red-700 dark:text-red-400">{{ job.error_message }}</p>
                </CardContent>
            </Card>

            <!-- Discovered URLs -->
            <Card v-if="job.discovered_urls && job.discovered_urls.length > 0">
                <CardHeader>
                    <CardTitle>Discovered Policies</CardTitle>
                    <CardDescription>{{ job.discovered_urls.length }} policy document(s) found</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-3">
                        <div
                            v-for="(policy, index) in job.discovered_urls"
                            :key="index"
                            class="flex items-center justify-between rounded-lg border p-3"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <Globe class="h-5 w-5 flex-shrink-0 text-muted-foreground" />
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <Badge variant="outline">
                                            {{ formatDocumentType(policy.detected_type) }}
                                        </Badge>
                                        <span class="text-xs text-muted-foreground capitalize">
                                            via {{ policy.discovery_method.replace('_', ' ') }}
                                        </span>
                                    </div>
                                    <a
                                        :href="policy.url"
                                        target="_blank"
                                        class="text-sm text-blue-600 hover:underline truncate block"
                                    >
                                        {{ policy.url }}
                                    </a>
                                    <p v-if="policy.link_text" class="text-xs text-muted-foreground">
                                        Link text: "{{ policy.link_text }}"
                                    </p>
                                </div>
                            </div>
                            <a
                                :href="policy.url"
                                target="_blank"
                                class="flex-shrink-0"
                            >
                                <Button variant="ghost" size="sm">
                                    <ExternalLink class="h-4 w-4" />
                                </Button>
                            </a>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Links -->
            <div class="flex gap-3">
                <Link
                    v-if="job.company_id"
                    :href="`/companies/${job.company_id}`"
                >
                    <Button variant="outline" class="gap-2">
                        <Building2 class="h-4 w-4" />
                        View Company
                    </Button>
                </Link>
                <a
                    v-if="job.website_url"
                    :href="job.website_url"
                    target="_blank"
                >
                    <Button variant="outline" class="gap-2">
                        <ExternalLink class="h-4 w-4" />
                        Visit Website
                    </Button>
                </a>
            </div>
        </div>
    </AppLayout>
</template>
