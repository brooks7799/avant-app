<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Clock,
    XCircle,
    Loader2,
    FileText,
    ExternalLink,
    Building2,
    ArrowLeft,
    RefreshCw,
    Hash,
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

interface VersionPreview {
    id: number;
    content_preview: string;
    word_count: number;
    content_hash: string;
}

interface Job {
    id: number;
    document_id: number;
    document_type: string | null;
    document_url: string | null;
    company_name: string | null;
    company_id: number | null;
    status: string;
    http_status: number | null;
    content_changed: boolean;
    created_version_id: number | null;
    progress_log: LogEntry[];
    error_message: string | null;
    started_at: string | null;
    completed_at: string | null;
    duration_ms: number | null;
    created_at: string;
    version_preview: VersionPreview | null;
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
    { title: `Scrape #${job.value.id}`, href: `/queue/scrape/${job.value.id}` },
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
            const response = await fetch(`/queue/scrape/${job.value.id}/status`);
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

function formatHttpStatus(status: number | null): string {
    if (!status) return '-';
    const statusTexts: Record<number, string> = {
        200: 'OK',
        201: 'Created',
        301: 'Moved Permanently',
        302: 'Found',
        400: 'Bad Request',
        401: 'Unauthorized',
        403: 'Forbidden',
        404: 'Not Found',
        500: 'Server Error',
        502: 'Bad Gateway',
        503: 'Service Unavailable',
    };
    return `${status} ${statusTexts[status] || ''}`;
}

function getHttpStatusColor(status: number | null): string {
    if (!status) return '';
    if (status >= 200 && status < 300) return 'text-green-600';
    if (status >= 300 && status < 400) return 'text-yellow-600';
    return 'text-red-600';
}
</script>

<template>
    <Head :title="`Scrape Job #${job.id}`" />

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
                        <h1 class="text-2xl font-semibold">Scrape Job #{{ job.id }}</h1>
                        <p class="text-sm text-muted-foreground">
                            <span v-if="job.company_name">{{ job.company_name }} &middot;</span>
                            {{ job.document_type || 'Document' }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Badge
                        v-if="job.content_changed"
                        class="bg-blue-100 text-blue-800"
                    >
                        <RefreshCw class="mr-1 h-3 w-3" />
                        Content Changed
                    </Badge>
                    <Badge :variant="getStatusBadgeVariant(job.status)" class="text-sm">
                        {{ job.status }}
                    </Badge>
                </div>
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
                        <CardTitle class="text-sm font-medium text-muted-foreground">HTTP Status</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold" :class="getHttpStatusColor(job.http_status)">
                            {{ formatHttpStatus(job.http_status) }}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Word Count</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{ job.version_preview?.word_count ?? '-' }}
                        </div>
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
                    <CardDescription>Real-time progress updates from the scrape job</CardDescription>
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

            <!-- Content Preview -->
            <Card v-if="job.version_preview">
                <CardHeader>
                    <CardTitle>Content Preview</CardTitle>
                    <CardDescription>
                        First 500 characters of extracted content
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="rounded-lg bg-muted/50 p-4">
                        <p class="text-sm whitespace-pre-wrap">{{ job.version_preview.content_preview }}</p>
                    </div>
                    <div class="flex items-center gap-4 text-sm text-muted-foreground">
                        <div class="flex items-center gap-1">
                            <Hash class="h-4 w-4" />
                            <span class="font-mono text-xs">{{ job.version_preview.content_hash.substring(0, 16) }}...</span>
                        </div>
                        <div>
                            {{ job.version_preview.word_count.toLocaleString() }} words
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
                    v-if="job.document_url"
                    :href="job.document_url"
                    target="_blank"
                >
                    <Button variant="outline" class="gap-2">
                        <ExternalLink class="h-4 w-4" />
                        View Original Document
                    </Button>
                </a>
                <Link
                    v-if="job.document_id"
                    :href="`/documents/${job.document_id}`"
                >
                    <Button variant="outline" class="gap-2">
                        <FileText class="h-4 w-4" />
                        View Document
                    </Button>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
