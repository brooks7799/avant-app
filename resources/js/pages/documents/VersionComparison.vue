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
    Columns2,
    Rows3,
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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

interface AnalysisRecord {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
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
    ai_model_used: string | null;
    completed_at: string | null;
    created_at: string | null;
    error_message: string | null;
}

interface ChunkSummaryData {
    title: string | null;
    summary: string | null;
    impact: 'positive' | 'negative' | 'neutral' | null;
    grade: string | null;
    reason: string | null;
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
    comparisonId: number;
    analyses: AnalysisRecord[];
    chunkSummaries: Record<number, ChunkSummaryData>;
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
const summaryError = ref<string | null>(null);
const expandedBlocks = ref<Set<number>>(new Set());
const loadingStep = ref(0);
const showChangesSidebar = ref(true);
const activeChangeIndex = ref<number | null>(null);
const diffContainerRef = ref<HTMLElement | null>(null);
const highlightedBlockIndices = ref<Set<number>>(new Set());
// Initialize chunk summaries from props (cached from DB) or empty map
const chunkSummaries = ref<Map<number, { loading: boolean; data: ChunkSummaryData | null; error: string | null }>>(
    new Map(
        Object.entries(props.chunkSummaries || {}).map(([key, value]) => [
            parseInt(key),
            { loading: false, data: value, error: null }
        ])
    )
);
const hoveredItemIndex = ref<number | null>(null);
const viewMode = ref<'inline' | 'side-by-side'>('inline');

// Analysis state
const analyses = ref<AnalysisRecord[]>(props.analyses || []);
const selectedAnalysisId = ref<number | null>(null);
const pendingAnalysisId = ref<number | null>(null);

// Progress tracking for chunk analysis
const analysisProgress = ref<{
    totalChunks: number | null;
    processedChunks: number;
    currentLabel: string | null;
}>({
    totalChunks: null,
    processedChunks: 0,
    currentLabel: null,
});

// Computed: Get selected analysis
const selectedAnalysis = computed<AnalysisRecord | null>(() => {
    if (!selectedAnalysisId.value) return null;
    return analyses.value.find(a => a.id === selectedAnalysisId.value) ?? null;
});

// Computed: Get completed analyses for selector
const completedAnalyses = computed(() => {
    return analyses.value.filter(a => a.status === 'completed');
});

// Initialize selected analysis to the latest completed one
onMounted(() => {
    const latestCompleted = analyses.value.find(a => a.status === 'completed');
    if (latestCompleted) {
        selectedAnalysisId.value = latestCompleted.id;
    }
});

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

// Computed: Build side-by-side aligned rows for the split view
interface SideBySideRow {
    leftLine: number | null;
    leftContent: string;
    leftType: 'unchanged' | 'removed' | 'empty';
    rightLine: number | null;
    rightContent: string;
    rightType: 'unchanged' | 'added' | 'empty';
    blockIndex: number | null;
}

const sideBySideRows = computed<SideBySideRow[]>(() => {
    const rows: SideBySideRow[] = [];

    props.diff.blocks.forEach((block, blockIndex) => {
        if (block.type === 'collapsed') {
            // For collapsed blocks, show a placeholder row
            rows.push({
                leftLine: null,
                leftContent: `... ${block.skippedLines} unchanged lines ...`,
                leftType: 'unchanged',
                rightLine: null,
                rightContent: `... ${block.skippedLines} unchanged lines ...`,
                rightType: 'unchanged',
                blockIndex: null,
            });
            return;
        }

        if (block.type === 'unchanged') {
            // Unchanged lines appear on both sides
            block.lines.forEach((line) => {
                rows.push({
                    leftLine: line.oldLine,
                    leftContent: line.content,
                    leftType: 'unchanged',
                    rightLine: line.newLine,
                    rightContent: line.content,
                    rightType: 'unchanged',
                    blockIndex: null,
                });
            });
        } else if (block.type === 'removed') {
            // Check if next block is 'added' (modification pair)
            const nextBlock = props.diff.blocks[blockIndex + 1];
            if (nextBlock?.type === 'added') {
                // Pair them side by side
                const maxLen = Math.max(block.lines.length, nextBlock.lines.length);
                for (let i = 0; i < maxLen; i++) {
                    const oldLine = block.lines[i];
                    const newLine = nextBlock.lines[i];
                    rows.push({
                        leftLine: oldLine?.oldLine ?? null,
                        leftContent: oldLine?.content ?? '',
                        leftType: oldLine ? 'removed' : 'empty',
                        rightLine: newLine?.newLine ?? null,
                        rightContent: newLine?.content ?? '',
                        rightType: newLine ? 'added' : 'empty',
                        blockIndex: blockIndex,
                    });
                }
            } else {
                // Pure removal - right side is empty
                block.lines.forEach((line) => {
                    rows.push({
                        leftLine: line.oldLine,
                        leftContent: line.content,
                        leftType: 'removed',
                        rightLine: null,
                        rightContent: '',
                        rightType: 'empty',
                        blockIndex: blockIndex,
                    });
                });
            }
        } else if (block.type === 'added') {
            // Check if previous block was 'removed' (already handled in pair above)
            const prevBlock = props.diff.blocks[blockIndex - 1];
            if (prevBlock?.type === 'removed') {
                // Already handled in the removed block processing
                return;
            }
            // Pure addition - left side is empty
            block.lines.forEach((line) => {
                rows.push({
                    leftLine: null,
                    leftContent: '',
                    leftType: 'empty',
                    rightLine: line.newLine,
                    rightContent: line.content,
                    rightType: 'added',
                    blockIndex: blockIndex,
                });
            });
        }
    });

    return rows;
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
    // Cleanup any active polling/intervals
    if (stepInterval) clearInterval(stepInterval);
    if (pollInterval) clearInterval(pollInterval);
});

// Fetch chunk summary on hover
async function fetchChunkSummary(index: number) {
    const item = changeNavItems.value[index];
    if (!item) return;

    // Already fetched or loading
    if (chunkSummaries.value.has(index)) return;

    // Get the text content from the blocks
    const blocks = props.diff.blocks;
    let removedText = '';
    let addedText = '';

    for (const blockIndex of item.blockIndices) {
        const block = blocks[blockIndex];
        if (block) {
            const text = block.lines.map(l => l.content).join('\n');
            if (block.type === 'removed') {
                removedText += text + '\n';
            } else if (block.type === 'added') {
                addedText += text + '\n';
            }
        }
    }

    // Set loading state
    chunkSummaries.value.set(index, { loading: true, data: null, error: null });

    try {
        const response = await fetch(`/documents/${props.document.id}/compare/chunk-summary`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                removed_text: removedText.trim(),
                added_text: addedText.trim(),
            }),
        });

        const data = await response.json();

        if (data.success) {
            chunkSummaries.value.set(index, { loading: false, data: data.data, error: null });
        } else {
            chunkSummaries.value.set(index, { loading: false, data: null, error: data.error });
        }
    } catch (error) {
        chunkSummaries.value.set(index, { loading: false, data: null, error: 'Failed to load summary' });
    }
}

function handleItemHover(index: number) {
    hoveredItemIndex.value = index;
    // Only fetch if we don't already have data (fallback for old comparisons)
    // New comparisons get summaries from the analysis job, loaded via props
    if (!chunkSummaries.value.has(index)) {
        fetchChunkSummary(index);
    }
}

function getGradeColor(grade: string): string {
    switch (grade) {
        case 'A': return 'bg-green-500';
        case 'B': return 'bg-green-400';
        case 'C': return 'bg-yellow-500';
        case 'D': return 'bg-orange-500';
        case 'F': return 'bg-red-500';
        default: return 'bg-gray-400';
    }
}

function getImpactBadgeClass(impact: string): string {
    switch (impact) {
        case 'positive': return 'bg-green-100 text-green-700';
        case 'negative': return 'bg-red-100 text-red-700';
        default: return 'bg-gray-100 text-gray-700';
    }
}

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

let pollInterval: ReturnType<typeof setInterval> | null = null;

async function generateNewAnalysis() {
    if (isLoadingSummary.value) return;

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
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            throw new Error('CSRF token not found');
        }

        const response = await fetch(
            `/documents/${props.document.id}/compare/${props.oldVersion.id}/${props.newVersion.id}/analyze`,
            {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
            }
        );

        if (!response.ok) {
            const errorText = await response.text();
            console.error('Analysis request failed:', response.status, errorText);
            throw new Error(`Server error: ${response.status}`);
        }

        const data = await response.json();

        if (data.success && data.status === 'pending') {
            // Job queued, start polling for status
            pendingAnalysisId.value = data.analysis_id;

            // Add a placeholder analysis to the list
            analyses.value.unshift({
                id: data.analysis_id,
                status: 'pending',
                summary: null,
                impact_analysis: null,
                impact_score_delta: null,
                change_flags: null,
                is_suspicious_timing: false,
                suspicious_timing_score: null,
                timing_context: null,
                ai_model_used: null,
                completed_at: null,
                created_at: new Date().toISOString(),
                error_message: null,
            });

            startPolling(data.analysis_id);
        } else {
            summaryError.value = data.error || 'Failed to start analysis';
            stopLoading();
        }
    } catch (error) {
        console.error('Analysis error:', error);
        summaryError.value = error instanceof Error ? error.message : 'Network error while starting analysis';
        stopLoading();
    }
}

function startPolling(analysisId: number) {
    // Poll every 2 seconds
    pollInterval = setInterval(async () => {
        try {
            const response = await fetch(
                `/documents/${props.document.id}/compare/analysis/${analysisId}/status`
            );
            const data = await response.json();

            // Update progress tracking
            if (data.progress) {
                analysisProgress.value = {
                    totalChunks: data.progress.total_chunks,
                    processedChunks: data.progress.processed_chunks || 0,
                    currentLabel: data.progress.current_label,
                };
            }

            // Update the analysis in our list
            const index = analyses.value.findIndex(a => a.id === analysisId);
            if (index !== -1) {
                if (data.status === 'completed' && data.data) {
                    analyses.value[index] = data.data;
                    selectedAnalysisId.value = analysisId;
                    // Reload page to get cached chunk summaries
                    window.location.reload();
                } else if (data.status === 'failed') {
                    analyses.value[index].status = 'failed';
                    analyses.value[index].error_message = data.error;
                    summaryError.value = data.error || 'Analysis failed';
                    stopLoading();
                } else {
                    analyses.value[index].status = data.status;
                }
            }
        } catch (error) {
            summaryError.value = 'Network error while checking status';
            stopLoading();
        }
    }, 2000);
}

function stopLoading() {
    if (stepInterval) {
        clearInterval(stepInterval);
        stepInterval = null;
    }
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
    pendingAnalysisId.value = null;
    isLoadingSummary.value = false;
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
                        <div class="flex items-center gap-2">
                            <!-- Analysis selector when multiple exist -->
                            <Select v-if="completedAnalyses.length > 1" v-model="selectedAnalysisId">
                                <SelectTrigger class="w-[200px]">
                                    <SelectValue placeholder="Select analysis" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="analysis in completedAnalyses"
                                        :key="analysis.id"
                                        :value="analysis.id"
                                    >
                                        {{ analysis.ai_model_used || 'Unknown model' }}
                                        <span class="text-xs text-muted-foreground ml-2">
                                            {{ analysis.completed_at ? new Date(analysis.completed_at).toLocaleDateString() : '' }}
                                        </span>
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                :disabled="isLoadingSummary"
                                @click="generateNewAnalysis"
                            >
                                <Loader2 v-if="isLoadingSummary" class="mr-2 h-4 w-4 animate-spin" />
                                <Brain v-else class="mr-2 h-4 w-4" />
                                {{ isLoadingSummary ? 'Analyzing...' : (completedAnalyses.length > 0 ? 'New Analysis' : 'Generate Analysis') }}
                            </Button>
                        </div>
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

                            <!-- Chunk progress (when analyzing chunks) -->
                            <div v-if="analysisProgress.totalChunks !== null" class="mt-4 rounded-lg border bg-muted/50 p-4">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="font-medium">{{ analysisProgress.currentLabel || 'Processing...' }}</span>
                                    <span class="text-muted-foreground">
                                        {{ analysisProgress.processedChunks }} / {{ analysisProgress.totalChunks }} changes
                                    </span>
                                </div>
                                <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full bg-green-500 transition-all duration-300"
                                        :style="{ width: `${analysisProgress.totalChunks > 0 ? (analysisProgress.processedChunks / analysisProgress.totalChunks) * 100 : 0}%` }"
                                    />
                                </div>
                            </div>

                            <!-- Progress bar (initial steps) -->
                            <div v-else class="mt-4">
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
                    <div v-else-if="summaryError && !selectedAnalysis" class="rounded-lg bg-red-50 p-4 dark:bg-red-950/30">
                        <p class="text-sm text-red-600 dark:text-red-400">{{ summaryError }}</p>
                        <Button variant="outline" size="sm" class="mt-2" @click="generateNewAnalysis">
                            Try Again
                        </Button>
                    </div>

                    <!-- Summary content -->
                    <div v-else-if="selectedAnalysis" class="space-y-6">
                        <!-- Impact Score Delta -->
                        <div v-if="selectedAnalysis.impact_score_delta !== undefined && selectedAnalysis.impact_score_delta !== null" class="flex items-center gap-4">
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-muted-foreground">Impact Score Change:</span>
                                <Badge
                                    :variant="selectedAnalysis.impact_score_delta < 0 ? 'destructive' : selectedAnalysis.impact_score_delta > 0 ? 'default' : 'outline'"
                                    class="text-sm"
                                >
                                    {{ selectedAnalysis.impact_score_delta > 0 ? '+' : '' }}{{ selectedAnalysis.impact_score_delta }} points
                                </Badge>
                            </div>
                            <div v-if="selectedAnalysis.is_suspicious_timing" class="flex items-center gap-1 text-amber-600">
                                <AlertTriangle class="h-4 w-4" />
                                <span class="text-sm font-medium">Suspicious Timing Detected</span>
                            </div>
                        </div>

                        <!-- Executive Summary -->
                        <div class="rounded-lg bg-muted p-4">
                            <h4 class="mb-2 font-semibold">Executive Summary</h4>
                            <p class="text-sm whitespace-pre-wrap">{{ selectedAnalysis.summary }}</p>
                        </div>

                        <!-- Impact Analysis -->
                        <div v-if="selectedAnalysis.impact_analysis" class="rounded-lg border p-4">
                            <h4 class="mb-2 font-semibold">Impact Analysis</h4>
                            <p class="text-sm whitespace-pre-wrap">{{ selectedAnalysis.impact_analysis }}</p>
                        </div>

                        <!-- Change Flags - Negative -->
                        <div v-if="selectedAnalysis.change_flags?.new_clauses?.length" class="space-y-3">
                            <h4 class="font-semibold text-red-700 dark:text-red-400">New Concerning Clauses</h4>
                            <div
                                v-for="(clause, index) in selectedAnalysis.change_flags.new_clauses"
                                :key="`new-${index}`"
                                class="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950/30"
                            >
                                <div class="flex items-center justify-between">
                                    <Badge variant="destructive" class="text-xs">{{ clause.type?.replace(/_/g, ' ') }}</Badge>
                                    <span class="text-xs text-muted-foreground">Severity: {{ clause.severity }}/10</span>
                                </div>
                                <p class="mt-2 text-sm">{{ clause.description }}</p>
                            </div>
                        </div>

                        <!-- Change Flags - Removed Good Clauses -->
                        <div v-if="selectedAnalysis.change_flags?.removed_clauses?.length" class="space-y-3">
                            <h4 class="font-semibold text-orange-700 dark:text-orange-400">Removed Clauses</h4>
                            <div
                                v-for="(clause, index) in selectedAnalysis.change_flags.removed_clauses"
                                :key="`removed-${index}`"
                                class="rounded-lg border border-orange-200 bg-orange-50 p-3 dark:border-orange-800 dark:bg-orange-950/30"
                            >
                                <div class="flex items-center justify-between">
                                    <Badge variant="outline" class="border-orange-500 text-xs text-orange-700">{{ clause.type?.replace(/_/g, ' ') }}</Badge>
                                    <span class="text-xs text-muted-foreground">Severity: {{ clause.severity }}/10</span>
                                </div>
                                <p class="mt-2 text-sm">{{ clause.description }}</p>
                            </div>
                        </div>

                        <!-- Change Flags - Modified -->
                        <div v-if="selectedAnalysis.change_flags?.modified_clauses?.length" class="space-y-3">
                            <h4 class="font-semibold text-yellow-700 dark:text-yellow-400">Modified Clauses</h4>
                            <div
                                v-for="(clause, index) in selectedAnalysis.change_flags.modified_clauses"
                                :key="`modified-${index}`"
                                class="rounded-lg border border-yellow-200 bg-yellow-50 p-3 dark:border-yellow-800 dark:bg-yellow-950/30"
                            >
                                <div class="flex items-center justify-between">
                                    <Badge variant="outline" class="border-yellow-500 text-xs text-yellow-700">{{ clause.type?.replace(/_/g, ' ') }}</Badge>
                                    <span class="text-xs text-muted-foreground">Severity: {{ clause.severity }}/10</span>
                                </div>
                                <p class="mt-2 text-sm">{{ clause.description }}</p>
                            </div>
                        </div>

                        <!-- Change Flags - Neutral -->
                        <div v-if="selectedAnalysis.change_flags?.neutral_changes?.length" class="space-y-3">
                            <h4 class="font-semibold text-gray-700 dark:text-gray-400">Neutral Changes</h4>
                            <div
                                v-for="(clause, index) in selectedAnalysis.change_flags.neutral_changes"
                                :key="`neutral-${index}`"
                                class="rounded-lg border bg-muted/50 p-3"
                            >
                                <div class="flex items-center gap-2">
                                    <Badge variant="outline" class="text-xs">{{ clause.type?.replace(/_/g, ' ') }}</Badge>
                                </div>
                                <p class="mt-2 text-sm">{{ clause.description }}</p>
                            </div>
                        </div>

                        <!-- Suspicious Timing Details -->
                        <div v-if="selectedAnalysis.is_suspicious_timing && selectedAnalysis.timing_context" class="rounded-lg border-l-4 border-amber-500 bg-amber-50 p-4 dark:bg-amber-950/30">
                            <h4 class="mb-1 flex items-center gap-2 font-semibold text-amber-800 dark:text-amber-200">
                                <AlertTriangle class="h-4 w-4" />
                                Suspicious Timing Alert
                            </h4>
                            <p class="text-sm text-amber-700 dark:text-amber-300">
                                This update may have been timed to minimize user attention.
                                <span v-if="selectedAnalysis.suspicious_timing_score">
                                    (Score: {{ selectedAnalysis.suspicious_timing_score }}/100)
                                </span>
                            </p>
                        </div>

                        <!-- AI Metadata -->
                        <div v-if="selectedAnalysis.ai_model_used" class="flex items-center gap-4 text-xs text-muted-foreground">
                            <span>Model: {{ selectedAnalysis.ai_model_used }}</span>
                            <span v-if="selectedAnalysis.completed_at">Analyzed: {{ new Date(selectedAnalysis.completed_at).toLocaleString() }}</span>
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
                        <div class="flex items-center gap-2">
                            <!-- View Mode Selector -->
                            <Select v-model="viewMode">
                                <SelectTrigger class="w-[160px]">
                                    <component :is="viewMode === 'inline' ? Rows3 : Columns2" class="mr-2 h-4 w-4" />
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="inline">
                                        <div class="flex items-center gap-2">
                                            <Rows3 class="h-4 w-4" />
                                            Inline
                                        </div>
                                    </SelectItem>
                                    <SelectItem value="side-by-side">
                                        <div class="flex items-center gap-2">
                                            <Columns2 class="h-4 w-4" />
                                            Side by Side
                                        </div>
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                v-if="viewMode === 'inline'"
                                variant="outline"
                                size="sm"
                                @click="showChangesSidebar = !showChangesSidebar"
                                class="gap-2"
                            >
                                <component :is="showChangesSidebar ? PanelLeftClose : PanelLeft" class="h-4 w-4" />
                                {{ showChangesSidebar ? 'Hide' : 'Show' }} Changes List
                            </Button>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <!-- Inline View -->
                    <div
                        v-if="viewMode === 'inline'"
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
                                    class="relative cursor-pointer border-b px-3 py-2.5 transition-all"
                                    :class="{
                                        'bg-blue-100 dark:bg-blue-900/50 border-l-4 border-l-blue-500': activeChangeIndex === index,
                                        'hover:bg-muted/50': activeChangeIndex !== index,
                                    }"
                                    @click="scrollToChange(index)"
                                    @mouseenter="handleItemHover(index)"
                                    @mouseleave="hoveredItemIndex = null"
                                >
                                    <div class="flex items-center justify-between">
                                        <!-- Title: AI-generated title or fallback to line info -->
                                        <span class="text-sm font-semibold">
                                            <template v-if="chunkSummaries.get(index)?.data?.title">
                                                {{ chunkSummaries.get(index)?.data?.title }}
                                            </template>
                                            <template v-else>
                                                Line {{ item.startLine || '?' }} – {{ item.lineCount }} line{{ item.lineCount !== 1 ? 's' : '' }}
                                            </template>
                                        </span>
                                        <div class="flex items-center gap-1">
                                            <!-- Grade badge if summary loaded -->
                                            <span
                                                v-if="chunkSummaries.get(index)?.data?.grade"
                                                class="flex h-5 w-5 items-center justify-center rounded text-xs font-bold text-white"
                                                :class="getGradeColor(chunkSummaries.get(index)?.data?.grade)"
                                            >
                                                {{ chunkSummaries.get(index)?.data?.grade }}
                                            </span>
                                            <Loader2 v-else-if="chunkSummaries.get(index)?.loading" class="h-3 w-3 animate-spin text-muted-foreground" />
                                            <ArrowUpRight v-else class="h-3 w-3 text-muted-foreground" />
                                        </div>
                                    </div>

                                    <!-- Subtitle: AI summary (expanded when selected) or preview text -->
                                    <p
                                        class="mt-1 text-xs"
                                        :class="{
                                            'text-muted-foreground': true,
                                            'line-clamp-2 font-mono': activeChangeIndex !== index && !chunkSummaries.get(index)?.data?.summary,
                                            'line-clamp-2': activeChangeIndex !== index && chunkSummaries.get(index)?.data?.summary,
                                        }"
                                    >
                                        <template v-if="chunkSummaries.get(index)?.data?.summary">
                                            {{ chunkSummaries.get(index)?.data?.summary }}
                                        </template>
                                        <template v-else-if="chunkSummaries.get(index)?.loading">
                                            <span class="italic">Analyzing change...</span>
                                        </template>
                                        <template v-else>
                                            <span class="font-mono">{{ item.preview }}</span>
                                        </template>
                                    </p>

                                    <!-- Expanded details when selected -->
                                    <div
                                        v-if="activeChangeIndex === index && chunkSummaries.get(index)?.data"
                                        class="mt-2 space-y-2 border-t border-blue-200 pt-2 dark:border-blue-700"
                                    >
                                        <div class="flex items-center justify-between">
                                            <span
                                                class="rounded px-2 py-0.5 text-xs font-medium"
                                                :class="getImpactBadgeClass(chunkSummaries.get(index)?.data?.impact)"
                                            >
                                                {{ chunkSummaries.get(index)?.data?.impact || 'neutral' }} impact
                                            </span>
                                            <span class="text-xs text-muted-foreground">
                                                Line {{ item.startLine || '?' }} – {{ item.lineCount }} line{{ item.lineCount !== 1 ? 's' : '' }}
                                            </span>
                                        </div>
                                        <p v-if="chunkSummaries.get(index)?.data?.reason" class="text-xs text-muted-foreground">
                                            {{ chunkSummaries.get(index)?.data?.reason }}
                                        </p>
                                    </div>

                                    <!-- Tooltip popup (only when hovering and NOT selected) -->
                                    <div
                                        v-if="hoveredItemIndex === index && activeChangeIndex !== index && chunkSummaries.get(index)?.data"
                                        class="absolute left-full top-0 z-50 ml-2 w-72 rounded-lg border bg-popover p-3 shadow-lg"
                                    >
                                        <div class="flex items-start justify-between gap-2">
                                            <span
                                                class="rounded px-2 py-0.5 text-xs font-medium"
                                                :class="getImpactBadgeClass(chunkSummaries.get(index)?.data?.impact)"
                                            >
                                                {{ chunkSummaries.get(index)?.data?.impact || 'neutral' }}
                                            </span>
                                            <span
                                                class="flex h-6 w-6 items-center justify-center rounded text-sm font-bold text-white"
                                                :class="getGradeColor(chunkSummaries.get(index)?.data?.grade)"
                                            >
                                                {{ chunkSummaries.get(index)?.data?.grade }}
                                            </span>
                                        </div>
                                        <p class="mt-2 text-sm">
                                            {{ chunkSummaries.get(index)?.data?.summary }}
                                        </p>
                                        <p class="mt-2 text-xs text-muted-foreground">
                                            {{ chunkSummaries.get(index)?.data?.reason }}
                                        </p>
                                    </div>
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

                    <!-- Side-by-Side View -->
                    <div v-else class="overflow-hidden rounded-lg border font-mono text-sm">
                        <!-- Header row -->
                        <div class="flex border-b bg-muted">
                            <div class="flex-1 border-r px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <Badge variant="outline" class="bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300">Old</Badge>
                                    <span class="text-sm font-medium">Version {{ oldVersion.version_number }}</span>
                                    <span class="text-xs text-muted-foreground">{{ formatDate(oldVersion.scraped_at) }}</span>
                                </div>
                            </div>
                            <div class="flex-1 px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <Badge class="bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300">New</Badge>
                                    <span class="text-sm font-medium">Version {{ newVersion.version_number }}</span>
                                    <span class="text-xs text-muted-foreground">{{ formatDate(newVersion.scraped_at) }}</span>
                                </div>
                            </div>
                        </div>
                        <!-- Content rows -->
                        <div class="max-h-[600px] overflow-y-auto">
                            <div
                                v-for="(row, rowIndex) in sideBySideRows"
                                :key="rowIndex"
                                class="flex"
                            >
                                <!-- Left pane (old version) -->
                                <div
                                    class="flex flex-1 border-r"
                                    :class="{
                                        'bg-red-50 dark:bg-red-950/30': row.leftType === 'removed',
                                        'bg-gray-50 dark:bg-gray-900/20': row.leftType === 'empty',
                                    }"
                                >
                                    <div class="w-12 shrink-0 border-r px-2 py-1 text-right text-xs text-muted-foreground">
                                        {{ row.leftLine || '' }}
                                    </div>
                                    <div
                                        class="flex-1 whitespace-pre-wrap break-all px-2 py-1"
                                        :class="{
                                            'text-red-800 dark:text-red-200': row.leftType === 'removed',
                                            'text-muted-foreground/50': row.leftType === 'empty',
                                        }"
                                    >
                                        {{ row.leftContent || '\u00A0' }}
                                    </div>
                                </div>
                                <!-- Right pane (new version) -->
                                <div
                                    class="flex flex-1"
                                    :class="{
                                        'bg-green-50 dark:bg-green-950/30': row.rightType === 'added',
                                        'bg-gray-50 dark:bg-gray-900/20': row.rightType === 'empty',
                                    }"
                                >
                                    <div class="w-12 shrink-0 border-r px-2 py-1 text-right text-xs text-muted-foreground">
                                        {{ row.rightLine || '' }}
                                    </div>
                                    <div
                                        class="flex-1 whitespace-pre-wrap break-all px-2 py-1"
                                        :class="{
                                            'text-green-800 dark:text-green-200': row.rightType === 'added',
                                            'text-muted-foreground/50': row.rightType === 'empty',
                                        }"
                                    >
                                        {{ row.rightContent || '\u00A0' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>

