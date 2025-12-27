<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
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
    Code,
    Server,
    Bug,
    ChevronDown,
    ChevronRight,
    RotateCcw,
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
    user_agent: string | null;
    request_headers: Record<string, string> | null;
    response_headers: Record<string, string[]> | null;
    raw_html_size: number | null;
    raw_html_preview: string | null;
    extracted_html_size: number | null;
    extracted_html_preview: string | null;
    version_preview: VersionPreview | null;
}

interface Props {
    job: Job;
}

const props = defineProps<Props>();

const job = ref<Job>(props.job);
const isRetrying = ref(false);
const showDebugPanel = ref(false);
const activeDebugTab = ref<'request' | 'response' | 'raw' | 'extracted'>('response');
let pollInterval: number | null = null;

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Queue Manager', href: '/queue' },
    { title: `Retrieval #${job.value.id}`, href: `/queue/scrape/${job.value.id}` },
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
    if (ms === null || ms === undefined) return '-';
    const absMs = Math.abs(ms);
    if (absMs < 1000) return `${absMs}ms`;
    const seconds = Math.floor(absMs / 1000);
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

function retryJob() {
    if (isRetrying.value) return;
    isRetrying.value = true;
    router.post(`/queue/scrape/${job.value.id}/retry`, {}, {
        onSuccess: () => {
            // Redirect is handled by Inertia
        },
        onError: () => {
            isRetrying.value = false;
        },
        onFinish: () => {
            // Don't reset isRetrying here - we're navigating away
        },
    });
}

function formatBytes(bytes: number | null): string {
    if (!bytes) return '-';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function formatHeaderValue(value: string | string[]): string {
    return Array.isArray(value) ? value.join(', ') : value;
}
</script>

<template>
    <Head :title="`Retrieval Job #${job.id}`" />

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
                        <h1 class="text-2xl font-semibold">Retrieval Job #{{ job.id }}</h1>
                        <p class="text-sm text-muted-foreground">
                            <span v-if="job.company_name">{{ job.company_name }} &middot;</span>
                            {{ job.document_type || 'Document' }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="job.status === 'failed'"
                        variant="default"
                        @click="retryJob"
                        :disabled="isRetrying"
                        class="cursor-pointer"
                    >
                        <RotateCcw v-if="!isRetrying" class="mr-2 h-4 w-4" />
                        <Loader2 v-else class="mr-2 h-4 w-4 animate-spin" />
                        {{ isRetrying ? 'Retrying...' : 'Retry' }}
                    </Button>
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
                    <CardDescription>Real-time progress updates from the retrieval job</CardDescription>
                </CardHeader>
                <CardContent>
                    <ProgressLog :entries="job.progress_log" />
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

            <!-- Debug Panel -->
            <Card v-if="job.raw_html_size || job.response_headers">
                <CardHeader class="cursor-pointer" @click="showDebugPanel = !showDebugPanel">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <Bug class="h-5 w-5 text-muted-foreground" />
                            <CardTitle>Debug Information</CardTitle>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="flex gap-3 text-sm text-muted-foreground">
                                <span v-if="job.raw_html_size">
                                    Raw: {{ formatBytes(job.raw_html_size) }}
                                </span>
                                <span v-if="job.extracted_html_size">
                                    Extracted: {{ formatBytes(job.extracted_html_size) }}
                                </span>
                            </div>
                            <ChevronDown v-if="showDebugPanel" class="h-5 w-5 text-muted-foreground" />
                            <ChevronRight v-else class="h-5 w-5 text-muted-foreground" />
                        </div>
                    </div>
                    <CardDescription>HTTP request/response details and fetched content</CardDescription>
                </CardHeader>
                <CardContent v-if="showDebugPanel" class="space-y-4">
                    <!-- Tab Buttons -->
                    <div class="flex gap-2 border-b">
                        <button
                            :class="[
                                'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                activeDebugTab === 'response'
                                    ? 'border-b-2 border-primary text-primary'
                                    : 'text-muted-foreground hover:text-foreground'
                            ]"
                            @click="activeDebugTab = 'response'"
                        >
                            <Server class="mr-2 h-4 w-4" />
                            Response Headers
                        </button>
                        <button
                            :class="[
                                'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                activeDebugTab === 'request'
                                    ? 'border-b-2 border-primary text-primary'
                                    : 'text-muted-foreground hover:text-foreground'
                            ]"
                            @click="activeDebugTab = 'request'"
                        >
                            <ExternalLink class="mr-2 h-4 w-4" />
                            Request Headers
                        </button>
                        <button
                            :class="[
                                'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                activeDebugTab === 'raw'
                                    ? 'border-b-2 border-primary text-primary'
                                    : 'text-muted-foreground hover:text-foreground',
                                !job.raw_html_preview ? 'opacity-50' : ''
                            ]"
                            :disabled="!job.raw_html_preview"
                            @click="job.raw_html_preview && (activeDebugTab = 'raw')"
                        >
                            <Code class="mr-2 h-4 w-4" />
                            Raw HTML
                            <Badge v-if="job.raw_html_size" variant="outline" class="ml-2">
                                {{ formatBytes(job.raw_html_size) }}
                            </Badge>
                        </button>
                        <button
                            :class="[
                                'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                activeDebugTab === 'extracted'
                                    ? 'border-b-2 border-primary text-primary'
                                    : 'text-muted-foreground hover:text-foreground',
                                !job.extracted_html_preview ? 'opacity-50' : ''
                            ]"
                            :disabled="!job.extracted_html_preview"
                            @click="job.extracted_html_preview && (activeDebugTab = 'extracted')"
                        >
                            <FileText class="mr-2 h-4 w-4" />
                            Extracted HTML
                            <Badge v-if="job.extracted_html_size" variant="outline" class="ml-2">
                                {{ formatBytes(job.extracted_html_size) }}
                            </Badge>
                        </button>
                    </div>

                    <!-- Response Headers -->
                    <div v-if="activeDebugTab === 'response'" class="space-y-4">
                        <div v-if="job.response_headers" class="rounded-lg border bg-muted/30 p-4 overflow-auto max-h-[400px]">
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-for="(value, key) in job.response_headers" :key="key" class="border-b last:border-0">
                                        <td class="py-2 pr-4 font-mono text-muted-foreground whitespace-nowrap align-top">{{ key }}</td>
                                        <td class="py-2 font-mono break-all">{{ formatHeaderValue(value) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">No response headers available</p>
                    </div>

                    <!-- Request Headers -->
                    <div v-if="activeDebugTab === 'request'" class="space-y-4">
                        <div class="rounded-lg border bg-muted/30 p-4">
                            <div class="space-y-2 text-sm">
                                <div class="flex">
                                    <span class="font-mono text-muted-foreground w-32">URL:</span>
                                    <span class="font-mono break-all">{{ job.document_url }}</span>
                                </div>
                                <div class="flex">
                                    <span class="font-mono text-muted-foreground w-32">User-Agent:</span>
                                    <span class="font-mono break-all">{{ job.user_agent || 'Not set' }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-if="job.request_headers" class="rounded-lg border bg-muted/30 p-4 overflow-auto max-h-[400px]">
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-for="(value, key) in job.request_headers" :key="key" class="border-b last:border-0">
                                        <td class="py-2 pr-4 font-mono text-muted-foreground whitespace-nowrap align-top">{{ key }}</td>
                                        <td class="py-2 font-mono break-all">{{ value }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Raw HTML Preview -->
                    <div v-if="activeDebugTab === 'raw'" class="space-y-2">
                        <div class="flex items-center justify-between text-sm text-muted-foreground">
                            <span>Showing first 2,000 characters of {{ formatBytes(job.raw_html_size) }}</span>
                        </div>
                        <div class="rounded-lg border bg-muted/30 p-4 overflow-auto max-h-[500px]">
                            <pre class="text-xs font-mono whitespace-pre-wrap break-all">{{ job.raw_html_preview }}</pre>
                        </div>
                    </div>

                    <!-- Extracted HTML Preview -->
                    <div v-if="activeDebugTab === 'extracted'" class="space-y-2">
                        <div class="flex items-center justify-between text-sm text-muted-foreground">
                            <span>Showing first 2,000 characters of {{ formatBytes(job.extracted_html_size) }}</span>
                        </div>
                        <div class="rounded-lg border bg-muted/30 p-4 overflow-auto max-h-[500px]">
                            <pre class="text-xs font-mono whitespace-pre-wrap break-all">{{ job.extracted_html_preview }}</pre>
                        </div>
                    </div>
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
