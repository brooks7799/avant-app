<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, Link } from '@inertiajs/vue3';
import {
    Play,
    Square,
    RefreshCw,
    Trash2,
    CheckCircle2,
    Clock,
    XCircle,
    Loader2,
    Search,
    FileText,
    Globe,
    Activity,
    BarChart3,
    Power,
    Eye,
    Brain,
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ref, computed, onMounted, onUnmounted } from 'vue';

interface ScrapeJob {
    id: number;
    document_id: number;
    document_type: string | null;
    company_name: string | null;
    document_url: string | null;
    status: string;
    content_changed: boolean;
    error_message: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
}

interface DiscoveryJob {
    id: number;
    website_id: number;
    website_url: string | null;
    company_name: string | null;
    status: string;
    urls_crawled: number;
    policies_found: number;
    error_message: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
}

interface AnalysisJob {
    id: number;
    document_version_id: number;
    document_id: number | null;
    document_type: string | null;
    company_name: string | null;
    analysis_type: string;
    status: string;
    model_used: string | null;
    tokens_used: number | null;
    analysis_cost: number | null;
    error_message: string | null;
    started_at: string | null;
    completed_at: string | null;
    duration_ms: number | null;
    created_at: string;
}

interface Stats {
    pending_jobs: number;
    pending_analysis_jobs: number;
    failed_jobs: number;
    total_documents: number;
    monitored_documents: number;
    documents_due: number;
    total_websites: number;
    websites_pending_discovery: number;
    scrape_jobs_today: number;
    scrape_jobs_success_today: number;
    scrape_jobs_failed_today: number;
    changes_detected_today: number;
    ai_analyses_total: number;
    ai_analyses_today: number;
}

interface WorkerStatus {
    running: boolean;
    pid: number | null;
    uptime: string | null;
}

interface Props {
    stats: Stats;
    recentScrapeJobs: ScrapeJob[];
    recentDiscoveryJobs: DiscoveryJob[];
    recentAnalysisJobs: AnalysisJob[];
    workerStatus: WorkerStatus;
}

const props = defineProps<Props>();

const showStopDialog = ref(false);
const showClearDialog = ref(false);
const isRefreshing = ref(false);
const isWorkerActionPending = ref(false);
const activeTab = ref<'scrape' | 'discovery' | 'analysis'>('scrape');
const displayUptime = ref(props.workerStatus.uptime);

// Filter state
const statusFilter = ref<'all' | 'completed' | 'failed' | 'pending' | 'running'>('all');
const timeFilter = ref<'all' | 'today' | 'week'>('all');

// Helper to check if a date is today
function isToday(dateString: string): boolean {
    const date = new Date(dateString);
    const today = new Date();
    return date.toDateString() === today.toDateString();
}

// Helper to check if a date is within the last week
function isThisWeek(dateString: string): boolean {
    const date = new Date(dateString);
    const now = new Date();
    const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
    return date >= weekAgo;
}

// Generic filter function
function filterJobs<T extends { status: string; created_at: string }>(jobs: T[]): T[] {
    return jobs.filter(job => {
        // Status filter
        if (statusFilter.value !== 'all' && job.status !== statusFilter.value) {
            return false;
        }
        // Time filter
        if (timeFilter.value === 'today' && !isToday(job.created_at)) {
            return false;
        }
        if (timeFilter.value === 'week' && !isThisWeek(job.created_at)) {
            return false;
        }
        return true;
    });
}

// Filtered job lists
const filteredScrapeJobs = computed(() => filterJobs(props.recentScrapeJobs));
const filteredDiscoveryJobs = computed(() => filterJobs(props.recentDiscoveryJobs));
const filteredAnalysisJobs = computed(() => filterJobs(props.recentAnalysisJobs));

// Get counts for filter badges
const getStatusCounts = computed(() => {
    const jobs = activeTab.value === 'scrape'
        ? props.recentScrapeJobs
        : activeTab.value === 'discovery'
            ? props.recentDiscoveryJobs
            : props.recentAnalysisJobs;

    return {
        all: jobs.length,
        completed: jobs.filter(j => j.status === 'completed').length,
        failed: jobs.filter(j => j.status === 'failed').length,
        pending: jobs.filter(j => j.status === 'pending').length,
        running: jobs.filter(j => j.status === 'running').length,
    };
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Queue Manager', href: '/queue' },
];

let refreshInterval: number | null = null;
let uptimeInterval: number | null = null;
let uptimeSeconds = 0;

// Parse uptime string like "01:23" or "1-02:03:04" into seconds
function parseUptimeToSeconds(uptime: string | null): number {
    if (!uptime) return 0;

    // Format can be: SS, MM:SS, HH:MM:SS, or D-HH:MM:SS
    const dayMatch = uptime.match(/^(\d+)-(.+)$/);
    let days = 0;
    let timeStr = uptime;

    if (dayMatch) {
        days = parseInt(dayMatch[1], 10);
        timeStr = dayMatch[2];
    }

    const parts = timeStr.split(':').map(p => parseInt(p, 10));
    let seconds = 0;

    if (parts.length === 1) {
        seconds = parts[0];
    } else if (parts.length === 2) {
        seconds = parts[0] * 60 + parts[1];
    } else if (parts.length === 3) {
        seconds = parts[0] * 3600 + parts[1] * 60 + parts[2];
    }

    return days * 86400 + seconds;
}

// Format seconds back to uptime string
function formatUptime(totalSeconds: number): string {
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    const pad = (n: number) => n.toString().padStart(2, '0');

    if (days > 0) {
        return `${days}-${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
    } else if (hours > 0) {
        return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
    } else {
        return `${pad(minutes)}:${pad(seconds)}`;
    }
}

function tickUptime() {
    if (props.workerStatus.running) {
        uptimeSeconds++;
        displayUptime.value = formatUptime(uptimeSeconds);
    }
}

function syncUptime() {
    uptimeSeconds = parseUptimeToSeconds(props.workerStatus.uptime);
    if (props.workerStatus.running && props.workerStatus.uptime) {
        displayUptime.value = props.workerStatus.uptime;
    } else {
        displayUptime.value = null;
    }
}

onMounted(() => {
    syncUptime();

    refreshInterval = window.setInterval(() => {
        refreshData(true);
    }, 5000);

    // Update uptime display every second
    uptimeInterval = window.setInterval(tickUptime, 1000);
});

onUnmounted(() => {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
    if (uptimeInterval) {
        clearInterval(uptimeInterval);
    }
});

function refreshData(silent = false) {
    if (!silent) {
        isRefreshing.value = true;
    }
    router.reload({
        only: ['stats', 'recentScrapeJobs', 'recentDiscoveryJobs', 'recentAnalysisJobs', 'workerStatus'],
        onFinish: () => {
            isRefreshing.value = false;
            syncUptime();
        },
    });
}

function formatDuration(ms: number | null): string {
    if (!ms) return '-';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${(ms / 60000).toFixed(1)}m`;
}

function formatCost(cost: number | null): string {
    if (!cost) return '-';
    return `$${cost.toFixed(4)}`;
}

function startQueue() {
    isWorkerActionPending.value = true;
    router.post('/queue/worker/start', {}, {
        preserveScroll: true,
        onFinish: () => {
            isWorkerActionPending.value = false;
        },
    });
}

function stopQueue() {
    isWorkerActionPending.value = true;
    router.post('/queue/worker/stop', {}, {
        preserveScroll: true,
        onFinish: () => {
            isWorkerActionPending.value = false;
            showStopDialog.value = false;
        },
    });
}

function retryFailed() {
    router.post('/queue/retry-failed', {}, {
        preserveScroll: true,
    });
}

function flushFailed() {
    router.post('/queue/flush-failed', {}, {
        preserveScroll: true,
    });
}

function clearPending() {
    router.post('/queue/clear-pending', {}, {
        preserveScroll: true,
        onSuccess: () => {
            showClearDialog.value = false;
        },
    });
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

function formatRelativeTime(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diff = now.getTime() - date.getTime();

    const seconds = Math.floor(diff / 1000);
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);

    if (seconds < 60) return `${seconds}s ago`;
    if (minutes < 60) return `${minutes}m ago`;
    if (hours < 24) return `${hours}h ago`;
    return new Date(dateString).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <Head title="Queue Manager" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header with Queue Control -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Queue Manager</h1>
                    <p class="text-sm text-muted-foreground">
                        Start and stop the queue worker, monitor job progress
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    @click="refreshData()"
                    :disabled="isRefreshing"
                >
                    <RefreshCw
                        class="mr-2 h-4 w-4"
                        :class="{ 'animate-spin': isRefreshing }"
                    />
                    Refresh
                </Button>
            </div>

            <!-- Main Queue Control - Start/Stop -->
            <Card
                class="border-2"
                :class="workerStatus.running
                    ? 'border-green-500 bg-green-50 dark:bg-green-950/30'
                    : 'border-muted'"
            >
                <CardContent class="py-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-14 w-14 items-center justify-center rounded-full"
                                :class="workerStatus.running
                                    ? 'bg-green-500 text-white'
                                    : 'bg-muted text-muted-foreground'"
                            >
                                <Power class="h-7 w-7" />
                            </div>
                            <div>
                                <h2 class="text-xl font-semibold">
                                    Queue is {{ workerStatus.running ? 'Running' : 'Stopped' }}
                                </h2>
                                <p class="text-sm text-muted-foreground">
                                    <template v-if="workerStatus.running">
                                        PID: {{ workerStatus.pid }}
                                        <span v-if="displayUptime"> &middot; Uptime: {{ displayUptime }}</span>
                                    </template>
                                    <template v-else>
                                        Click "Start Queue" to begin processing jobs
                                    </template>
                                </p>
                            </div>
                        </div>

                        <!-- Start/Stop Button -->
                        <div>
                            <Button
                                v-if="!workerStatus.running"
                                size="lg"
                                @click="startQueue"
                                :disabled="isWorkerActionPending"
                                class="gap-2 px-8"
                            >
                                <Loader2 v-if="isWorkerActionPending" class="h-5 w-5 animate-spin" />
                                <Play v-else class="h-5 w-5" />
                                Start Queue
                            </Button>

                            <Button
                                v-if="workerStatus.running"
                                size="lg"
                                variant="destructive"
                                @click="showStopDialog = true"
                                :disabled="isWorkerActionPending"
                                class="gap-2 px-8"
                            >
                                <Loader2 v-if="isWorkerActionPending" class="h-5 w-5 animate-spin" />
                                <Square v-else class="h-5 w-5" />
                                Stop Queue
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Stop Queue Dialog -->
            <Dialog v-model:open="showStopDialog">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Stop Queue</DialogTitle>
                        <DialogDescription>
                            This will stop the queue worker. Any job currently being processed may be interrupted.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" @click="showStopDialog = false">
                            Cancel
                        </Button>
                        <Button variant="destructive" @click="stopQueue" :disabled="isWorkerActionPending">
                            <Loader2 v-if="isWorkerActionPending" class="mr-2 h-4 w-4 animate-spin" />
                            Stop Queue
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Stats Cards -->
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Pending Jobs</CardTitle>
                        <Activity class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.pending_jobs }}</div>
                        <p class="text-xs text-muted-foreground">
                            in queue
                            <span v-if="stats.failed_jobs > 0" class="text-red-500">
                                ({{ stats.failed_jobs }} failed)
                            </span>
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Ready to Retrieve</CardTitle>
                        <FileText class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.documents_due }}</div>
                        <p class="text-xs text-muted-foreground">
                            documents need retrieval
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Today's Jobs</CardTitle>
                        <BarChart3 class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            <template v-if="stats.scrape_jobs_today > 0">
                                <span class="text-green-600">{{ stats.scrape_jobs_success_today }}</span>
                                <span class="text-muted-foreground">/</span>
                                <span>{{ stats.scrape_jobs_today }}</span>
                            </template>
                            <template v-else>0</template>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="stats.scrape_jobs_today > 0">
                                {{ stats.changes_detected_today }} changes detected
                            </template>
                            <template v-else>
                                no jobs run today
                            </template>
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Discovery</CardTitle>
                        <Search class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.websites_pending_discovery }}</div>
                        <p class="text-xs text-muted-foreground">
                            websites pending
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- Secondary Actions (only show if there are failed jobs or pending jobs) -->
            <div v-if="stats.failed_jobs > 0 || stats.pending_jobs > 0" class="flex flex-wrap gap-3">
                <Button
                    v-if="stats.failed_jobs > 0"
                    variant="outline"
                    size="sm"
                    @click="retryFailed"
                    class="gap-2"
                >
                    <RefreshCw class="h-4 w-4" />
                    Retry Failed ({{ stats.failed_jobs }})
                </Button>
                <Button
                    v-if="stats.failed_jobs > 0"
                    variant="outline"
                    size="sm"
                    @click="flushFailed"
                    class="gap-2 text-red-600 hover:text-red-700"
                >
                    <Trash2 class="h-4 w-4" />
                    Clear Failed
                </Button>
                <Dialog v-model:open="showClearDialog">
                    <Button
                        v-if="stats.pending_jobs > 0"
                        variant="outline"
                        size="sm"
                        @click="showClearDialog = true"
                        class="gap-2 text-red-600 hover:text-red-700"
                    >
                        <Trash2 class="h-4 w-4" />
                        Clear Pending ({{ stats.pending_jobs }})
                    </Button>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Clear Pending Jobs</DialogTitle>
                            <DialogDescription>
                                This will remove all {{ stats.pending_jobs }} pending jobs from the queue. This cannot be undone.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button variant="outline" @click="showClearDialog = false">
                                Cancel
                            </Button>
                            <Button variant="destructive" @click="clearPending">
                                Clear All
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>

            <!-- Tab Buttons -->
            <div class="flex gap-2 border-b">
                <button
                    :class="[
                        'px-4 py-2 text-sm font-medium transition-colors cursor-pointer',
                        activeTab === 'scrape'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    ]"
                    @click="activeTab = 'scrape'"
                >
                    <FileText class="mr-2 inline h-4 w-4" />
                    Retrieval Jobs
                </button>
                <button
                    :class="[
                        'px-4 py-2 text-sm font-medium transition-colors cursor-pointer',
                        activeTab === 'discovery'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    ]"
                    @click="activeTab = 'discovery'"
                >
                    <Globe class="mr-2 inline h-4 w-4" />
                    Discovery Jobs
                </button>
                <button
                    :class="[
                        'px-4 py-2 text-sm font-medium transition-colors cursor-pointer',
                        activeTab === 'analysis'
                            ? 'border-b-2 border-primary text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    ]"
                    @click="activeTab = 'analysis'"
                >
                    <Brain class="mr-2 inline h-4 w-4" />
                    AI Analysis
                    <Badge v-if="stats.pending_analysis_jobs > 0" variant="secondary" class="ml-2">
                        {{ stats.pending_analysis_jobs }}
                    </Badge>
                </button>
            </div>

            <!-- Filter Bar -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="text-sm text-muted-foreground">Status:</span>
                    <div class="flex gap-1">
                        <Button
                            v-for="status in ['all', 'completed', 'failed', 'pending', 'running'] as const"
                            :key="status"
                            :variant="statusFilter === status ? 'default' : 'outline'"
                            size="sm"
                            @click="statusFilter = status"
                            class="h-7 text-xs"
                        >
                            {{ status === 'all' ? 'All' : status.charAt(0).toUpperCase() + status.slice(1) }}
                            <span
                                v-if="getStatusCounts[status] > 0"
                                class="ml-1 opacity-70"
                            >
                                ({{ getStatusCounts[status] }})
                            </span>
                        </Button>
                    </div>
                </div>

                <div class="h-4 w-px bg-border" />

                <div class="flex items-center gap-2">
                    <span class="text-sm text-muted-foreground">Time:</span>
                    <div class="flex gap-1">
                        <Button
                            v-for="time in [
                                { value: 'all', label: 'All Time' },
                                { value: 'today', label: 'Today' },
                                { value: 'week', label: 'This Week' },
                            ] as const"
                            :key="time.value"
                            :variant="timeFilter === time.value ? 'default' : 'outline'"
                            size="sm"
                            @click="timeFilter = time.value"
                            class="h-7 text-xs"
                        >
                            {{ time.label }}
                        </Button>
                    </div>
                </div>

                <div
                    v-if="statusFilter !== 'all' || timeFilter !== 'all'"
                    class="ml-auto"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="statusFilter = 'all'; timeFilter = 'all'"
                        class="h-7 text-xs text-muted-foreground"
                    >
                        Clear filters
                    </Button>
                </div>
            </div>

            <!-- Recent Retrieval Jobs -->
            <Card v-if="activeTab === 'scrape'">
                <CardHeader>
                    <CardTitle>Recent Retrieval Jobs</CardTitle>
                    <CardDescription>
                        Showing {{ filteredScrapeJobs.length }} of {{ recentScrapeJobs.length }} jobs
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="filteredScrapeJobs.length === 0" class="py-8 text-center text-muted-foreground">
                        <template v-if="recentScrapeJobs.length === 0">
                            No retrieval jobs yet.
                        </template>
                        <template v-else>
                            No jobs match the current filters.
                        </template>
                    </div>
                    <div v-else class="space-y-2">
                        <Link
                            v-for="job in filteredScrapeJobs"
                            :key="job.id"
                            :href="`/queue/scrape/${job.id}`"
                            class="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50 transition-colors cursor-pointer"
                        >
                            <div class="flex items-center gap-3">
                                <component
                                    :is="getStatusIcon(job.status)"
                                    class="h-5 w-5"
                                    :class="[
                                        getStatusColor(job.status),
                                        job.status === 'running' ? 'animate-spin' : ''
                                    ]"
                                />
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{ job.company_name || 'Unknown' }}</span>
                                        <Badge variant="outline" class="text-xs">
                                            {{ job.document_type || 'Document' }}
                                        </Badge>
                                        <Badge
                                            v-if="job.content_changed"
                                            class="text-xs bg-blue-100 text-blue-800"
                                        >
                                            Changed
                                        </Badge>
                                    </div>
                                    <div class="text-xs text-muted-foreground truncate max-w-md">
                                        {{ job.document_url }}
                                    </div>
                                    <div v-if="job.error_message" class="text-xs text-red-500 truncate max-w-md">
                                        {{ job.error_message }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <Badge :variant="getStatusBadgeVariant(job.status)">
                                    {{ job.status }}
                                </Badge>
                                <span class="text-xs text-muted-foreground whitespace-nowrap">
                                    {{ formatRelativeTime(job.created_at) }}
                                </span>
                                <Eye class="h-4 w-4 text-muted-foreground" />
                            </div>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <!-- Recent Discovery Jobs -->
            <Card v-if="activeTab === 'discovery'">
                <CardHeader>
                    <CardTitle>Recent Discovery Jobs</CardTitle>
                    <CardDescription>
                        Showing {{ filteredDiscoveryJobs.length }} of {{ recentDiscoveryJobs.length }} jobs
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="filteredDiscoveryJobs.length === 0" class="py-8 text-center text-muted-foreground">
                        <template v-if="recentDiscoveryJobs.length === 0">
                            No discovery jobs yet.
                        </template>
                        <template v-else>
                            No jobs match the current filters.
                        </template>
                    </div>
                    <div v-else class="space-y-2">
                        <Link
                            v-for="job in filteredDiscoveryJobs"
                            :key="job.id"
                            :href="`/queue/discovery/${job.id}`"
                            class="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50 transition-colors cursor-pointer"
                        >
                            <div class="flex items-center gap-3">
                                <component
                                    :is="getStatusIcon(job.status)"
                                    class="h-5 w-5"
                                    :class="[
                                        getStatusColor(job.status),
                                        job.status === 'running' ? 'animate-spin' : ''
                                    ]"
                                />
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{ job.company_name || 'Unknown' }}</span>
                                        <Badge
                                            v-if="job.status === 'completed'"
                                            variant="secondary"
                                            class="text-xs"
                                        >
                                            {{ job.policies_found }} found
                                        </Badge>
                                    </div>
                                    <div class="text-xs text-muted-foreground truncate max-w-md">
                                        {{ job.website_url }}
                                    </div>
                                    <div v-if="job.status === 'completed'" class="text-xs text-muted-foreground">
                                        Crawled {{ job.urls_crawled }} URLs
                                    </div>
                                    <div v-if="job.error_message" class="text-xs text-red-500 truncate max-w-md">
                                        {{ job.error_message }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <Badge :variant="getStatusBadgeVariant(job.status)">
                                    {{ job.status }}
                                </Badge>
                                <span class="text-xs text-muted-foreground whitespace-nowrap">
                                    {{ formatRelativeTime(job.created_at) }}
                                </span>
                                <Eye class="h-4 w-4 text-muted-foreground" />
                            </div>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <!-- Recent AI Analysis Jobs -->
            <Card v-if="activeTab === 'analysis'">
                <CardHeader>
                    <CardTitle>Recent AI Analysis Jobs</CardTitle>
                    <CardDescription>
                        Showing {{ filteredAnalysisJobs.length }} of {{ recentAnalysisJobs.length }} jobs
                        <span v-if="stats.ai_analyses_today > 0" class="ml-2">
                            ({{ stats.ai_analyses_today }} today, {{ stats.ai_analyses_total }} total)
                        </span>
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="filteredAnalysisJobs.length === 0" class="py-8 text-center text-muted-foreground">
                        <Brain class="h-12 w-12 mx-auto mb-4 opacity-50" />
                        <template v-if="recentAnalysisJobs.length === 0">
                            <p>No AI analysis jobs yet.</p>
                            <p class="text-sm mt-2">Go to a document page and click "Analyze with AI" to start.</p>
                        </template>
                        <template v-else>
                            <p>No jobs match the current filters.</p>
                        </template>
                    </div>
                    <div v-else class="space-y-2">
                        <Link
                            v-for="job in filteredAnalysisJobs"
                            :key="job.id"
                            :href="job.document_id ? `/documents/${job.document_id}` : '#'"
                            class="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50 transition-colors cursor-pointer"
                        >
                            <div class="flex items-center gap-3">
                                <component
                                    :is="getStatusIcon(job.status)"
                                    class="h-5 w-5"
                                    :class="[
                                        getStatusColor(job.status),
                                        job.status === 'running' ? 'animate-spin' : ''
                                    ]"
                                />
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{ job.company_name || 'Unknown' }}</span>
                                        <Badge variant="outline" class="text-xs">
                                            {{ job.document_type || 'Document' }}
                                        </Badge>
                                        <Badge
                                            v-if="job.status === 'completed' && job.model_used"
                                            variant="secondary"
                                            class="text-xs"
                                        >
                                            {{ job.model_used }}
                                        </Badge>
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ job.analysis_type.replace('_', ' ') }}
                                        <span v-if="job.tokens_used"> &middot; {{ job.tokens_used.toLocaleString() }} tokens</span>
                                        <span v-if="job.analysis_cost"> &middot; {{ formatCost(job.analysis_cost) }}</span>
                                        <span v-if="job.duration_ms"> &middot; {{ formatDuration(job.duration_ms) }}</span>
                                    </div>
                                    <div v-if="job.error_message" class="text-xs text-red-500 truncate max-w-md">
                                        {{ job.error_message }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <Badge :variant="getStatusBadgeVariant(job.status)">
                                    {{ job.status }}
                                </Badge>
                                <span class="text-xs text-muted-foreground whitespace-nowrap">
                                    {{ formatRelativeTime(job.created_at) }}
                                </span>
                                <Eye class="h-4 w-4 text-muted-foreground" />
                            </div>
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
