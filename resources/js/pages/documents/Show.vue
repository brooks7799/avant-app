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
    Code,
    Type,
    BookOpen,
    FileCode,
    Brain,
    AlertTriangle,
    ShieldCheck,
    ThumbsUp,
    ThumbsDown,
    Lightbulb,
    HelpCircle,
    ChevronDown,
    ChevronUp,
    History,
    CircleAlert,
    GitCompare,
    ArrowRight,
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
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { marked } from 'marked';

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

interface ExtractedMetadataItem {
    value: string;
    raw_match: string;
    confidence: number;
    position: string;
}

interface ExtractedMetadata {
    update_date?: ExtractedMetadataItem;
    effective_date?: ExtractedMetadataItem;
    version?: ExtractedMetadataItem;
    extracted_at?: string;
}

interface Version {
    id: number;
    version_number: number;
    content_raw: string | null;
    content_text: string | null;
    content_markdown: string | null;
    word_count: number;
    character_count: number;
    language: string | null;
    content_hash: string;
    scraped_at: string | null;
    effective_date: string | null;
    metadata: ExtractedMetadata | null;
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

interface FaqItem {
    question: string;
    short_answer: string;
    long_answer: string;
    risk_level: number;
    what_to_watch_for: string;
}

interface FlagItem {
    type: string;
    description: string;
    section_reference?: string;
    severity: number;
}

interface Analysis {
    id: number;
    analysis_type: string;
    overall_score: number;
    overall_rating: string;
    summary: string | null;
    key_concerns: string | null;
    positive_aspects: string | null;
    recommendations: string | null;
    extracted_data: {
        faq?: FaqItem[];
        dimension_scores?: Record<string, number>;
        chunk_summaries?: string[];
    } | null;
    flags: {
        red?: FlagItem[];
        yellow?: FlagItem[];
        green?: FlagItem[];
    } | null;
    model_used: string;
    tokens_used: number;
    analysis_cost: number;
    processing_errors: string[] | null;
    has_errors: boolean;
    created_at: string;
}

interface AnalysisHistoryItem {
    id: number;
    analysis_type: string;
    overall_score: number;
    overall_rating: string;
    model_used: string;
    tokens_used: number;
    analysis_cost: number;
    is_current: boolean;
    has_errors: boolean;
    error_count: number;
    created_at: string;
}

interface PendingAnalysisJob {
    id: number;
    status: 'pending' | 'running';
    created_at: string;
    started_at: string | null;
    progress_log: Array<{ timestamp: string; message: string }> | null;
}

interface VersionComparisonAnalysis {
    comparison_id: number;
    old_version_id: number;
    new_version_id: number;
    old_version_number: number;
    new_version_number: number;
    compare_url: string;
    analysis: {
        id: number;
        status: string;
        summary: string | null;
        impact_analysis: string | null;
        impact_score_delta: number | null;
        change_flags: {
            new_clauses?: Array<{ type: string; severity: number; description: string }>;
            removed_clauses?: Array<{ type: string; severity: number; description: string }>;
            modified_clauses?: Array<{ type: string; severity: number; description: string }>;
            neutral_changes?: Array<{ type: string; description: string }>;
        } | null;
        is_suspicious_timing: boolean;
        suspicious_timing_score: number | null;
        timing_context: any | null;
        completed_at: string | null;
    };
}

interface Props {
    document: DocumentData;
    currentVersion: Version | null;
    versions: VersionSummary[];
    scrapeJobs: ScrapeJob[];
    products: Product[];
    analysis: Analysis | null;
    analysisHistory: AnalysisHistoryItem[];
    pendingAnalysisJob: PendingAnalysisJob | null;
    latestVersionComparison: VersionComparisonAnalysis | null;
}

const props = defineProps<Props>();

const isScraping = ref(false);
const isExtracting = ref(false);
const isAnalyzing = ref(false);
const activeContentTab = ref('rendered');
const expandedFaqIndex = ref<number | null>(null);
const showAllFlags = ref(false);
const showAnalysisHistory = ref(false);
const showProcessingErrors = ref(false);
const showLatestChangesDetails = ref(false);

// Version comparison state
const compareMode = ref(false);
const selectedVersions = ref<number[]>([]);

function toggleCompareMode() {
    compareMode.value = !compareMode.value;
    if (!compareMode.value) {
        selectedVersions.value = [];
    }
}

function toggleVersionSelection(versionId: number) {
    const index = selectedVersions.value.indexOf(versionId);
    if (index >= 0) {
        selectedVersions.value.splice(index, 1);
    } else if (selectedVersions.value.length < 2) {
        selectedVersions.value.push(versionId);
    }
}

function isVersionSelected(versionId: number): boolean {
    return selectedVersions.value.includes(versionId);
}

function canCompare(): boolean {
    return selectedVersions.value.length === 2;
}

function getCompareUrl(): string {
    if (selectedVersions.value.length !== 2) return '';
    // Sort so older version is first (lower ID typically means older)
    const sorted = [...selectedVersions.value].sort((a, b) => a - b);
    return `/documents/${props.document.id}/compare/${sorted[0]}/${sorted[1]}`;
}

function getPreviousVersion(versionIndex: number): VersionSummary | null {
    // versions are sorted by scraped_at desc, so next index is the previous version
    if (versionIndex < props.versions.length - 1) {
        return props.versions[versionIndex + 1];
    }
    return null;
}

function handleCompareClick() {
    if (!compareMode.value) {
        // First click - enter compare mode
        compareMode.value = true;
    } else if (canCompare()) {
        // Second click with 2 versions selected - navigate to compare page
        window.location.href = getCompareUrl();
    }
    // If in compare mode but less than 2 selected, do nothing (button shows "Select 2 Versions")
}

let analysisPollingInterval: number | null = null;

// Start polling if there's a pending job
onMounted(() => {
    if (props.pendingAnalysisJob) {
        startAnalysisPolling();
    }
});

onUnmounted(() => {
    stopAnalysisPolling();
});

function startAnalysisPolling() {
    if (analysisPollingInterval) return;
    analysisPollingInterval = window.setInterval(() => {
        router.reload({
            only: ['analysis', 'analysisHistory', 'pendingAnalysisJob'],
            preserveScroll: true,
            onSuccess: () => {
                // Stop polling if job is done
                if (!props.pendingAnalysisJob) {
                    stopAnalysisPolling();
                }
            },
        });
    }, 3000);
}

function stopAnalysisPolling() {
    if (analysisPollingInterval) {
        clearInterval(analysisPollingInterval);
        analysisPollingInterval = null;
    }
}

function runAiAnalysis() {
    isAnalyzing.value = true;
    router.post(`/documents/${props.document.id}/analyze`, {}, {
        preserveScroll: true,
        onFinish: () => {
            isAnalyzing.value = false;
            // Start polling after submitting
            startAnalysisPolling();
        },
    });
}

function toggleFaq(index: number) {
    expandedFaqIndex.value = expandedFaqIndex.value === index ? null : index;
}

function getRatingColor(rating: string): string {
    switch (rating) {
        case 'A': return 'text-green-600 bg-green-100';
        case 'B': return 'text-lime-600 bg-lime-100';
        case 'C': return 'text-yellow-600 bg-yellow-100';
        case 'D': return 'text-orange-600 bg-orange-100';
        case 'F': return 'text-red-600 bg-red-100';
        default: return 'text-gray-600 bg-gray-100';
    }
}

function getRatingLabel(rating: string): string {
    switch (rating) {
        case 'A': return 'Excellent';
        case 'B': return 'Good';
        case 'C': return 'Fair';
        case 'D': return 'Poor';
        case 'F': return 'Failing';
        default: return 'Unknown';
    }
}

function formatFlagType(type: string): string {
    return type.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
}

function reExtractMetadata() {
    isExtracting.value = true;
    router.post(`/documents/${props.document.id}/extract-metadata`, {}, {
        preserveScroll: true,
        onFinish: () => {
            isExtracting.value = false;
        },
    });
}

// Prepare HTML for iframe rendering
const iframeHtml = computed(() => {
    if (!props.currentVersion?.content_raw) return '';
    const baseUrl = new URL(props.document.source_url).origin;
    return `<!DOCTYPE html>
<html>
<head>
    <base href="${baseUrl}/">
    <meta charset="UTF-8">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            padding: 20px;
            max-width: 100%;
            color: #333;
        }
        img { max-width: 100%; height: auto; }
        a { color: #0066cc; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f5f5f5; }
    </style>
</head>
<body>
${props.currentVersion.content_raw}
</body>
</html>`;
});

// Render markdown to HTML
const renderedMarkdown = computed(() => {
    if (!props.currentVersion?.content_markdown) return '';
    return marked(props.currentVersion.content_markdown) as string;
});

// Render version comparison summary as HTML
const renderedComparisonSummary = computed(() => {
    if (!props.latestVersionComparison?.analysis?.summary) return '';
    return marked(props.latestVersionComparison.analysis.summary) as string;
});

// Render version comparison impact analysis as HTML
const renderedComparisonImpact = computed(() => {
    if (!props.latestVersionComparison?.analysis?.impact_analysis) return '';
    return marked(props.latestVersionComparison.analysis.impact_analysis) as string;
});

// Render document analysis summary as HTML
const renderedAnalysisSummary = computed(() => {
    if (!props.analysis?.summary) return '';
    return marked(props.analysis.summary) as string;
});

// Render document analysis recommendations as HTML
const renderedAnalysisRecommendations = computed(() => {
    if (!props.analysis?.recommendations) return '';
    return marked(props.analysis.recommendations) as string;
});

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

function formatDateOnly(dateString: string | null): string {
    if (!dateString) return 'Unknown';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
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
        <div class="mx-auto w-full max-w-6xl px-4 py-6">
        <div class="flex h-full flex-1 flex-col gap-6">
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
                        Retrieve Now
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
                        <CardTitle class="text-sm font-medium text-muted-foreground">Last Retrieved</CardTitle>
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

            <!-- Latest Version Changes Summary -->
            <Card v-if="latestVersionComparison">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <GitCompare class="h-5 w-5" />
                                Latest Changes
                                <Badge variant="secondary" class="ml-2">
                                    v{{ latestVersionComparison.old_version_number }} → v{{ latestVersionComparison.new_version_number }}
                                </Badge>
                                <Badge
                                    v-if="latestVersionComparison.analysis.is_suspicious_timing"
                                    variant="destructive"
                                    class="ml-1"
                                >
                                    ⚠️ Suspicious Timing
                                </Badge>
                            </CardTitle>
                            <CardDescription>
                                AI analysis of changes from the previous version
                            </CardDescription>
                        </div>
                        <Link :href="latestVersionComparison.compare_url">
                            <Button variant="outline" size="sm">
                                <GitCompare class="mr-2 h-4 w-4" />
                                View Full Comparison
                            </Button>
                        </Link>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Impact Score Delta - Always visible -->
                    <div
                        v-if="latestVersionComparison.analysis.impact_score_delta !== null"
                        class="flex items-center gap-3 rounded-lg p-3"
                        :class="{
                            'bg-red-50 dark:bg-red-950/30': latestVersionComparison.analysis.impact_score_delta < -5,
                            'bg-yellow-50 dark:bg-yellow-950/30': latestVersionComparison.analysis.impact_score_delta >= -5 && latestVersionComparison.analysis.impact_score_delta < 0,
                            'bg-gray-50 dark:bg-gray-800/30': latestVersionComparison.analysis.impact_score_delta === 0,
                            'bg-green-50 dark:bg-green-950/30': latestVersionComparison.analysis.impact_score_delta > 0,
                        }"
                    >
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-full text-lg font-bold"
                            :class="{
                                'bg-red-100 text-red-700': latestVersionComparison.analysis.impact_score_delta < -5,
                                'bg-yellow-100 text-yellow-700': latestVersionComparison.analysis.impact_score_delta >= -5 && latestVersionComparison.analysis.impact_score_delta < 0,
                                'bg-gray-100 text-gray-700': latestVersionComparison.analysis.impact_score_delta === 0,
                                'bg-green-100 text-green-700': latestVersionComparison.analysis.impact_score_delta > 0,
                            }"
                        >
                            {{ latestVersionComparison.analysis.impact_score_delta > 0 ? '+' : '' }}{{ latestVersionComparison.analysis.impact_score_delta }}
                        </div>
                        <div class="flex-1">
                            <p class="font-medium">
                                <span v-if="latestVersionComparison.analysis.impact_score_delta < -5">Significant Negative Impact</span>
                                <span v-else-if="latestVersionComparison.analysis.impact_score_delta < 0">Minor Negative Impact</span>
                                <span v-else-if="latestVersionComparison.analysis.impact_score_delta === 0">Neutral Changes</span>
                                <span v-else>Positive Impact</span>
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Impact score change from version {{ latestVersionComparison.old_version_number }} to {{ latestVersionComparison.new_version_number }}
                            </p>
                        </div>
                        <!-- Expand/Collapse button -->
                        <Button
                            variant="ghost"
                            size="sm"
                            class="cursor-pointer"
                            @click="showLatestChangesDetails = !showLatestChangesDetails"
                        >
                            <component :is="showLatestChangesDetails ? ChevronUp : ChevronDown" class="mr-1 h-4 w-4" />
                            {{ showLatestChangesDetails ? 'Hide Details' : 'Show Details' }}
                        </Button>
                    </div>

                    <!-- Collapsible Details -->
                    <template v-if="showLatestChangesDetails">
                        <!-- Summary -->
                        <div v-if="latestVersionComparison.analysis.summary" class="rounded-lg bg-muted p-5">
                            <div
                                class="prose dark:prose-invert max-w-none prose-headings:text-lg prose-headings:font-semibold prose-headings:mt-4 prose-headings:mb-3 prose-p:my-3 prose-p:leading-relaxed prose-ul:my-3 prose-li:my-1 prose-li:leading-relaxed prose-strong:text-foreground"
                                v-html="renderedComparisonSummary"
                            />
                        </div>

                        <!-- Impact Analysis -->
                        <div v-if="latestVersionComparison.analysis.impact_analysis" class="rounded-lg border p-5">
                            <div
                                class="prose dark:prose-invert max-w-none prose-headings:text-lg prose-headings:font-semibold prose-headings:mt-4 prose-headings:mb-3 prose-p:my-3 prose-p:leading-relaxed prose-ul:my-3 prose-li:my-1 prose-li:leading-relaxed prose-strong:text-foreground"
                                v-html="renderedComparisonImpact"
                            />
                        </div>

                        <!-- Link to full comparison -->
                        <div class="flex justify-center pt-2">
                            <Link :href="latestVersionComparison.compare_url">
                                <Button variant="default">
                                    <GitCompare class="mr-2 h-4 w-4" />
                                    View Full Diff & Details
                                    <ArrowRight class="ml-2 h-4 w-4" />
                                </Button>
                            </Link>
                        </div>
                    </template>
                </CardContent>
            </Card>

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
                            <dt class="text-sm font-medium text-muted-foreground">Retrieval Frequency</dt>
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

            <!-- Extracted Metadata -->
            <Card v-if="currentVersion">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle>Extracted Metadata</CardTitle>
                            <CardDescription>Dates and version info extracted from document content</CardDescription>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="reExtractMetadata"
                            :disabled="isExtracting"
                        >
                            <Loader2 v-if="isExtracting" class="mr-2 h-4 w-4 animate-spin" />
                            <RefreshCw v-else class="mr-2 h-4 w-4" />
                            {{ currentVersion.metadata ? 'Re-extract' : 'Extract' }}
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="currentVersion.metadata && Object.keys(currentVersion.metadata).length > 0">
                        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div v-if="currentVersion.metadata.update_date">
                                <dt class="text-sm font-medium text-muted-foreground">Last Updated (from document)</dt>
                                <dd class="mt-1">
                                    <span class="font-semibold">{{ formatDateOnly(currentVersion.metadata.update_date.value) }}</span>
                                    <div class="mt-1 flex items-center gap-2">
                                        <Badge variant="outline" class="text-xs">
                                            {{ Math.round(currentVersion.metadata.update_date.confidence * 100) }}% confidence
                                        </Badge>
                                    </div>
                                    <p class="mt-1 text-xs text-muted-foreground italic">
                                        Found: "{{ currentVersion.metadata.update_date.raw_match }}"
                                    </p>
                                </dd>
                            </div>
                            <div v-if="currentVersion.metadata.effective_date">
                                <dt class="text-sm font-medium text-muted-foreground">Effective Date</dt>
                                <dd class="mt-1">
                                    <span class="font-semibold">{{ formatDateOnly(currentVersion.metadata.effective_date.value) }}</span>
                                    <div class="mt-1 flex items-center gap-2">
                                        <Badge variant="outline" class="text-xs">
                                            {{ Math.round(currentVersion.metadata.effective_date.confidence * 100) }}% confidence
                                        </Badge>
                                    </div>
                                    <p class="mt-1 text-xs text-muted-foreground italic">
                                        Found: "{{ currentVersion.metadata.effective_date.raw_match }}"
                                    </p>
                                </dd>
                            </div>
                            <div v-if="currentVersion.metadata.version">
                                <dt class="text-sm font-medium text-muted-foreground">Document Version</dt>
                                <dd class="mt-1">
                                    <Badge variant="secondary" class="text-sm">v{{ currentVersion.metadata.version.value }}</Badge>
                                    <div class="mt-1 flex items-center gap-2">
                                        <Badge variant="outline" class="text-xs">
                                            {{ Math.round(currentVersion.metadata.version.confidence * 100) }}% confidence
                                        </Badge>
                                    </div>
                                    <p class="mt-1 text-xs text-muted-foreground italic">
                                        Found: "{{ currentVersion.metadata.version.raw_match }}"
                                    </p>
                                </dd>
                            </div>
                        </dl>
                        <p v-if="currentVersion.metadata.extracted_at" class="mt-4 text-xs text-muted-foreground">
                            Extracted: {{ formatDate(currentVersion.metadata.extracted_at) }}
                        </p>
                    </div>
                    <div v-else class="flex flex-col items-center justify-center py-8 text-center">
                        <Calendar class="h-8 w-8 text-muted-foreground" />
                        <p class="mt-2 text-sm text-muted-foreground">
                            No metadata has been extracted yet
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Click "Extract" to parse dates and version info from the document
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- AI Analysis -->
            <Card v-if="currentVersion">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <Brain class="h-5 w-5" />
                                AI Analysis
                                <Badge v-if="pendingAnalysisJob" variant="secondary" class="ml-2">
                                    <Loader2 class="mr-1 h-3 w-3 animate-spin" />
                                    {{ pendingAnalysisJob.status === 'running' ? 'Analyzing...' : 'Queued' }}
                                </Badge>
                            </CardTitle>
                            <CardDescription>
                                AI-powered analysis of document terms and conditions
                            </CardDescription>
                        </div>
                        <Button
                            @click="runAiAnalysis"
                            :disabled="isAnalyzing || !!pendingAnalysisJob"
                            :variant="analysis ? 'outline' : 'default'"
                        >
                            <Loader2 v-if="isAnalyzing || pendingAnalysisJob" class="mr-2 h-4 w-4 animate-spin" />
                            <Brain v-else class="mr-2 h-4 w-4" />
                            {{ pendingAnalysisJob ? 'Analysis in Progress' : (analysis ? 'Re-analyze' : 'Analyze with AI') }}
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <!-- Analysis In Progress -->
                    <div v-if="pendingAnalysisJob" class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="relative">
                            <Brain class="h-16 w-16 text-primary/30" />
                            <Loader2 class="h-8 w-8 text-primary animate-spin absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2" />
                        </div>
                        <h3 class="mt-6 text-lg font-semibold">
                            {{ pendingAnalysisJob.status === 'running' ? 'Analyzing Document...' : 'Waiting in Queue...' }}
                        </h3>
                        <p class="mt-2 text-sm text-muted-foreground max-w-md">
                            {{ pendingAnalysisJob.status === 'running'
                                ? 'The AI is reviewing your document. This typically takes 1-3 minutes for large documents.'
                                : 'Your analysis job is queued. Make sure the queue worker is running.' }}
                        </p>
                        <div v-if="pendingAnalysisJob.progress_log?.length" class="mt-4 text-left w-full max-w-md">
                            <p class="text-xs font-medium text-muted-foreground mb-2">Progress:</p>
                            <div class="space-y-1 bg-muted/30 rounded p-2 max-h-32 overflow-y-auto">
                                <div
                                    v-for="(log, idx) in pendingAnalysisJob.progress_log"
                                    :key="idx"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ log.message }}
                                </div>
                            </div>
                        </div>
                        <p class="mt-4 text-xs text-muted-foreground">
                            Started {{ formatDate(pendingAnalysisJob.created_at) }}
                        </p>
                    </div>

                    <!-- Analysis Results (hidden when job is pending) -->
                    <div v-else-if="analysis" class="space-y-6">
                        <!-- Score and Rating -->
                        <div class="flex items-center gap-6 p-4 bg-muted/30 rounded-lg">
                            <div class="text-center">
                                <div
                                    class="text-4xl font-bold w-16 h-16 rounded-full flex items-center justify-center"
                                    :class="getRatingColor(analysis.overall_rating)"
                                >
                                    {{ analysis.overall_rating }}
                                </div>
                                <p class="text-sm text-muted-foreground mt-1">{{ getRatingLabel(analysis.overall_rating) }}</p>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="text-2xl font-semibold">{{ analysis.overall_score.toFixed(0) }}/100</span>
                                    <span class="text-sm text-muted-foreground">Overall Score</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2.5">
                                    <div
                                        class="h-2.5 rounded-full transition-all"
                                        :class="{
                                            'bg-green-500': analysis.overall_score >= 80,
                                            'bg-lime-500': analysis.overall_score >= 60 && analysis.overall_score < 80,
                                            'bg-yellow-500': analysis.overall_score >= 40 && analysis.overall_score < 60,
                                            'bg-orange-500': analysis.overall_score >= 20 && analysis.overall_score < 40,
                                            'bg-red-500': analysis.overall_score < 20,
                                        }"
                                        :style="{ width: `${analysis.overall_score}%` }"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        <!-- Summary -->
                        <div v-if="analysis.summary" class="rounded-lg bg-muted p-5">
                            <h4 class="font-semibold mb-3 text-lg">📋 Summary</h4>
                            <div
                                class="prose dark:prose-invert max-w-none prose-headings:text-lg prose-headings:font-semibold prose-headings:mt-4 prose-headings:mb-3 prose-p:my-3 prose-p:leading-relaxed prose-ul:my-3 prose-li:my-1 prose-li:leading-relaxed prose-strong:text-foreground"
                                v-html="renderedAnalysisSummary"
                            />
                        </div>

                        <!-- Flags Overview -->
                        <div class="grid gap-4 md:grid-cols-3">
                            <!-- Red Flags (Concerns) -->
                            <div class="border rounded-lg p-5 bg-red-50/50 dark:bg-red-950/20">
                                <div class="flex items-center gap-2 mb-4">
                                    <AlertTriangle class="h-5 w-5 text-red-500" />
                                    <h4 class="font-semibold text-red-700 dark:text-red-400">❌ Key Concerns</h4>
                                    <Badge variant="destructive" class="ml-auto">
                                        {{ analysis.flags?.red?.length ?? 0 }}
                                    </Badge>
                                </div>
                                <ul v-if="analysis.flags?.red?.length" class="space-y-3">
                                    <li
                                        v-for="(flag, idx) in (showAllFlags ? analysis.flags.red : analysis.flags.red.slice(0, 3))"
                                        :key="idx"
                                        class="flex items-start gap-2"
                                    >
                                        <ThumbsDown class="h-5 w-5 text-red-500 flex-shrink-0 mt-0.5" />
                                        <div>
                                            <span class="font-semibold">{{ formatFlagType(flag.type) }}</span>
                                            <p class="text-muted-foreground text-sm mt-1 leading-relaxed">{{ flag.description }}</p>
                                        </div>
                                    </li>
                                </ul>
                                <p v-else class="text-muted-foreground">No major concerns found</p>
                            </div>

                            <!-- Yellow Flags (Cautions) -->
                            <div class="border rounded-lg p-5 bg-yellow-50/50 dark:bg-yellow-950/20">
                                <div class="flex items-center gap-2 mb-4">
                                    <AlertTriangle class="h-5 w-5 text-yellow-500" />
                                    <h4 class="font-semibold text-yellow-700 dark:text-yellow-400">⚠️ Cautions</h4>
                                    <Badge variant="secondary" class="ml-auto bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                        {{ analysis.flags?.yellow?.length ?? 0 }}
                                    </Badge>
                                </div>
                                <ul v-if="analysis.flags?.yellow?.length" class="space-y-3">
                                    <li
                                        v-for="(flag, idx) in (showAllFlags ? analysis.flags.yellow : analysis.flags.yellow.slice(0, 3))"
                                        :key="idx"
                                        class="flex items-start gap-2"
                                    >
                                        <AlertTriangle class="h-5 w-5 text-yellow-500 flex-shrink-0 mt-0.5" />
                                        <div>
                                            <span class="font-semibold">{{ formatFlagType(flag.type) }}</span>
                                            <p class="text-muted-foreground text-sm mt-1 leading-relaxed">{{ flag.description }}</p>
                                        </div>
                                    </li>
                                </ul>
                                <p v-else class="text-muted-foreground">No cautions found</p>
                            </div>

                            <!-- Green Flags (Positives) -->
                            <div class="border rounded-lg p-5 bg-green-50/50 dark:bg-green-950/20">
                                <div class="flex items-center gap-2 mb-4">
                                    <ShieldCheck class="h-5 w-5 text-green-500" />
                                    <h4 class="font-semibold text-green-700 dark:text-green-400">✅ Positive Aspects</h4>
                                    <Badge variant="secondary" class="ml-auto bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                        {{ analysis.flags?.green?.length ?? 0 }}
                                    </Badge>
                                </div>
                                <ul v-if="analysis.flags?.green?.length" class="space-y-3">
                                    <li
                                        v-for="(flag, idx) in (showAllFlags ? analysis.flags.green : analysis.flags.green.slice(0, 3))"
                                        :key="idx"
                                        class="flex items-start gap-2"
                                    >
                                        <ThumbsUp class="h-5 w-5 text-green-500 flex-shrink-0 mt-0.5" />
                                        <div>
                                            <span class="font-semibold">{{ formatFlagType(flag.type) }}</span>
                                            <p class="text-muted-foreground text-sm mt-1 leading-relaxed">{{ flag.description }}</p>
                                        </div>
                                    </li>
                                </ul>
                                <p v-else class="text-muted-foreground">No positives found</p>
                            </div>
                        </div>

                        <!-- Show More/Less Toggle -->
                        <div
                            v-if="(analysis.flags?.red?.length ?? 0) > 3 || (analysis.flags?.yellow?.length ?? 0) > 3 || (analysis.flags?.green?.length ?? 0) > 3"
                            class="text-center"
                        >
                            <Button variant="ghost" size="sm" @click="showAllFlags = !showAllFlags">
                                <component :is="showAllFlags ? ChevronUp : ChevronDown" class="mr-2 h-4 w-4" />
                                {{ showAllFlags ? 'Show Less' : 'Show All Flags' }}
                            </Button>
                        </div>

                        <!-- Recommendations -->
                        <div v-if="analysis.recommendations" class="border rounded-lg p-5 bg-blue-50 dark:bg-blue-950/30">
                            <h4 class="font-semibold mb-3 text-lg text-blue-800 dark:text-blue-300 flex items-center gap-2">
                                <Lightbulb class="h-5 w-5" />
                                Recommendations
                            </h4>
                            <div
                                class="prose dark:prose-invert max-w-none prose-headings:text-lg prose-headings:font-semibold prose-headings:mt-4 prose-headings:mb-3 prose-p:my-3 prose-p:leading-relaxed prose-ul:my-3 prose-li:my-1 prose-li:leading-relaxed prose-strong:text-foreground text-blue-900 dark:text-blue-100"
                                v-html="renderedAnalysisRecommendations"
                            />
                        </div>

                        <!-- FAQ Section -->
                        <div v-if="analysis.extracted_data?.faq?.length">
                            <h4 class="font-semibold mb-4 text-lg flex items-center gap-2">
                                <HelpCircle class="h-5 w-5" />
                                ❓ Frequently Asked Questions
                            </h4>
                            <div class="space-y-3">
                                <div
                                    v-for="(faq, idx) in analysis.extracted_data.faq"
                                    :key="idx"
                                    class="border rounded-lg overflow-hidden"
                                >
                                    <button
                                        class="w-full flex items-center justify-between p-4 text-left hover:bg-muted/50 transition-colors cursor-pointer"
                                        @click="toggleFaq(idx)"
                                    >
                                        <span class="font-medium">{{ faq.question }}</span>
                                        <component :is="expandedFaqIndex === idx ? ChevronUp : ChevronDown" class="h-5 w-5 flex-shrink-0 ml-2" />
                                    </button>
                                    <div v-if="expandedFaqIndex === idx" class="px-4 pb-4 pt-2 border-t bg-muted/30">
                                        <p class="font-semibold mb-2 text-green-700 dark:text-green-400">✅ {{ faq.short_answer }}</p>
                                        <p class="text-muted-foreground leading-relaxed">{{ faq.long_answer }}</p>
                                        <div v-if="faq.what_to_watch_for" class="mt-3 text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/30 p-3 rounded-lg">
                                            <strong>⚠️ Watch for:</strong> {{ faq.what_to_watch_for }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Processing Errors Warning -->
                        <div v-if="analysis.has_errors" class="border border-amber-300 bg-amber-50 rounded-lg p-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <CircleAlert class="h-5 w-5 text-amber-600" />
                                    <span class="font-semibold text-amber-800">
                                        Analysis completed with {{ analysis.processing_errors?.length }} error(s)
                                    </span>
                                </div>
                                <Button variant="ghost" size="sm" @click="showProcessingErrors = !showProcessingErrors">
                                    <component :is="showProcessingErrors ? ChevronUp : ChevronDown" class="h-4 w-4" />
                                </Button>
                            </div>
                            <p class="text-sm text-amber-700 mt-1">
                                Some sections could not be analyzed. The score may be incomplete.
                            </p>
                            <div v-if="showProcessingErrors && analysis.processing_errors" class="mt-3 space-y-1">
                                <div
                                    v-for="(error, idx) in analysis.processing_errors"
                                    :key="idx"
                                    class="text-xs text-amber-900 bg-amber-100 px-2 py-1 rounded"
                                >
                                    {{ error }}
                                </div>
                            </div>
                        </div>

                        <!-- Analysis Meta -->
                        <div class="flex flex-wrap items-center gap-4 text-xs text-muted-foreground border-t pt-4">
                            <span>Model: <strong>{{ analysis.model_used }}</strong></span>
                            <span>Tokens: {{ analysis.tokens_used.toLocaleString() }}</span>
                            <span v-if="analysis.analysis_cost > 0">Cost: ${{ analysis.analysis_cost.toFixed(4) }}</span>
                            <span>Analyzed: {{ formatDate(analysis.created_at) }}</span>
                            <Button
                                v-if="analysisHistory.length > 1"
                                variant="ghost"
                                size="sm"
                                class="ml-auto"
                                @click="showAnalysisHistory = !showAnalysisHistory"
                            >
                                <History class="mr-1 h-3 w-3" />
                                History ({{ analysisHistory.length }})
                            </Button>
                        </div>

                        <!-- Analysis History -->
                        <div v-if="showAnalysisHistory && analysisHistory.length > 0" class="border-t pt-4 mt-2">
                            <h4 class="text-sm font-semibold mb-2 flex items-center gap-2">
                                <History class="h-4 w-4" />
                                Analysis History
                            </h4>
                            <div class="space-y-2">
                                <div
                                    v-for="item in analysisHistory"
                                    :key="item.id"
                                    class="flex items-center justify-between text-sm p-2 rounded"
                                    :class="item.is_current ? 'bg-primary/10 border border-primary/30' : 'bg-muted/30'"
                                >
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
                                            :class="getRatingColor(item.overall_rating)"
                                        >
                                            {{ item.overall_rating }}
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium">{{ item.model_used }}</span>
                                                <Badge v-if="item.is_current" variant="outline" class="text-xs">Current</Badge>
                                                <Badge v-if="item.has_errors" variant="destructive" class="text-xs">
                                                    {{ item.error_count }} errors
                                                </Badge>
                                            </div>
                                            <div class="text-xs text-muted-foreground">
                                                Score: {{ item.overall_score.toFixed(0) }}
                                                &middot; {{ item.tokens_used.toLocaleString() }} tokens
                                                <span v-if="item.analysis_cost > 0">&middot; ${{ item.analysis_cost.toFixed(4) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="text-xs text-muted-foreground">
                                        {{ formatDate(item.created_at) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- No Analysis State -->
                    <div v-else class="flex flex-col items-center justify-center py-8 text-center">
                        <Brain class="h-12 w-12 text-muted-foreground" />
                        <h3 class="mt-4 text-lg font-semibold">No AI Analysis Yet</h3>
                        <p class="mt-2 text-sm text-muted-foreground max-w-md">
                            Run an AI analysis to get a plain-English summary of this document's terms,
                            identify potential concerns, and get answers to common questions.
                        </p>
                        <Button class="mt-4" @click="runAiAnalysis" :disabled="isAnalyzing">
                            <Loader2 v-if="isAnalyzing" class="mr-2 h-4 w-4 animate-spin" />
                            <Brain v-else class="mr-2 h-4 w-4" />
                            Analyze with AI
                        </Button>
                    </div>
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
                    <CardTitle>Current Content</CardTitle>
                    <CardDescription>
                        Version {{ currentVersion.version_number }} &middot;
                        Retrieved {{ formatDate(currentVersion.scraped_at) }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <!-- Tab Buttons -->
                        <div class="flex flex-wrap gap-2 border-b">
                            <button
                                :class="[
                                    'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                    activeContentTab === 'rendered'
                                        ? 'border-b-2 border-primary text-primary'
                                        : 'text-muted-foreground hover:text-foreground'
                                ]"
                                @click="activeContentTab = 'rendered'"
                            >
                                <Eye class="mr-2 h-4 w-4" />
                                Rendered HTML
                            </button>
                            <button
                                :class="[
                                    'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                    activeContentTab === 'markdown-rendered'
                                        ? 'border-b-2 border-primary text-primary'
                                        : 'text-muted-foreground hover:text-foreground',
                                    !currentVersion.content_markdown ? 'opacity-50 cursor-not-allowed' : ''
                                ]"
                                :disabled="!currentVersion.content_markdown"
                                @click="currentVersion.content_markdown && (activeContentTab = 'markdown-rendered')"
                            >
                                <FileCode class="mr-2 h-4 w-4" />
                                Markdown Preview
                            </button>
                            <button
                                :class="[
                                    'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                    activeContentTab === 'text'
                                        ? 'border-b-2 border-primary text-primary'
                                        : 'text-muted-foreground hover:text-foreground'
                                ]"
                                @click="activeContentTab = 'text'"
                            >
                                <Type class="mr-2 h-4 w-4" />
                                Plain Text
                            </button>
                            <button
                                :class="[
                                    'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                    activeContentTab === 'markdown'
                                        ? 'border-b-2 border-primary text-primary'
                                        : 'text-muted-foreground hover:text-foreground',
                                    !currentVersion.content_markdown ? 'opacity-50 cursor-not-allowed' : ''
                                ]"
                                :disabled="!currentVersion.content_markdown"
                                @click="currentVersion.content_markdown && (activeContentTab = 'markdown')"
                            >
                                <BookOpen class="mr-2 h-4 w-4" />
                                Markdown Source
                            </button>
                            <button
                                :class="[
                                    'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                    activeContentTab === 'html'
                                        ? 'border-b-2 border-primary text-primary'
                                        : 'text-muted-foreground hover:text-foreground'
                                ]"
                                @click="activeContentTab = 'html'"
                            >
                                <Code class="mr-2 h-4 w-4" />
                                HTML Source
                            </button>
                        </div>

                        <!-- Content -->
                        <div v-if="activeContentTab === 'rendered'" class="rounded-lg border bg-white overflow-hidden">
                            <iframe
                                :srcdoc="iframeHtml"
                                class="w-full h-[600px] border-0"
                                sandbox="allow-same-origin"
                                title="Rendered HTML content"
                            ></iframe>
                        </div>
                        <div v-else-if="activeContentTab === 'markdown-rendered'" class="max-h-[600px] overflow-auto rounded-lg border bg-white dark:bg-slate-900 p-6">
                            <article class="prose prose-sm dark:prose-invert max-w-none" v-html="renderedMarkdown"></article>
                        </div>
                        <div v-else-if="activeContentTab === 'text'" class="max-h-[600px] overflow-auto rounded-lg border bg-muted/30 p-4">
                            <pre class="whitespace-pre-wrap font-mono text-sm">{{ currentVersion.content_text }}</pre>
                        </div>
                        <div v-else-if="activeContentTab === 'markdown'" class="max-h-[600px] overflow-auto rounded-lg border bg-muted/30 p-4">
                            <pre class="whitespace-pre-wrap font-mono text-sm">{{ currentVersion.content_markdown || 'No markdown content available' }}</pre>
                        </div>
                        <div v-else-if="activeContentTab === 'html'" class="max-h-[600px] overflow-auto rounded-lg border bg-muted/30 p-4">
                            <pre class="whitespace-pre-wrap font-mono text-xs">{{ currentVersion.content_raw }}</pre>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- No Content State -->
            <Card v-else>
                <CardContent class="flex flex-col items-center justify-center py-12">
                    <FileText class="h-12 w-12 text-muted-foreground" />
                    <h3 class="mt-4 text-lg font-semibold">No content yet</h3>
                    <p class="mt-2 text-center text-sm text-muted-foreground">
                        This document hasn't been retrieved yet.
                    </p>
                    <Button class="mt-4" @click="scrapeNow" :disabled="isScraping">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Retrieve Now
                    </Button>
                </CardContent>
            </Card>

            <!-- Version History -->
            <Card v-if="versions.length > 0">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <History class="h-5 w-5" />
                                Version History
                            </CardTitle>
                            <CardDescription>
                                {{ versions.length }} version{{ versions.length !== 1 ? 's' : '' }} tracked
                            </CardDescription>
                        </div>
                        <div class="flex items-center gap-2">
                            <!-- Cancel button when in compare mode -->
                            <Button
                                v-if="compareMode"
                                variant="outline"
                                size="sm"
                                @click="toggleCompareMode"
                            >
                                Cancel
                            </Button>
                            <!-- Main Compare Versions button -->
                            <Button
                                v-if="versions.length >= 2"
                                :class="{
                                    'bg-green-600 hover:bg-green-700 text-white': canCompare(),
                                }"
                                size="sm"
                                @click="handleCompareClick"
                            >
                                <GitCompare class="mr-2 h-4 w-4" />
                                <span v-if="!compareMode">Compare Versions</span>
                                <span v-else-if="canCompare()">Compare Now</span>
                                <span v-else>Select 2 Versions</span>
                            </Button>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <!-- Compare mode instructions -->
                    <div v-if="compareMode && !canCompare()" class="mb-4 rounded-lg bg-blue-50 p-3 dark:bg-blue-950/30">
                        <p class="text-sm text-blue-700 dark:text-blue-300">
                            <GitCompare class="mr-2 inline h-4 w-4" />
                            Select {{ 2 - selectedVersions.length }} version{{ selectedVersions.length === 1 ? '' : 's' }} to compare by clicking the checkboxes below
                        </p>
                    </div>

                    <!-- Ready to compare message -->
                    <div v-if="compareMode && canCompare()" class="mb-4 rounded-lg bg-green-50 p-3 dark:bg-green-950/30">
                        <p class="text-sm text-green-700 dark:text-green-300">
                            <GitCompare class="mr-2 inline h-4 w-4" />
                            2 versions selected! Click the green <strong>Compare Now</strong> button above to view the diff.
                        </p>
                    </div>

                    <div class="space-y-2">
                        <div
                            v-for="(version, versionIndex) in versions"
                            :key="version.id"
                            class="flex items-center justify-between rounded-lg border p-3 transition-colors"
                            :class="{
                                'bg-muted/50': version.is_current && !compareMode,
                                'bg-green-50 dark:bg-green-950/30 border-green-400': compareMode && isVersionSelected(version.id),
                                'cursor-pointer hover:bg-muted/50': compareMode,
                            }"
                            @click="compareMode && toggleVersionSelection(version.id)"
                        >
                            <div class="flex items-center gap-3">
                                <!-- Checkbox in compare mode -->
                                <div
                                    v-if="compareMode"
                                    class="flex h-6 w-6 items-center justify-center rounded border-2 transition-colors"
                                    :class="{
                                        'border-green-500 bg-green-500 text-white': isVersionSelected(version.id),
                                        'border-gray-300 hover:border-gray-400': !isVersionSelected(version.id),
                                    }"
                                >
                                    <svg v-if="isVersionSelected(version.id)" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>

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
                            <div class="flex items-center gap-3">
                                <span class="font-mono text-xs text-muted-foreground">
                                    {{ version.content_hash.substring(0, 12) }}...
                                </span>
                                <Link v-if="!compareMode" :href="`/queue/version/${version.id}`" @click.stop>
                                    <Button variant="ghost" size="sm">
                                        <ArrowRight class="h-4 w-4" />
                                    </Button>
                                </Link>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Recent Retrieval Jobs -->
            <Card v-if="scrapeJobs.length > 0">
                <CardHeader>
                    <CardTitle>Recent Retrieval Jobs</CardTitle>
                    <CardDescription>Last 10 retrieval attempts</CardDescription>
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
        </div>
    </AppLayout>
</template>
