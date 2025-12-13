<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Plus,
    Minus,
    AlertTriangle,
    Calendar,
    FileText,
    TrendingDown,
    TrendingUp,
    Minus as MinusIcon,
    Clock,
    Loader2,
    Brain,
    ChevronDown,
    ChevronUp,
    Cpu,
    Send,
    FileSearch,
    Scale,
    FileCheck,
    CheckCircle,
    PanelLeftClose,
    PanelLeft,
    ArrowUpRight,
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
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';

interface DiffLine {
    type: 'unchanged' | 'added' | 'removed';
    oldLine: number | null;
    newLine: number | null;
    content: string;
}

interface DiffBlock {
    type: 'unchanged' | 'added' | 'removed' | 'collapsed';
    lines: DiffLine[];
    skippedLines?: number;
    startOldLine?: number | null;
    startNewLine?: number | null;
}

interface DiffStats {
    linesAdded: number;
    linesRemoved: number;
    linesUnchanged: number;
    totalLines: number;
    changePercentage: number;
}

interface BehavioralSignal {
    type: string;
    severity: string;
    penalty: number;
    holiday?: string;
    timing?: string;
    description: string;
    details?: string;
}

interface BehavioralAnalysis {
    signals: BehavioralSignal[];
    penalty: number;
    risk_score: number;
    summary?: string;
}

interface ChangeItem {
    category: string;
    title: string;
    old_text: string;
    new_text: string;
    impact: 'positive' | 'negative' | 'neutral';
    severity: number;
    explanation: string;
}

interface ChangeSummary {
    summary: string;
    changes: ChangeItem[];
    risk_assessment: string;
    recommendations: string;
}

interface Props {
    document: {
        id: number;
        source_url: string;
        document_type: string;
        company: {
            id: number;
            name: string;
        };
    };
    oldVersion: {
        id: number;
        version_number: string;
        scraped_at: string | null;
        word_count: number;
    };
    newVersion: {
        id: number;
        version_number: string;
        scraped_at: string | null;
        word_count: number;
    };
    diff: {
        blocks: DiffBlock[];
        stats: DiffStats;
    };
    behavioralSignals: BehavioralAnalysis | null;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Companies', href: '/companies' },
    { title: props.document.company.name, href: `/companies/${props.document.company.id}` },
    { title: props.document.document_type, href: `/documents/${props.document.id}` },
    { title: 'Compare Versions', href: '#' },
];

// State
const isLoadingSummary = ref(false);
const changeSummary = ref<ChangeSummary | null>(null);
const summaryError = ref<string | null>(null);
const expandedBlocks = ref<Set<number>>(new Set());
const loadingStep = ref(0);
const showChangesSidebar = ref(true);
const activeChangeIndex = ref<number | null>(null);
const diffContainerRef = ref<HTMLElement | null>(null);
const highlightedBlockIndices = ref<Set<number>>(new Set());

// Computed: Extract list of changes from diff blocks for sidebar navigation
interface ChangeNavItem {
    blockIndices: number[];  // Can be multiple for modified (removed+added pair)
    type: 'added' | 'removed' | 'modified';
    preview: string;
    lineCount: number;
    startLine: number | null;
}

const changeNavItems = computed<ChangeNavItem[]>(() => {
    const items: ChangeNavItem[] = [];
    const blocks = props.diff.blocks;
    const processedIndices = new Set<number>();

    blocks.forEach((block, blockIndex) => {
        if (processedIndices.has(blockIndex)) return;
        if (block.type !== 'added' && block.type !== 'removed') return;

        // Check if this is part of a removed+added pair (modification)
        const nextBlock = blocks[blockIndex + 1];
        const isModified = (
            (block.type === 'removed' && nextBlock?.type === 'added') ||
            (block.type === 'added' && nextBlock?.type === 'removed')
        );

        if (isModified) {
            // Group as "modified" - mark both as processed
            processedIndices.add(blockIndex);
            processedIndices.add(blockIndex + 1);

            const removedBlock = block.type === 'removed' ? block : nextBlock;
            const addedBlock = block.type === 'added' ? block : nextBlock;

            // Preview shows what it changed to (the added content)
            const firstAddedLine = addedBlock.lines.find(l => l.content.trim().length > 0);
            const preview = firstAddedLine?.content.trim().slice(0, 80) || '(empty)';

            items.push({
                blockIndices: [blockIndex, blockIndex + 1],
                type: 'modified',
                preview: preview.length === 80 ? preview + '...' : preview,
                lineCount: removedBlock.lines.length + addedBlock.lines.length,
                startLine: removedBlock.lines[0]?.oldLine || addedBlock.lines[0]?.newLine,
            });
        } else {
            // Single added or removed block
            processedIndices.add(blockIndex);

            const firstLine = block.lines.find(l => l.content.trim().length > 0);
            const preview = firstLine?.content.trim().slice(0, 80) || '(empty)';

            items.push({
                blockIndices: [blockIndex],
                type: block.type,
                preview: preview.length === 80 ? preview + '...' : preview,
                lineCount: block.lines.length,
                startLine: block.type === 'added'
                    ? block.lines[0]?.newLine
                    : block.lines[0]?.oldLine,
            });
        }
    });

    return items;
});

function scrollToChange(index: number) {
    activeChangeIndex.value = index;
    const item = changeNavItems.value[index];
    if (!item) return;

    // Set all block indices for this change item (handles modified pairs)
    highlightedBlockIndices.value = new Set(item.blockIndices);

    // Scroll to the first block in the right diff view
    const firstBlockIndex = Math.min(...item.blockIndices);
    const element = document.getElementById(`diff-block-${firstBlockIndex}`);
    const scrollContainer = document.getElementById('diff-scroll-container');

    if (element && scrollContainer) {
        // Get element's position relative to scroll container
        const elementRect = element.getBoundingClientRect();
        const containerRect = scrollContainer.getBoundingClientRect();
        const relativeTop = elementRect.top - containerRect.top + scrollContainer.scrollTop;

        // Scroll with offset to show context lines above
        scrollContainer.scrollTo({
            top: Math.max(0, relativeTop - 80),
            behavior: 'smooth'
        });
    }

    // Also scroll the left sidebar to show the selected item
    const sidebarItem = document.getElementById(`sidebar-item-${index}`);
    const sidebarContainer = document.getElementById('sidebar-scroll-container');

    if (sidebarItem && sidebarContainer) {
        const itemRect = sidebarItem.getBoundingClientRect();
        const sidebarRect = sidebarContainer.getBoundingClientRect();
        const relativeTop = itemRect.top - sidebarRect.top + sidebarContainer.scrollTop;

        sidebarContainer.scrollTo({
            top: Math.max(0, relativeTop - 50),
            behavior: 'smooth'
        });
    }
}

// Keyboard navigation
const isHoveringDiffArea = ref(false);

function handleKeyDown(event: KeyboardEvent) {
    if (!isHoveringDiffArea.value) return;
    if (activeChangeIndex.value === null && changeNavItems.value.length > 0) {
        // No selection yet, select first item on any arrow key
        if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) {
            event.preventDefault();
            scrollToChange(0);
            return;
        }
    }

    if (activeChangeIndex.value === null) return;

    const totalItems = changeNavItems.value.length;
    let newIndex = activeChangeIndex.value;

    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
        event.preventDefault();
        newIndex = (activeChangeIndex.value + 1) % totalItems;
    } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
        event.preventDefault();
        newIndex = (activeChangeIndex.value - 1 + totalItems) % totalItems;
    }

    if (newIndex !== activeChangeIndex.value) {
        scrollToChange(newIndex);
    }
}

onMounted(() => {
    document.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeyDown);
});

const loadingSteps = [
    { message: 'Initializing analysis...', icon: 'init' },
    { message: 'Using gpt-5-nano model', icon: 'model' },
    { message: 'Sending documents for comparison...', icon: 'send' },
    { message: 'Processing version differences...', icon: 'process' },
    { message: 'Analyzing legal implications...', icon: 'analyze' },
    { message: 'Generating summary...', icon: 'summary' },
];

// Computed
const hasBehavioralWarnings = computed(() => {
    return props.behavioralSignals && props.behavioralSignals.signals.length > 0;
});

const criticalSignals = computed(() => {
    if (!props.behavioralSignals) return [];
    return props.behavioralSignals.signals.filter(s => s.severity === 'critical' || s.severity === 'high');
});

// Methods
function formatDate(dateString: string | null): string {
    if (!dateString) return 'Unknown date';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

function formatShortDate(dateString: string | null): string {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function getImpactIcon(impact: string) {
    switch (impact) {
        case 'positive': return TrendingUp;
        case 'negative': return TrendingDown;
        default: return MinusIcon;
    }
}

function getImpactColor(impact: string): string {
    switch (impact) {
        case 'positive': return 'text-green-600 dark:text-green-400';
        case 'negative': return 'text-red-600 dark:text-red-400';
        default: return 'text-gray-600 dark:text-gray-400';
    }
}

function getSeverityColor(severity: number): string {
    if (severity >= 8) return 'bg-red-500';
    if (severity >= 6) return 'bg-orange-500';
    if (severity >= 4) return 'bg-yellow-500';
    return 'bg-gray-400';
}

function getCategoryLabel(category: string): string {
    const labels: Record<string, string> = {
        'data_collection': 'Data Collection',
        'data_sharing': 'Data Sharing',
        'user_rights': 'User Rights',
        'legal_terms': 'Legal Terms',
        'dispute_resolution': 'Dispute Resolution',
        'liability': 'Liability',
        'notifications': 'Notifications',
        'other': 'Other',
    };
    return labels[category] || category;
}

function toggleBlock(index: number) {
    if (expandedBlocks.value.has(index)) {
        expandedBlocks.value.delete(index);
    } else {
        expandedBlocks.value.add(index);
    }
}

let stepInterval: ReturnType<typeof setInterval> | null = null;

async function loadChangeSummary() {
    if (changeSummary.value || isLoadingSummary.value) return;

    isLoadingSummary.value = true;
    summaryError.value = null;
    loadingStep.value = 0;

    // Cycle through loading steps to show progress
    stepInterval = setInterval(() => {
        if (loadingStep.value < loadingSteps.length - 1) {
            loadingStep.value++;
        }
    }, 1500);

    try {
        const response = await fetch(
            `/documents/${props.document.id}/compare/${props.oldVersion.id}/${props.newVersion.id}/summary`
        );
        const data = await response.json();

        if (data.success) {
            changeSummary.value = data.data;
        } else {
            summaryError.value = data.error || 'Failed to generate summary';
        }
    } catch (error) {
        summaryError.value = 'Network error while loading summary';
    } finally {
        if (stepInterval) {
            clearInterval(stepInterval);
            stepInterval = null;
        }
        isLoadingSummary.value = false;
    }
}
</script>

<template>
    <Head :title="`Compare Versions - ${document.document_type}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Version Comparison</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ document.document_type }} - {{ document.company.name }}
                    </p>
                </div>
                <Link :href="`/documents/${document.id}`">
                    <Button variant="outline">
                        <ArrowLeft class="mr-2 h-4 w-4" />
                        Back to Document
                    </Button>
                </Link>
            </div>

            <!-- Behavioral Warning Banner -->
            <Card v-if="hasBehavioralWarnings" class="border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/30">
                <CardContent class="py-4">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900">
                            <AlertTriangle class="h-5 w-5 text-red-600 dark:text-red-400" />
                        </div>
                        <div class="flex-1">
                            <h3 class="font-semibold text-red-800 dark:text-red-200">Suspicious Update Timing Detected</h3>
                            <p class="mt-1 text-sm text-red-700 dark:text-red-300">
                                {{ behavioralSignals?.summary || 'This policy update shows behavioral patterns that may indicate an attempt to minimize user attention.' }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <Badge
                                    v-for="signal in criticalSignals"
                                    :key="signal.type"
                                    variant="destructive"
                                    class="text-xs"
                                >
                                    <AlertTriangle class="mr-1 h-3 w-3" />
                                    {{ signal.holiday ? `Updated near ${signal.holiday}` : signal.description }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Version Info Cards -->
            <div class="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader class="pb-3">
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-lg">Version {{ oldVersion.version_number }}</CardTitle>
                            <Badge variant="outline">Old</Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div class="flex items-center gap-4 text-sm text-muted-foreground">
                            <div class="flex items-center gap-1">
                                <Calendar class="h-4 w-4" />
                                {{ formatDate(oldVersion.scraped_at) }}
                            </div>
                            <div class="flex items-center gap-1">
                                <FileText class="h-4 w-4" />
                                {{ oldVersion.word_count.toLocaleString() }} words
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-3">
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-lg">Version {{ newVersion.version_number }}</CardTitle>
                            <Badge>New</Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div class="flex items-center gap-4 text-sm text-muted-foreground">
                            <div class="flex items-center gap-1">
                                <Calendar class="h-4 w-4" />
                                {{ formatDate(newVersion.scraped_at) }}
                            </div>
                            <div class="flex items-center gap-1">
                                <FileText class="h-4 w-4" />
                                {{ newVersion.word_count.toLocaleString() }} words
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Diff Stats -->
            <Card>
                <CardHeader>
                    <CardTitle>Change Summary</CardTitle>
                    <CardDescription>
                        {{ diff.stats.changePercentage }}% of the document changed
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex items-center gap-6">
                        <div class="flex items-center gap-2">
                            <Plus class="h-4 w-4 text-green-600" />
                            <span class="text-lg font-semibold text-green-600">{{ diff.stats.linesAdded }}</span>
                            <span class="text-sm text-muted-foreground">lines added</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <Minus class="h-4 w-4 text-red-600" />
                            <span class="text-lg font-semibold text-red-600">{{ diff.stats.linesRemoved }}</span>
                            <span class="text-sm text-muted-foreground">lines removed</span>
                        </div>
                        <div class="flex-1">
                            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                <div class="flex h-full">
                                    <div
                                        class="bg-green-500"
                                        :style="{ width: `${(diff.stats.linesAdded / diff.stats.totalLines) * 100}%` }"
                                    />
                                    <div
                                        class="bg-red-500"
                                        :style="{ width: `${(diff.stats.linesRemoved / diff.stats.totalLines) * 100}%` }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- AI Change Analysis -->
            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <Brain class="h-5 w-5" />
                                AI Change Analysis
                            </CardTitle>
                            <CardDescription>
                                Detailed analysis of what changed and its implications
                            </CardDescription>
                        </div>
                        <Button
                            v-if="!changeSummary && !isLoadingSummary"
                            @click="loadChangeSummary"
                        >
                            <Brain class="mr-2 h-4 w-4" />
                            Generate Analysis
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <!-- Loading state with progress steps -->
                    <div v-if="isLoadingSummary" class="py-6">
                        <div class="mx-auto max-w-md space-y-4">
                            <!-- Progress steps -->
                            <div class="space-y-3">
                                <div
                                    v-for="(step, index) in loadingSteps"
                                    :key="index"
                                    class="flex items-center gap-3 transition-all duration-300"
                                    :class="{
                                        'opacity-100': index <= loadingStep,
                                        'opacity-30': index > loadingStep,
                                    }"
                                >
                                    <!-- Step icon -->
                                    <div
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition-colors"
                                        :class="{
                                            'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-400': index < loadingStep,
                                            'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400': index === loadingStep,
                                            'bg-muted text-muted-foreground': index > loadingStep,
                                        }"
                                    >
                                        <CheckCircle v-if="index < loadingStep" class="h-4 w-4" />
                                        <Loader2 v-else-if="index === loadingStep" class="h-4 w-4 animate-spin" />
                                        <Cpu v-else-if="step.icon === 'model'" class="h-4 w-4" />
                                        <Send v-else-if="step.icon === 'send'" class="h-4 w-4" />
                                        <FileSearch v-else-if="step.icon === 'process'" class="h-4 w-4" />
                                        <Scale v-else-if="step.icon === 'analyze'" class="h-4 w-4" />
                                        <FileCheck v-else-if="step.icon === 'summary'" class="h-4 w-4" />
                                        <Brain v-else class="h-4 w-4" />
                                    </div>

                                    <!-- Step text -->
                                    <span
                                        class="text-sm transition-colors"
                                        :class="{
                                            'text-green-600 dark:text-green-400': index < loadingStep,
                                            'font-medium text-foreground': index === loadingStep,
                                            'text-muted-foreground': index > loadingStep,
                                        }"
                                    >
                                        {{ step.message }}
                                    </span>
                                </div>
                            </div>

                            <!-- Progress bar -->
                            <div class="mt-4">
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full bg-blue-500 transition-all duration-500"
                                        :style="{ width: `${((loadingStep + 1) / loadingSteps.length) * 100}%` }"
                                    />
                                </div>
                                <p class="mt-2 text-center text-xs text-muted-foreground">
                                    This may take 10-30 seconds depending on document length
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Error state -->
                    <div v-else-if="summaryError" class="rounded-lg bg-red-50 p-4 dark:bg-red-950/30">
                        <p class="text-sm text-red-600 dark:text-red-400">{{ summaryError }}</p>
                        <Button variant="outline" size="sm" class="mt-2" @click="loadChangeSummary">
                            Try Again
                        </Button>
                    </div>

                    <!-- Summary content -->
                    <div v-else-if="changeSummary" class="space-y-6">
                        <!-- Executive Summary -->
                        <div class="rounded-lg bg-muted p-4">
                            <h4 class="mb-2 font-semibold">Executive Summary</h4>
                            <p class="text-sm">{{ changeSummary.summary }}</p>
                        </div>

                        <!-- Individual Changes -->
                        <div v-if="changeSummary.changes?.length" class="space-y-3">
                            <h4 class="font-semibold">Key Changes</h4>
                            <div
                                v-for="(change, index) in changeSummary.changes"
                                :key="index"
                                class="rounded-lg border p-4"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex items-center gap-2">
                                        <component
                                            :is="getImpactIcon(change.impact)"
                                            class="h-5 w-5"
                                            :class="getImpactColor(change.impact)"
                                        />
                                        <span class="font-medium">{{ change.title }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Badge variant="outline" class="text-xs">
                                            {{ getCategoryLabel(change.category) }}
                                        </Badge>
                                        <div class="flex items-center gap-1">
                                            <div
                                                class="h-2 w-2 rounded-full"
                                                :class="getSeverityColor(change.severity)"
                                            />
                                            <span class="text-xs text-muted-foreground">{{ change.severity }}/10</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 grid gap-2 md:grid-cols-2">
                                    <div class="rounded bg-red-50 p-2 dark:bg-red-950/30">
                                        <span class="text-xs font-medium text-red-600 dark:text-red-400">Before:</span>
                                        <p class="mt-1 text-sm text-red-800 dark:text-red-200">{{ change.old_text }}</p>
                                    </div>
                                    <div class="rounded bg-green-50 p-2 dark:bg-green-950/30">
                                        <span class="text-xs font-medium text-green-600 dark:text-green-400">After:</span>
                                        <p class="mt-1 text-sm text-green-800 dark:text-green-200">{{ change.new_text }}</p>
                                    </div>
                                </div>

                                <p class="mt-2 text-sm text-muted-foreground">
                                    {{ change.explanation }}
                                </p>
                            </div>
                        </div>

                        <!-- Risk Assessment -->
                        <div v-if="changeSummary.risk_assessment" class="rounded-lg border-l-4 border-yellow-500 bg-yellow-50 p-4 dark:bg-yellow-950/30">
                            <h4 class="mb-1 font-semibold text-yellow-800 dark:text-yellow-200">Risk Assessment</h4>
                            <p class="text-sm text-yellow-700 dark:text-yellow-300">{{ changeSummary.risk_assessment }}</p>
                        </div>

                        <!-- Recommendations -->
                        <div v-if="changeSummary.recommendations" class="rounded-lg bg-blue-50 p-4 dark:bg-blue-950/30">
                            <h4 class="mb-1 font-semibold text-blue-800 dark:text-blue-200">Recommendations</h4>
                            <p class="text-sm text-blue-700 dark:text-blue-300">{{ changeSummary.recommendations }}</p>
                        </div>
                    </div>

                    <!-- Placeholder -->
                    <div v-else class="py-8 text-center">
                        <Brain class="mx-auto h-12 w-12 text-muted-foreground/50" />
                        <p class="mt-2 text-sm text-muted-foreground">
                            Click "Generate Analysis" to get an AI-powered breakdown of all changes and their implications.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- Line-by-Line Diff -->
            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle>Line-by-Line Comparison</CardTitle>
                            <CardDescription>
                                Detailed view of all changes between versions
                            </CardDescription>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="showChangesSidebar = !showChangesSidebar"
                            class="gap-2"
                        >
                            <component :is="showChangesSidebar ? PanelLeftClose : PanelLeft" class="h-4 w-4" />
                            {{ showChangesSidebar ? 'Hide' : 'Show' }} Changes List
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        class="flex gap-4"
                        @mouseenter="isHoveringDiffArea = true"
                        @mouseleave="isHoveringDiffArea = false"
                    >
                        <!-- Changes Sidebar (30%) -->
                        <div
                            v-if="showChangesSidebar"
                            class="w-[30%] shrink-0 overflow-hidden rounded-lg border bg-muted/30"
                        >
                            <div class="border-b bg-muted px-3 py-2">
                                <h4 class="text-sm font-semibold">Changes ({{ changeNavItems.length }})</h4>
                                <p class="text-xs text-muted-foreground">Click to jump to change</p>
                            </div>
                            <div id="sidebar-scroll-container" class="max-h-[600px] overflow-y-auto">
                                <div
                                    v-for="(item, index) in changeNavItems"
                                    :key="index"
                                    :id="`sidebar-item-${index}`"
                                    class="cursor-pointer border-b px-3 py-2.5 transition-all"
                                    :class="{
                                        'bg-blue-100 dark:bg-blue-900/50 border-l-4 border-l-blue-500': activeChangeIndex === index,
                                        'hover:bg-muted/50': activeChangeIndex !== index,
                                    }"
                                    @click="scrollToChange(index)"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-semibold">
                                            Line {{ item.startLine || '?' }} – {{ item.lineCount }} line{{ item.lineCount !== 1 ? 's' : '' }}
                                        </span>
                                        <ArrowUpRight class="h-3 w-3 text-muted-foreground" />
                                    </div>
                                    <p class="mt-1 line-clamp-2 font-mono text-xs text-muted-foreground">
                                        {{ item.preview }}
                                    </p>
                                </div>
                                <div v-if="changeNavItems.length === 0" class="px-3 py-8 text-center">
                                    <p class="text-sm text-muted-foreground">No changes detected</p>
                                </div>
                            </div>
                        </div>

                        <!-- Diff Content (70% or 100% when sidebar hidden) -->
                        <div
                            ref="diffContainerRef"
                            class="flex-1 overflow-hidden rounded-lg border font-mono text-sm"
                            :class="{ 'w-full': !showChangesSidebar }"
                        >
                            <div id="diff-scroll-container" class="max-h-[600px] overflow-y-auto">
                                <template v-for="(block, blockIndex) in diff.blocks" :key="blockIndex">
                                    <!-- Collapsed block -->
                                    <div
                                        v-if="block.type === 'collapsed'"
                                        class="flex cursor-pointer items-center justify-center gap-2 bg-muted/50 py-2 text-xs text-muted-foreground hover:bg-muted"
                                        @click="toggleBlock(blockIndex)"
                                    >
                                        <component :is="expandedBlocks.has(blockIndex) ? ChevronUp : ChevronDown" class="h-4 w-4" />
                                        {{ block.skippedLines }} unchanged lines
                                    </div>

                                    <!-- Regular diff lines - wrapped in a block container for highlight -->
                                    <div
                                        v-else
                                        :id="(block.type === 'added' || block.type === 'removed') ? `diff-block-${blockIndex}` : undefined"
                                    >
                                        <div
                                            v-for="(line, lineIndex) in block.lines"
                                            :key="`${blockIndex}-${lineIndex}`"
                                            class="flex"
                                            :class="{
                                                'bg-green-50 dark:bg-green-950/30': line.type === 'added' && !highlightedBlockIndices.has(blockIndex),
                                                'bg-red-50 dark:bg-red-950/30': line.type === 'removed' && !highlightedBlockIndices.has(blockIndex),
                                                'bg-green-100 dark:bg-green-900/40 border-l-8 border-l-blue-500': line.type === 'added' && highlightedBlockIndices.has(blockIndex),
                                                'bg-red-100 dark:bg-red-900/40 border-l-8 border-l-blue-500': line.type === 'removed' && highlightedBlockIndices.has(blockIndex),
                                            }"
                                        >
                                            <!-- Line numbers -->
                                            <div class="flex w-20 shrink-0 border-r text-xs text-muted-foreground">
                                                <div class="w-10 px-2 py-1 text-right">
                                                    {{ line.oldLine || '' }}
                                                </div>
                                                <div class="w-10 px-2 py-1 text-right">
                                                    {{ line.newLine || '' }}
                                                </div>
                                            </div>

                                            <!-- Change indicator -->
                                            <div class="w-6 shrink-0 py-1 text-center">
                                                <span v-if="line.type === 'added'" class="text-green-600">+</span>
                                                <span v-else-if="line.type === 'removed'" class="text-red-600">-</span>
                                            </div>

                                            <!-- Content -->
                                            <div
                                                class="flex-1 whitespace-pre-wrap break-all px-2 py-1"
                                                :class="{
                                                    'text-green-800 dark:text-green-200': line.type === 'added',
                                                    'text-red-800 dark:text-red-200': line.type === 'removed',
                                                }"
                                            >
                                                {{ line.content || '\u00A0' }}
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>

