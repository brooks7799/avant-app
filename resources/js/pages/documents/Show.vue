<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    FileText,
    ExternalLink,
    Building2,
    Globe,
    Clock,
    CheckCircle2,
    XCircle,
    RefreshCw,
    Hash,
    Calendar,
    Eye,
    ArrowLeft,
    Loader2,
    Package,
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
import { computed, ref } from 'vue';

interface DocumentData {
    id: number;
    source_url: string;
    canonical_url: string | null;
    document_type: string;
    document_type_slug: string;
    company_id: number;
    company_name: string;
    website_id: number;
    website_url: string;
    is_active: boolean;
    is_monitored: boolean;
    scrape_frequency: string;
    scrape_status: string;
    discovery_method: string;
    last_scraped_at: string | null;
    last_changed_at: string | null;
    created_at: string;
    updated_at: string;
    metadata: Record<string, unknown> | null;
}

interface Version {
    id: number;
    version_number: number;
    content_text: string | null;
    content_markdown: string | null;
    word_count: number;
    character_count: number;
    language: string | null;
    content_hash: string;
    scraped_at: string | null;
    effective_date: string | null;
}

interface VersionSummary {
    id: number;
    version_number: number;
    word_count: number;
    content_hash: string;
    scraped_at: string | null;
    is_current: boolean;
}

interface ScrapeJob {
    id: number;
    status: string;
    content_changed: boolean;
    error_message: string | null;
    started_at: string | null;
    completed_at: string | null;
    duration_ms: number | null;
    created_at: string;
}

interface Product {
    id: number;
    name: string;
    type: string;
    is_primary: boolean;
}

interface Props {
    document: DocumentData;
    currentVersion: Version | null;
    versions: VersionSummary[];
    scrapeJobs: ScrapeJob[];
    products: Product[];
}

const props = defineProps<Props>();

const isScraping = ref(false);
const showFullContent = ref(false);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Companies', href: '/companies' },
    { title: props.document.company_name, href: `/companies/${props.document.company_id}` },
    { title: props.document.document_type, href: `/documents/${props.document.id}` },
]);

function scrapeNow() {
    isScraping.value = true;
    router.post(`/documents/${props.document.id}/scrape`, {}, {
        preserveScroll: true,
        onFinish: () => {
            isScraping.value = false;
        },
    });
}

function formatDate(dateString: string | null): string {
    if (!dateString) return 'Never';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatRelativeTime(dateString: string | null): string {
    if (!dateString) return 'Never';
    const date = new Date(dateString);
    const now = new Date();
    const diff = now.getTime() - date.getTime();

    const seconds = Math.floor(diff / 1000);
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (days > 0) return `${days}d ago`;
    if (hours > 0) return `${hours}h ago`;
    if (minutes > 0) return `${minutes}m ago`;
    return 'just now';
}

function formatDuration(ms: number | null): string {
    if (!ms) return '-';
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
}

function getScrapeStatusIcon(status: string) {
    switch (status) {
        case 'success':
            return CheckCircle2;
        case 'failed':
        case 'blocked':
            return XCircle;
        case 'running':
            return Loader2;
        default:
            return Clock;
    }
}

function getScrapeStatusColor(status: string): string {
    switch (status) {
        case 'success':
            return 'text-green-500';
        case 'failed':
        case 'blocked':
            return 'text-red-500';
        case 'running':
            return 'text-blue-500';
        default:
            return 'text-yellow-500';
    }
}

function getStatusBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'success':
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
</script>

<template>
    <Head :title="`${document.document_type} - ${document.company_name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Back Link -->
            <div>
                <Link
                    :href="`/companies/${document.company_id}`"
                    class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Back to {{ document.company_name }}
                </Link>
            </div>

            <!-- Header -->
            <div class="flex items-start justify-between">
                <div class="flex items-start gap-4">
                    <div class="flex h-14 w-14 items-center justify-center rounded-lg bg-muted">
                        <FileText class="h-7 w-7 text-muted-foreground" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold">{{ document.document_type }}</h1>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ document.company_name }}
                        </p>
                        <a
                            :href="document.source_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-1 flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 hover:underline break-all"
                        >
                            <ExternalLink class="h-3 w-3 flex-shrink-0" />
                            {{ document.source_url }}
                        </a>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Badge v-if="document.is_monitored" variant="secondary">
                        Monitored
                    </Badge>
                    <Badge v-if="!document.is_active" variant="destructive">
                        Inactive
                    </Badge>
                    <Button @click="scrapeNow" :disabled="isScraping || document.scrape_status === 'running'">
                        <Loader2 v-if="isScraping || document.scrape_status === 'running'" class="mr-2 h-4 w-4 animate-spin" />
                        <RefreshCw v-else class="mr-2 h-4 w-4" />
                        Scrape Now
                    </Button>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid gap-4 md:grid-cols-4">
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Status</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="flex items-center gap-2">
                            <component
                                :is="getScrapeStatusIcon(document.scrape_status)"
                                class="h-5 w-5"
                                :class="getScrapeStatusColor(document.scrape_status)"
                            />
                            <span class="text-xl font-bold capitalize">{{ document.scrape_status || 'pending' }}</span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Last Scraped</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-xl font-bold">{{ formatRelativeTime(document.last_scraped_at) }}</div>
                        <p class="text-xs text-muted-foreground">{{ formatDate(document.last_scraped_at) }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Versions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-xl font-bold">{{ versions.length }}</div>
                        <p class="text-xs text-muted-foreground">version{{ versions.length !== 1 ? 's' : '' }} captured</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Word Count</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-xl font-bold">{{ currentVersion?.word_count?.toLocaleString() ?? '-' }}</div>
                        <p class="text-xs text-muted-foreground">{{ currentVersion?.character_count?.toLocaleString() ?? '-' }} characters</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Metadata -->
            <Card>
                <CardHeader>
                    <CardTitle>Document Details</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Document Type</dt>
                            <dd class="mt-1">{{ document.document_type }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Discovery Method</dt>
                            <dd class="mt-1 capitalize">{{ document.discovery_method.replace('_', ' ') }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Scrape Frequency</dt>
                            <dd class="mt-1 capitalize">{{ document.scrape_frequency }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Language</dt>
                            <dd class="mt-1">{{ currentVersion?.language || 'Unknown' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Last Changed</dt>
                            <dd class="mt-1">{{ formatDate(document.last_changed_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Added</dt>
                            <dd class="mt-1">{{ formatDate(document.created_at) }}</dd>
                        </div>
                        <div v-if="currentVersion?.content_hash" class="sm:col-span-2 lg:col-span-3">
                            <dt class="text-sm font-medium text-muted-foreground">Content Hash</dt>
                            <dd class="mt-1 font-mono text-xs text-muted-foreground">{{ currentVersion.content_hash }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <!-- Products -->
            <Card v-if="products.length > 0">
                <CardHeader>
                    <CardTitle>Linked Products</CardTitle>
                    <CardDescription>Products and services that use this document</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex flex-wrap gap-2">
                        <Badge
                            v-for="product in products"
                            :key="product.id"
                            variant="secondary"
                            class="gap-1"
                        >
                            <Package class="h-3 w-3" />
                            {{ product.name }}
                            <span v-if="product.is_primary" class="text-xs">(Primary)</span>
                        </Badge>
                    </div>
                </CardContent>
            </Card>

            <!-- Current Version Content -->
            <Card v-if="currentVersion">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle>Current Content</CardTitle>
                            <CardDescription>
                                Version {{ currentVersion.version_number }} &middot;
                                Scraped {{ formatDate(currentVersion.scraped_at) }}
                            </CardDescription>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="showFullContent = !showFullContent"
                        >
                            <Eye class="mr-2 h-4 w-4" />
                            {{ showFullContent ? 'Show Less' : 'Show Full' }}
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        class="rounded-lg bg-muted/30 p-4 font-mono text-sm whitespace-pre-wrap overflow-x-auto"
                        :class="{ 'max-h-96 overflow-y-auto': !showFullContent }"
                    >
                        {{ currentVersion.content_text }}
                    </div>
                </CardContent>
            </Card>

            <!-- No Content State -->
            <Card v-else>
                <CardContent class="flex flex-col items-center justify-center py-12">
                    <FileText class="h-12 w-12 text-muted-foreground" />
                    <h3 class="mt-4 text-lg font-semibold">No content yet</h3>
                    <p class="mt-2 text-center text-sm text-muted-foreground">
                        This document hasn't been scraped yet.
                    </p>
                    <Button class="mt-4" @click="scrapeNow" :disabled="isScraping">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Scrape Now
                    </Button>
                </CardContent>
            </Card>

            <!-- Version History -->
            <Card v-if="versions.length > 0">
                <CardHeader>
                    <CardTitle>Version History</CardTitle>
                    <CardDescription>Previous versions of this document</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2">
                        <div
                            v-for="version in versions"
                            :key="version.id"
                            class="flex items-center justify-between rounded-lg border p-3"
                            :class="{ 'bg-muted/50': version.is_current }"
                        >
                            <div class="flex items-center gap-3">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-muted text-sm font-medium">
                                    v{{ version.version_number }}
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium">{{ version.word_count?.toLocaleString() }} words</span>
                                        <Badge v-if="version.is_current" variant="secondary" class="text-xs">
                                            Current
                                        </Badge>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        {{ formatDate(version.scraped_at) }}
                                    </p>
                                </div>
                            </div>
                            <span class="font-mono text-xs text-muted-foreground">
                                {{ version.content_hash.substring(0, 12) }}...
                            </span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Recent Scrape Jobs -->
            <Card v-if="scrapeJobs.length > 0">
                <CardHeader>
                    <CardTitle>Recent Scrape Jobs</CardTitle>
                    <CardDescription>Last 10 scrape attempts</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2">
                        <Link
                            v-for="job in scrapeJobs"
                            :key="job.id"
                            :href="`/queue/scrape/${job.id}`"
                            class="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50 transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <component
                                    :is="getScrapeStatusIcon(job.status)"
                                    class="h-5 w-5"
                                    :class="[
                                        getScrapeStatusColor(job.status),
                                        job.status === 'running' ? 'animate-spin' : ''
                                    ]"
                                />
                                <div>
                                    <div class="flex items-center gap-2">
                                        <Badge :variant="getStatusBadgeVariant(job.status)" class="text-xs">
                                            {{ job.status }}
                                        </Badge>
                                        <Badge v-if="job.content_changed" class="text-xs bg-blue-100 text-blue-800">
                                            Changed
                                        </Badge>
                                    </div>
                                    <p class="text-xs text-muted-foreground mt-1">
                                        {{ formatDate(job.created_at) }}
                                        <span v-if="job.duration_ms"> &middot; {{ formatDuration(job.duration_ms) }}</span>
                                    </p>
                                </div>
                            </div>
                            <Eye class="h-4 w-4 text-muted-foreground" />
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <!-- Links -->
            <div class="flex gap-3">
                <Link :href="`/companies/${document.company_id}`">
                    <Button variant="outline" class="gap-2">
                        <Building2 class="h-4 w-4" />
                        View Company
                    </Button>
                </Link>
                <a :href="document.source_url" target="_blank">
                    <Button variant="outline" class="gap-2">
                        <ExternalLink class="h-4 w-4" />
                        View Original
                    </Button>
                </a>
            </div>
        </div>
    </AppLayout>
</template>
