<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    FileText,
    ExternalLink,
    ArrowLeft,
    ArrowRight,
    Globe,
    Building2,
    Download,
    Loader2,
    Clock,
    History,
    Brain,
    Tag,
    Link2,
    Hash,
    Search,
    Percent,
    TrendingUp,
    AlertCircle,
    GitCompare,
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
import { ref, computed } from 'vue';

interface VersionAnalysis {
    id: number;
    overall_score: number;
    overall_rating: string;
    summary: string;
    tags: string[];
    model_used: string;
    created_at: string;
}

interface DocumentVersion {
    id: number;
    version_number: string;
    scraped_at: string | null;
    word_count: number;
    is_current: boolean;
    content_hash: string;
    analysis: VersionAnalysis | null;
}

interface Policy {
    url: string;
    detected_type: string | null;
    document_type_id: number | null;
    confidence: number;
    discovery_method: string;
    link_text?: string;
}

interface ExistingDocument {
    id: number;
    source_url: string;
    document_type: string | null;
    scrape_status: string;
    last_scraped_at: string | null;
    versions: DocumentVersion[];
}

interface Props {
    policy: Policy;
    website: {
        id: number;
        url: string;
    };
    company: {
        id: number;
        name: string;
    };
    index: number;
    totalPolicies: number;
    discoveryJobId: number | null;
    discoveryIndex: number | null;
    document: ExistingDocument | null;
}

const props = defineProps<Props>();

const isRetrieving = ref(false);

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
    if (!props.document || selectedVersions.value.length !== 2) return '';
    // Sort so older version is first (lower ID typically means older)
    const sorted = [...selectedVersions.value].sort((a, b) => a - b);
    return `/documents/${props.document.id}/compare/${sorted[0]}/${sorted[1]}`;
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

// Get the current version (first one with is_current=true, or the first one)
const currentVersion = computed(() => {
    if (!props.document?.versions?.length) return null;
    return props.document.versions.find(v => v.is_current) || props.document.versions[0];
});

// Get the current analysis from the current version
const currentAnalysis = computed(() => currentVersion.value?.analysis || null);

function retrievePolicy() {
    if (!props.discoveryJobId || props.discoveryIndex === null) return;

    isRetrieving.value = true;
    router.post(`/queue/discovery/${props.discoveryJobId}/policy/${props.discoveryIndex}/retrieve`, {}, {
        preserveScroll: true,
        onFinish: () => {
            isRetrieving.value = false;
        },
    });
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Companies', href: '/companies' },
    { title: props.company.name, href: `/companies/${props.company.id}` },
    { title: `Policy ${props.index + 1}`, href: `/companies/${props.company.id}/policy/${props.index}` },
];

function formatDetectedType(type: string | null): string {
    if (!type) return 'Unknown Policy';
    const smallWords = ['of', 'the', 'a', 'an', 'and', 'or', 'for', 'to', 'in', 'on'];
    return type
        .replace(/-/g, ' ')
        .split(' ')
        .map((word, index) => {
            if (index > 0 && smallWords.includes(word.toLowerCase())) {
                return word.toLowerCase();
            }
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        })
        .join(' ');
}

function formatVersionDate(dateString: string | null): string {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function getGradeColor(grade: string): string {
    switch (grade) {
        case 'A': return 'bg-green-500';
        case 'B': return 'bg-lime-500';
        case 'C': return 'bg-yellow-500';
        case 'D': return 'bg-orange-500';
        case 'F': return 'bg-red-500';
        default: return 'bg-gray-500';
    }
}

function getGradeLabel(grade: string): string {
    switch (grade) {
        case 'A': return 'Excellent';
        case 'B': return 'Good';
        case 'C': return 'Fair';
        case 'D': return 'Poor';
        case 'F': return 'Failing';
        default: return 'Unknown';
    }
}

function getGradeBgColor(grade: string): string {
    switch (grade) {
        case 'A': return 'bg-green-100 dark:bg-green-900/30';
        case 'B': return 'bg-lime-100 dark:bg-lime-900/30';
        case 'C': return 'bg-yellow-100 dark:bg-yellow-900/30';
        case 'D': return 'bg-orange-100 dark:bg-orange-900/30';
        case 'F': return 'bg-red-100 dark:bg-red-900/30';
        default: return 'bg-gray-100 dark:bg-gray-900/30';
    }
}

function getGradeTextColor(grade: string): string {
    switch (grade) {
        case 'A': return 'text-green-700 dark:text-green-400';
        case 'B': return 'text-lime-700 dark:text-lime-400';
        case 'C': return 'text-yellow-700 dark:text-yellow-400';
        case 'D': return 'text-orange-700 dark:text-orange-400';
        case 'F': return 'text-red-700 dark:text-red-400';
        default: return 'text-gray-700 dark:text-gray-400';
    }
}

function getConfidenceColor(confidence: number): string {
    if (confidence >= 0.8) return 'text-green-600 dark:text-green-400';
    if (confidence >= 0.5) return 'text-yellow-600 dark:text-yellow-400';
    return 'text-red-600 dark:text-red-400';
}

function formatConfidence(confidence: number): string {
    return `${Math.round(confidence * 100)}%`;
}

function formatDiscoveryMethod(method: string): string {
    const methods: Record<string, string> = {
        'crawl': 'Web Crawl',
        'sitemap': 'Sitemap',
        'manual': 'Manual',
        'footer': 'Footer Link',
        'header': 'Header Link',
    };
    return methods[method] || method;
}

function formatShortDate(dateString: string | null): string {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function getPreviousVersion(versionIndex: number) {
    if (!props.document?.versions) return null;
    // versions are ordered by scraped_at desc, so next index is the previous version
    if (versionIndex < props.document.versions.length - 1) {
        return props.document.versions[versionIndex + 1];
    }
    return null;
}
</script>

<template>
    <Head :title="`Policy - ${formatDetectedType(policy.detected_type)} - ${company.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900">
                        <FileText class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold">{{ formatDetectedType(policy.detected_type) }}</h1>
                        <div class="mt-1 flex items-center gap-3 text-sm text-muted-foreground">
                            <Link :href="`/companies/${company.id}`" class="hover:text-foreground">
                                {{ company.name }}
                            </Link>
                            <span>Policy {{ index + 1 }} of {{ totalPolicies }}</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="index > 0"
                        :href="`/companies/${company.id}/policy/${index - 1}`"
                    >
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Previous
                        </Button>
                    </Link>
                    <Link
                        v-if="index < totalPolicies - 1"
                        :href="`/companies/${company.id}/policy/${index + 1}`"
                    >
                        <Button variant="outline" size="sm">
                            Next
                            <ArrowRight class="ml-2 h-4 w-4" />
                        </Button>
                    </Link>
                    <a :href="policy.url" target="_blank" rel="noopener noreferrer">
                        <Button variant="outline">
                            <ExternalLink class="mr-2 h-4 w-4" />
                            Open URL
                        </Button>
                    </a>
                    <Button
                        v-if="discoveryJobId !== null && discoveryIndex !== null && !document"
                        @click="retrievePolicy"
                        :disabled="isRetrieving"
                    >
                        <Loader2 v-if="isRetrieving" class="mr-2 h-4 w-4 animate-spin" />
                        <Download v-else class="mr-2 h-4 w-4" />
                        Retrieve
                    </Button>
                </div>
            </div>

            <!-- Hero Section: Analysis Summary (if current version has analysis) -->
            <Card v-if="currentAnalysis" :class="getGradeBgColor(currentAnalysis.overall_rating)">
                <CardContent class="py-6">
                    <div class="flex flex-col gap-6 md:flex-row md:items-start md:justify-between">
                        <!-- Grade & Score -->
                        <div class="flex items-center gap-6">
                            <div class="flex flex-col items-center">
                                <div
                                    :class="[getGradeColor(currentAnalysis.overall_rating), 'flex h-20 w-20 items-center justify-center rounded-full text-white shadow-lg']"
                                >
                                    <span class="text-4xl font-bold">{{ currentAnalysis.overall_rating }}</span>
                                </div>
                                <span class="mt-2 text-sm font-medium" :class="getGradeTextColor(currentAnalysis.overall_rating)">
                                    {{ getGradeLabel(currentAnalysis.overall_rating) }}
                                </span>
                            </div>
                            <div class="flex flex-col gap-1">
                                <div class="flex items-baseline gap-2">
                                    <span class="text-3xl font-bold" :class="getGradeTextColor(currentAnalysis.overall_rating)">
                                        {{ currentAnalysis.overall_score.toFixed(1) }}
                                    </span>
                                    <span class="text-lg text-muted-foreground">/ 100</span>
                                </div>
                                <span class="text-sm text-muted-foreground">Overall Score</span>
                            </div>
                        </div>

                        <!-- Summary -->
                        <div class="flex-1 md:max-w-2xl">
                            <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                                <Brain class="h-4 w-4" />
                                AI Analysis Summary
                            </h3>
                            <p class="text-sm leading-relaxed">
                                {{ currentAnalysis.summary || 'No summary available.' }}
                            </p>
                            <div v-if="currentAnalysis.tags?.length" class="mt-3 flex flex-wrap gap-1.5">
                                <Badge
                                    v-for="tag in currentAnalysis.tags.slice(0, 8)"
                                    :key="tag"
                                    variant="secondary"
                                    class="text-xs"
                                >
                                    {{ tag }}
                                </Badge>
                                <Badge
                                    v-if="currentAnalysis.tags.length > 8"
                                    variant="outline"
                                    class="text-xs"
                                >
                                    +{{ currentAnalysis.tags.length - 8 }} more
                                </Badge>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-col gap-2">
                            <Link v-if="document" :href="`/documents/${document.id}`">
                                <Button variant="outline" size="sm" class="w-full">
                                    <FileText class="mr-2 h-4 w-4" />
                                    View Document
                                </Button>
                            </Link>
                            <Link v-if="currentVersion" :href="`/queue/version/${currentVersion.id}`">
                                <Button variant="outline" size="sm" class="w-full">
                                    <TrendingUp class="mr-2 h-4 w-4" />
                                    Full Analysis
                                </Button>
                            </Link>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- No Analysis Yet -->
            <Card v-else-if="document && currentVersion" class="border-yellow-200 bg-yellow-50 dark:border-yellow-900 dark:bg-yellow-900/20">
                <CardContent class="py-6">
                    <div class="flex items-center gap-4">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-yellow-100 dark:bg-yellow-900">
                            <AlertCircle class="h-6 w-6 text-yellow-600 dark:text-yellow-400" />
                        </div>
                        <div class="flex-1">
                            <h3 class="font-semibold text-yellow-800 dark:text-yellow-200">Ready for Analysis</h3>
                            <p class="text-sm text-yellow-700 dark:text-yellow-300">
                                This policy has been retrieved but not yet analyzed by AI.
                            </p>
                        </div>
                        <Link :href="`/documents/${document.id}`">
                            <Button variant="outline" size="sm">
                                <Brain class="mr-2 h-4 w-4" />
                                Analyze
                            </Button>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <!-- Policy URL & Detection Info -->
            <div class="grid gap-6 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Link2 class="h-5 w-5" />
                            Policy URL
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="rounded-lg bg-muted p-4">
                            <p class="break-all font-mono text-sm">{{ policy.url }}</p>
                        </div>
                        <div class="mt-4 flex items-center gap-4">
                            <a
                                :href="policy.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                            >
                                <ExternalLink class="h-4 w-4" />
                                Open in new tab
                            </a>
                            <span class="text-sm text-muted-foreground">
                                <Building2 class="mr-1 inline h-4 w-4" />
                                {{ company.name }}
                            </span>
                            <span class="text-sm text-muted-foreground">
                                <Globe class="mr-1 inline h-4 w-4" />
                                {{ website.url }}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Detection Info</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-muted-foreground">Type</span>
                            <span class="text-sm font-medium">{{ formatDetectedType(policy.detected_type) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-muted-foreground">Confidence</span>
                            <span class="text-sm font-semibold" :class="getConfidenceColor(policy.confidence)">
                                {{ formatConfidence(policy.confidence) }}
                            </span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full transition-all"
                                :class="{
                                    'bg-green-500': policy.confidence >= 0.8,
                                    'bg-yellow-500': policy.confidence >= 0.5 && policy.confidence < 0.8,
                                    'bg-red-500': policy.confidence < 0.5
                                }"
                                :style="{ width: `${policy.confidence * 100}%` }"
                            />
                        </div>
                        <div class="flex items-center justify-between pt-1">
                            <span class="text-sm text-muted-foreground">Method</span>
                            <Badge variant="secondary" class="text-xs">
                                {{ formatDiscoveryMethod(policy.discovery_method) }}
                            </Badge>
                        </div>
                        <div v-if="policy.link_text" class="border-t pt-3">
                            <span class="text-xs text-muted-foreground">Link text:</span>
                            <p class="mt-1 rounded bg-muted px-2 py-1 text-sm italic">"{{ policy.link_text }}"</p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Version History -->
            <Card v-if="document && document.versions.length > 0">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <History class="h-5 w-5" />
                                Version History
                            </CardTitle>
                            <CardDescription>
                                {{ document.versions.length }} version{{ document.versions.length !== 1 ? 's' : '' }} tracked
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
                                v-if="document.versions.length >= 2"
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
                            <Link :href="`/documents/${document.id}`">
                                <Button variant="outline" size="sm">
                                    <FileText class="mr-2 h-4 w-4" />
                                    View Document
                                </Button>
                            </Link>
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
                            v-for="(version, versionIndex) in document.versions"
                            :key="version.id"
                            class="flex items-center justify-between rounded-lg border p-3 transition-colors"
                            :class="{
                                'hover:bg-muted/50': !compareMode,
                                'bg-green-50 dark:bg-green-950/30 border-green-400': compareMode && isVersionSelected(version.id),
                                'cursor-pointer hover:bg-muted/50': compareMode,
                            }"
                            @click="compareMode && toggleVersionSelection(version.id)"
                        >
                            <component
                                :is="compareMode ? 'div' : Link"
                                :href="compareMode ? undefined : `/queue/version/${version.id}`"
                                class="flex flex-1 items-center gap-3"
                                :class="{ 'cursor-pointer': !compareMode }"
                            >
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

                                <!-- Grade badge or version number -->
                                <div
                                    v-if="version.analysis"
                                    :class="[getGradeColor(version.analysis.overall_rating), 'flex h-10 w-10 items-center justify-center rounded-full text-white font-bold']"
                                >
                                    {{ version.analysis.overall_rating }}
                                </div>
                                <div v-else class="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                                    <span class="text-sm font-medium text-muted-foreground">v{{ version.version_number }}</span>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">Version {{ version.version_number }}</span>
                                        <Badge v-if="version.is_current" variant="default" class="text-xs">
                                            Current
                                        </Badge>
                                        <Badge v-if="!version.analysis" variant="outline" class="text-xs text-yellow-600">
                                            Not Analyzed
                                        </Badge>
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ formatShortDate(version.scraped_at) }} · {{ version.word_count.toLocaleString() }} words
                                    </div>
                                </div>
                            </component>
                            <div v-if="!compareMode" class="flex items-center gap-3">
                                <!-- Score if analyzed -->
                                <div v-if="version.analysis" class="text-right">
                                    <div class="text-sm font-semibold" :class="getGradeTextColor(version.analysis.overall_rating)">
                                        {{ version.analysis.overall_score.toFixed(1) }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">score</div>
                                </div>
                                <!-- Compare with previous button -->
                                <Link
                                    v-if="getPreviousVersion(versionIndex)"
                                    :href="`/documents/${document.id}/compare/${getPreviousVersion(versionIndex)!.id}/${version.id}`"
                                    @click.stop
                                >
                                    <Button variant="outline" size="sm" title="Compare with previous version">
                                        <GitCompare class="h-4 w-4" />
                                    </Button>
                                </Link>
                                <Link :href="`/queue/version/${version.id}`">
                                    <ArrowRight class="h-4 w-4 text-muted-foreground" />
                                </Link>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Document Status (if document exists but no versions yet) -->
            <Card v-else-if="document">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Clock class="h-5 w-5 text-yellow-500" />
                        Document Pending
                    </CardTitle>
                    <CardDescription>
                        Document created, waiting for retrieval to complete
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-muted-foreground">
                            Status: <Badge variant="outline">{{ document.scrape_status }}</Badge>
                        </div>
                        <Link :href="`/documents/${document.id}`">
                            <Button variant="outline" size="sm">
                                <FileText class="mr-2 h-4 w-4" />
                                View Document
                            </Button>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <!-- Not Retrieved Yet -->
            <Card v-else class="border-dashed">
                <CardContent class="py-8">
                    <div class="flex flex-col items-center justify-center text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-muted mb-4">
                            <Download class="h-6 w-6 text-muted-foreground" />
                        </div>
                        <h3 class="font-semibold">Policy Not Retrieved</h3>
                        <p class="mt-1 text-sm text-muted-foreground max-w-md">
                            This policy URL was discovered but hasn't been retrieved yet. Click Retrieve to download and analyze it.
                        </p>
                        <Button
                            v-if="discoveryJobId !== null && discoveryIndex !== null"
                            @click="retrievePolicy"
                            :disabled="isRetrieving"
                            class="mt-4"
                        >
                            <Loader2 v-if="isRetrieving" class="mr-2 h-4 w-4 animate-spin" />
                            <Download v-else class="mr-2 h-4 w-4" />
                            Retrieve Policy
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Navigation -->
            <Card>
                <CardContent class="py-4">
                    <div class="flex items-center justify-between">
                        <Link :href="`/companies/${company.id}`">
                            <Button variant="outline">
                                <ArrowLeft class="mr-2 h-4 w-4" />
                                Back to Company
                            </Button>
                        </Link>
                        <div class="flex items-center gap-2">
                            <Link
                                v-if="index > 0"
                                :href="`/companies/${company.id}/policy/${index - 1}`"
                            >
                                <Button variant="outline" size="sm">
                                    <ArrowLeft class="mr-2 h-4 w-4" />
                                    Previous
                                </Button>
                            </Link>
                            <span class="text-sm text-muted-foreground px-2">
                                {{ index + 1 }} / {{ totalPolicies }}
                            </span>
                            <Link
                                v-if="index < totalPolicies - 1"
                                :href="`/companies/${company.id}/policy/${index + 1}`"
                            >
                                <Button variant="outline" size="sm">
                                    Next
                                    <ArrowRight class="ml-2 h-4 w-4" />
                                </Button>
                            </Link>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
