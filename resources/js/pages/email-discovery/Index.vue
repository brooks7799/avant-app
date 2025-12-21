<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import {
    Mail,
    RefreshCw,
    Check,
    X,
    ExternalLink,
    Building2,
    Clock,
    AlertCircle,
    Loader2,
    Unlink,
    Search,
    Import
} from 'lucide-vue-next';

interface Connection {
    email: string;
    status: string;
    last_sync_at: string | null;
    is_active: boolean;
}

interface DiscoveredCompany {
    id: number;
    name: string;
    domain: string;
    email_address: string;
    detection_source: string;
    detection_source_label: string;
    confidence_score: number;
    confidence_level: string;
    status: string;
    email_metadata: {
        subject: string;
        date: string | null;
        snippet: string | null;
    } | null;
    detected_policy_urls: string[] | null;
    company_id: number | null;
}

interface Job {
    id: number;
    status: string;
    emails_scanned: number;
    companies_found: number;
    error_message: string | null;
    progress_log: string[] | null;
    duration_ms: number | null;
    created_at: string;
    completed_at: string | null;
}

const props = defineProps<{
    connection: Connection | null;
    latestJob: Job | null;
    discoveredCompanies: DiscoveredCompany[];
    previousJobs: Job[];
}>();

const page = usePage();
const selectedCompanies = ref<number[]>([]);
const isPolling = ref(false);
const pollInterval = ref<number | null>(null);
const currentJob = ref<Job | null>(props.latestJob);

const isJobRunning = computed(() => {
    return currentJob.value?.status === 'pending' || currentJob.value?.status === 'running';
});

const pendingCompanies = computed(() => {
    return props.discoveredCompanies.filter(c => c.status === 'pending');
});

const importedCompanies = computed(() => {
    return props.discoveredCompanies.filter(c => c.status === 'imported');
});

const dismissedCompanies = computed(() => {
    return props.discoveredCompanies.filter(c => c.status === 'dismissed');
});

const allSelected = computed(() => {
    return pendingCompanies.value.length > 0 &&
           selectedCompanies.value.length === pendingCompanies.value.length;
});

function toggleSelectAll() {
    if (allSelected.value) {
        selectedCompanies.value = [];
    } else {
        selectedCompanies.value = pendingCompanies.value.map(c => c.id);
    }
}

function isCompanySelected(id: number): boolean {
    return selectedCompanies.value.includes(id);
}

function toggleCompany(id: number) {
    if (selectedCompanies.value.includes(id)) {
        selectedCompanies.value = selectedCompanies.value.filter(cid => cid !== id);
    } else {
        selectedCompanies.value = [...selectedCompanies.value, id];
    }
}

function getConfidenceBadgeVariant(level: string) {
    switch (level) {
        case 'high': return 'default';
        case 'medium': return 'secondary';
        case 'low': return 'outline';
        default: return 'outline';
    }
}

function formatDuration(ms: number | null): string {
    if (!ms) return '-';
    const seconds = Math.round(ms / 1000);
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    return `${minutes}m ${remainingSeconds}s`;
}

function formatDate(dateString: string | null): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString();
}

async function pollJobStatus() {
    if (!isJobRunning.value) {
        stopPolling();
        return;
    }

    try {
        const response = await fetch('/email-discovery/status');
        const data = await response.json();

        if (data.job) {
            currentJob.value = data.job;

            if (data.job.status === 'completed' || data.job.status === 'failed') {
                stopPolling();
                // Reload to get updated companies
                router.reload();
            }
        }
    } catch (error) {
        console.error('Error polling job status:', error);
    }
}

function startPolling() {
    if (pollInterval.value) return;
    isPolling.value = true;
    pollInterval.value = window.setInterval(pollJobStatus, 2000);
}

function stopPolling() {
    if (pollInterval.value) {
        clearInterval(pollInterval.value);
        pollInterval.value = null;
    }
    isPolling.value = false;
}

function startScan() {
    router.post('/email-discovery/scan', {}, {
        preserveScroll: true,
        onSuccess: () => {
            // Reload page data to get the new job
            router.reload({
                only: ['latestJob'],
                onSuccess: () => {
                    // Update currentJob from new props and start polling
                    currentJob.value = props.latestJob;
                    if (isJobRunning.value) {
                        startPolling();
                    }
                }
            });
        },
    });
}

function importSelected() {
    if (selectedCompanies.value.length === 0) return;
    router.post('/email-discovery/import', {
        company_ids: selectedCompanies.value
    });
}

function importSingle(id: number) {
    router.post(`/email-discovery/${id}/import`);
}

function dismissCompany(id: number) {
    router.post(`/email-discovery/${id}/dismiss`);
}

function disconnectGmail() {
    if (confirm('Are you sure you want to disconnect Gmail? Your discovered companies will be preserved.')) {
        router.post('/gmail/disconnect');
    }
}

// Watch for prop changes (e.g., after redirect from scan)
watch(() => props.latestJob, (newJob) => {
    currentJob.value = newJob;
    if (newJob && (newJob.status === 'pending' || newJob.status === 'running')) {
        startPolling();
    }
}, { immediate: false });

onMounted(() => {
    if (isJobRunning.value) {
        startPolling();
    }
});

onUnmounted(() => {
    stopPolling();
});
</script>

<template>
    <AppLayout title="Email Discovery">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Email Discovery</h1>
                    <p class="text-muted-foreground">
                        Discover companies you've signed up with by scanning your Gmail
                    </p>
                </div>
            </div>

            <!-- Flash Messages -->
            <div v-if="page.props.flash?.success" class="rounded-lg bg-green-50 p-4 text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-lg bg-red-50 p-4 text-red-800 dark:bg-red-950 dark:text-red-200">
                {{ page.props.flash.error }}
            </div>

            <!-- Connection Status -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Mail class="h-5 w-5" />
                        Gmail Connection
                    </CardTitle>
                    <CardDescription>
                        Connect your Gmail to discover companies from your inbox
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="!connection" class="flex flex-col items-center gap-4 py-8">
                        <Mail class="h-12 w-12 text-muted-foreground" />
                        <p class="text-muted-foreground">No Gmail account connected</p>
                        <Button as-child>
                            <a href="/gmail/connect">
                                <Mail class="mr-2 h-4 w-4" />
                                Connect Gmail
                            </a>
                        </Button>
                        <p class="text-xs text-muted-foreground max-w-md text-center">
                            We only request read-only access to search for welcome emails and ToS updates.
                            We never read sensitive emails or send messages.
                        </p>
                    </div>
                    <div v-else class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                                <Check class="h-5 w-5 text-green-600 dark:text-green-400" />
                            </div>
                            <div>
                                <p class="font-medium">{{ connection.email }}</p>
                                <p class="text-sm text-muted-foreground">
                                    Connected
                                    <span v-if="connection.last_sync_at">
                                        · Last scan: {{ formatDate(connection.last_sync_at) }}
                                    </span>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button
                                @click="startScan"
                                :disabled="isJobRunning"
                            >
                                <Loader2 v-if="isJobRunning" class="mr-2 h-4 w-4 animate-spin" />
                                <Search v-else class="mr-2 h-4 w-4" />
                                {{ isJobRunning ? 'Scanning...' : 'Scan Inbox' }}
                            </Button>
                            <Button variant="outline" @click="disconnectGmail">
                                <Unlink class="mr-2 h-4 w-4" />
                                Disconnect
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Scan Progress -->
            <Card v-if="currentJob && isJobRunning">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Loader2 class="h-5 w-5 animate-spin" />
                        Scanning Emails...
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <div class="flex items-center gap-8">
                            <div>
                                <p class="text-2xl font-bold">{{ currentJob.emails_scanned }}</p>
                                <p class="text-sm text-muted-foreground">Emails Scanned</p>
                            </div>
                            <div>
                                <p class="text-2xl font-bold">{{ currentJob.companies_found }}</p>
                                <p class="text-sm text-muted-foreground">Companies Found</p>
                            </div>
                        </div>
                        <div v-if="currentJob.progress_log && currentJob.progress_log.length > 0" class="rounded-lg bg-muted p-4">
                            <p class="text-sm font-mono">
                                {{ currentJob.progress_log[currentJob.progress_log.length - 1] }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Scan Error -->
            <Card v-if="currentJob && currentJob.status === 'failed'" class="border-red-200 dark:border-red-800">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-red-600 dark:text-red-400">
                        <AlertCircle class="h-5 w-5" />
                        Scan Failed
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-muted-foreground">{{ currentJob.error_message }}</p>
                    <Button class="mt-4" @click="startScan">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Try Again
                    </Button>
                </CardContent>
            </Card>

            <!-- Discovered Companies -->
            <Card v-if="discoveredCompanies.length > 0">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle>Discovered Companies</CardTitle>
                            <CardDescription>
                                {{ pendingCompanies.length }} pending ·
                                {{ importedCompanies.length }} imported ·
                                {{ dismissedCompanies.length }} dismissed
                            </CardDescription>
                        </div>
                        <div v-if="pendingCompanies.length > 0" class="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="toggleSelectAll"
                            >
                                {{ allSelected ? 'Deselect All' : 'Select All' }}
                            </Button>
                            <Button
                                size="sm"
                                :disabled="selectedCompanies.length === 0"
                                @click="importSelected"
                            >
                                <Import class="mr-2 h-4 w-4" />
                                Import Selected ({{ selectedCompanies.length }})
                            </Button>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <!-- Pending Companies -->
                        <div
                            v-for="company in pendingCompanies"
                            :key="company.id"
                            class="flex items-center gap-4 rounded-lg border p-4 cursor-pointer hover:bg-muted/50 transition-colors"
                            @click="toggleCompany(company.id)"
                        >
                            <input
                                type="checkbox"
                                :checked="isCompanySelected(company.id)"
                                @click.stop
                                @change="toggleCompany(company.id)"
                                class="h-4 w-4 cursor-pointer rounded border-gray-300 text-primary focus:ring-primary"
                            />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <Building2 class="h-4 w-4 text-muted-foreground" />
                                    <span class="font-medium">{{ company.name }}</span>
                                    <Badge :variant="getConfidenceBadgeVariant(company.confidence_level)">
                                        {{ Math.round(company.confidence_score * 100) }}% confidence
                                    </Badge>
                                    <Badge variant="outline">{{ company.detection_source_label }}</Badge>
                                </div>
                                <p class="text-sm text-muted-foreground">{{ company.domain }}</p>
                                <p v-if="company.email_metadata?.subject" class="text-sm text-muted-foreground truncate">
                                    {{ company.email_metadata.subject }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2" @click.stop>
                                <Button size="sm" variant="outline" as-child>
                                    <a :href="`https://${company.domain}`" target="_blank">
                                        <ExternalLink class="h-4 w-4" />
                                    </a>
                                </Button>
                                <Button size="sm" @click="importSingle(company.id)">
                                    <Check class="mr-1 h-4 w-4" />
                                    Import
                                </Button>
                                <Button size="sm" variant="ghost" @click="dismissCompany(company.id)">
                                    <X class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>

                        <!-- Imported Companies -->
                        <div v-if="importedCompanies.length > 0" class="border-t pt-4">
                            <h4 class="text-sm font-medium text-muted-foreground mb-2">Imported</h4>
                            <div v-for="company in importedCompanies" :key="company.id" class="flex items-center gap-4 rounded-lg bg-green-50 dark:bg-green-950/30 p-4 opacity-75">
                                <Check class="h-5 w-5 text-green-600" />
                                <div class="flex-1">
                                    <span class="font-medium">{{ company.name }}</span>
                                    <span class="text-sm text-muted-foreground ml-2">{{ company.domain }}</span>
                                </div>
                                <Button size="sm" variant="outline" as-child>
                                    <Link :href="`/companies/${company.company_id}`">
                                        View Company
                                    </Link>
                                </Button>
                            </div>
                        </div>

                        <!-- Dismissed Companies -->
                        <div v-if="dismissedCompanies.length > 0" class="border-t pt-4">
                            <h4 class="text-sm font-medium text-muted-foreground mb-2">Dismissed</h4>
                            <div v-for="company in dismissedCompanies" :key="company.id" class="flex items-center gap-4 rounded-lg p-4 opacity-50">
                                <X class="h-5 w-5 text-muted-foreground" />
                                <div class="flex-1">
                                    <span class="font-medium">{{ company.name }}</span>
                                    <span class="text-sm text-muted-foreground ml-2">{{ company.domain }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Empty State -->
            <Card v-else-if="connection && !isJobRunning">
                <CardContent class="flex flex-col items-center gap-4 py-12">
                    <Search class="h-12 w-12 text-muted-foreground" />
                    <div class="text-center">
                        <p class="font-medium">No companies discovered yet</p>
                        <p class="text-sm text-muted-foreground">
                            Click "Scan Inbox" to search your email for companies you've signed up with
                        </p>
                    </div>
                    <Button @click="startScan">
                        <Search class="mr-2 h-4 w-4" />
                        Scan Inbox
                    </Button>
                </CardContent>
            </Card>

            <!-- Previous Scans -->
            <Card v-if="previousJobs.length > 1">
                <CardHeader>
                    <CardTitle>Scan History</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2">
                        <div v-for="job in previousJobs.slice(1)" :key="job.id" class="flex items-center justify-between rounded-lg border p-3">
                            <div class="flex items-center gap-4">
                                <Badge :variant="job.status === 'completed' ? 'default' : job.status === 'failed' ? 'destructive' : 'secondary'">
                                    {{ job.status }}
                                </Badge>
                                <span class="text-sm">{{ formatDate(job.created_at) }}</span>
                            </div>
                            <div class="flex items-center gap-4 text-sm text-muted-foreground">
                                <span>{{ job.emails_scanned }} emails</span>
                                <span>{{ job.companies_found }} companies</span>
                                <span>{{ formatDuration(job.duration_ms) }}</span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
