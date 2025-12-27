<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    FileText,
    ExternalLink,
    ArrowLeft,
    Building2,
    Hash,
    Globe,
    BookOpen,
    Code,
    Type,
    CheckCircle2,
    Eye,
    FileCode,
    RefreshCw,
    Loader2,
    Calendar,
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
import { marked } from 'marked';

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
    version_number: string;
    content_raw: string;
    content_text: string;
    content_markdown: string | null;
    content_hash: string;
    word_count: number;
    character_count: number;
    language: string | null;
    scraped_at: string | null;
    is_current: boolean;
    extraction_metadata: Record<string, any> | null;
    metadata: ExtractedMetadata | null;
}

interface DocumentInfo {
    id: number;
    source_url: string;
    document_type: string | null;
    company_id: number;
    company_name: string | null;
    website_url: string | null;
}

interface Props {
    version: Version;
    document: DocumentInfo;
}

const props = defineProps<Props>();

const activeTab = ref('rendered');
const isExtracting = ref(false);

function reExtractMetadata() {
    isExtracting.value = true;
    router.post(`/queue/version/${props.version.id}/extract-metadata`, {}, {
        preserveScroll: true,
        onFinish: () => {
            isExtracting.value = false;
        },
    });
}

// Prepare HTML for iframe rendering - wrap in a basic document structure
// with a base tag to handle relative URLs and some default styling
const iframeHtml = computed(() => {
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
${props.version.content_raw}
</body>
</html>`;
});

// Render markdown to HTML
const renderedMarkdown = computed(() => {
    if (!props.version.content_markdown) return '';
    return marked(props.version.content_markdown) as string;
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Queue', href: '/queue' },
    { title: `Document`, href: `/documents/${props.document.id}` },
    { title: `Version ${props.version.version_number}`, href: `/queue/version/${props.version.id}` },
];

function formatDate(dateString: string | null): string {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatDateOnly(dateString: string | null): string {
    if (!dateString) return 'Unknown';
    // Parse date-only strings (YYYY-MM-DD) without timezone conversion
    // to avoid off-by-one errors when displaying
    const match = dateString.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (match) {
        const [, year, month, day] = match;
        const date = new Date(parseInt(year), parseInt(month) - 1, parseInt(day));
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        });
    }
    // Fallback for full datetime strings
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

function formatLanguage(lang: string | null): string {
    if (!lang) return 'Unknown';
    const languages: Record<string, string> = {
        en: 'English',
        de: 'German',
        fr: 'French',
        es: 'Spanish',
    };
    return languages[lang] || lang.toUpperCase();
}
</script>

<template>
    <Head :title="`Version ${version.version_number} - ${document.document_type || 'Document'}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl px-4 py-6">
        <div class="flex h-full flex-1 flex-col gap-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900">
                        <FileText class="h-6 w-6 text-green-600 dark:text-green-400" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold">Version {{ version.version_number }}</h1>
                        <div class="mt-1 flex items-center gap-3 text-sm text-muted-foreground">
                            <Badge v-if="version.is_current" variant="default">
                                <CheckCircle2 class="mr-1 h-3 w-3" />
                                Current Version
                            </Badge>
                            <Badge variant="outline">
                                {{ document.document_type || 'Document' }}
                            </Badge>
                            <span>{{ formatDate(version.scraped_at) }}</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Link :href="`/documents/${document.id}`">
                        <Button variant="outline">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Back to Document
                        </Button>
                    </Link>
                    <a :href="document.source_url" target="_blank" rel="noopener noreferrer">
                        <Button variant="outline">
                            <ExternalLink class="mr-2 h-4 w-4" />
                            View Original
                        </Button>
                    </a>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="grid gap-4 md:grid-cols-4">
                <Card>
                    <CardContent class="pt-6">
                        <div class="flex items-center gap-3">
                            <Type class="h-8 w-8 text-muted-foreground" />
                            <div>
                                <p class="text-2xl font-bold">{{ version.word_count.toLocaleString() }}</p>
                                <p class="text-xs text-muted-foreground">Words</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent class="pt-6">
                        <div class="flex items-center gap-3">
                            <Hash class="h-8 w-8 text-muted-foreground" />
                            <div>
                                <p class="text-2xl font-bold">{{ version.character_count.toLocaleString() }}</p>
                                <p class="text-xs text-muted-foreground">Characters</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent class="pt-6">
                        <div class="flex items-center gap-3">
                            <Globe class="h-8 w-8 text-muted-foreground" />
                            <div>
                                <p class="text-2xl font-bold">{{ formatLanguage(version.language) }}</p>
                                <p class="text-xs text-muted-foreground">Language</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent class="pt-6">
                        <div class="flex items-center gap-3">
                            <Building2 class="h-8 w-8 text-muted-foreground" />
                            <div>
                                <Link :href="`/companies/${document.company_id}`" class="text-lg font-bold hover:underline">
                                    {{ document.company_name || 'Unknown' }}
                                </Link>
                                <p class="text-xs text-muted-foreground">Company</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Source URL -->
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium">Source URL</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex items-center justify-between rounded-lg bg-muted p-3">
                        <code class="text-sm break-all">{{ document.source_url }}</code>
                        <a :href="document.source_url" target="_blank" rel="noopener noreferrer">
                            <Button variant="ghost" size="sm">
                                <ExternalLink class="h-4 w-4" />
                            </Button>
                        </a>
                    </div>
                </CardContent>
            </Card>

            <!-- Content Tabs -->
            <Card class="flex-1">
                <CardHeader>
                    <CardTitle>Content</CardTitle>
                    <CardDescription>
                        View the retrieved content in different formats
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <!-- Tab Buttons -->
                        <div class="flex flex-wrap gap-2 border-b">
                            <button
                                :class="[
                                    'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                    activeTab === 'rendered'
                                        ? 'border-b-2 border-primary text-primary'
                                        : 'text-muted-foreground hover:text-foreground'
                                ]"
                                @click="activeTab = 'rendered'"
                            >
                                <Eye class="mr-2 h-4 w-4" />
                                Rendered HTML
                            </button>
                            <button
                                :class="[
                                    'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                    activeTab === 'markdown-rendered'
                                        ? 'border-b-2 border-primary text-primary'
                                        : 'text-muted-foreground hover:text-foreground',
                                    !version.content_markdown ? 'opacity-50 cursor-not-allowed' : ''
                                ]"
                                :disabled="!version.content_markdown"
                                @click="version.content_markdown && (activeTab = 'markdown-rendered')"
                            >
                                <FileCode class="mr-2 h-4 w-4" />
                                Markdown Preview
                            </button>
                            <button
                                :class="[
                                    'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                    activeTab === 'text'
                                        ? 'border-b-2 border-primary text-primary'
                                        : 'text-muted-foreground hover:text-foreground'
                                ]"
                                @click="activeTab = 'text'"
                            >
                                <Type class="mr-2 h-4 w-4" />
                                Plain Text
                            </button>
                            <button
                                :class="[
                                    'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                    activeTab === 'markdown'
                                        ? 'border-b-2 border-primary text-primary'
                                        : 'text-muted-foreground hover:text-foreground',
                                    !version.content_markdown ? 'opacity-50 cursor-not-allowed' : ''
                                ]"
                                :disabled="!version.content_markdown"
                                @click="version.content_markdown && (activeTab = 'markdown')"
                            >
                                <BookOpen class="mr-2 h-4 w-4" />
                                Markdown Source
                            </button>
                            <button
                                :class="[
                                    'px-4 py-2 text-sm font-medium transition-colors cursor-pointer flex items-center',
                                    activeTab === 'html'
                                        ? 'border-b-2 border-primary text-primary'
                                        : 'text-muted-foreground hover:text-foreground'
                                ]"
                                @click="activeTab = 'html'"
                            >
                                <Code class="mr-2 h-4 w-4" />
                                HTML Source
                            </button>
                        </div>

                        <!-- Content -->
                        <div v-if="activeTab === 'rendered'" class="rounded-lg border bg-white overflow-hidden">
                            <iframe
                                :srcdoc="iframeHtml"
                                class="w-full h-[600px] border-0"
                                sandbox="allow-same-origin"
                                title="Rendered HTML content"
                            ></iframe>
                        </div>
                        <div v-else-if="activeTab === 'markdown-rendered'" class="max-h-[600px] overflow-auto rounded-lg border bg-white dark:bg-slate-900 p-6">
                            <article class="prose prose-sm dark:prose-invert max-w-none" v-html="renderedMarkdown"></article>
                        </div>
                        <div v-else-if="activeTab === 'text'" class="max-h-[600px] overflow-auto rounded-lg border bg-muted/30 p-4">
                            <pre class="whitespace-pre-wrap font-mono text-sm">{{ version.content_text }}</pre>
                        </div>
                        <div v-else-if="activeTab === 'markdown'" class="max-h-[600px] overflow-auto rounded-lg border bg-muted/30 p-4">
                            <pre class="whitespace-pre-wrap font-mono text-sm">{{ version.content_markdown || 'No markdown content available' }}</pre>
                        </div>
                        <div v-else-if="activeTab === 'html'" class="max-h-[600px] overflow-auto rounded-lg border bg-muted/30 p-4">
                            <pre class="whitespace-pre-wrap font-mono text-xs">{{ version.content_raw }}</pre>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Extracted Metadata -->
            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="text-base">Extracted Metadata</CardTitle>
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
                            {{ version.metadata ? 'Re-extract' : 'Extract' }}
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="version.metadata && Object.keys(version.metadata).length > 0">
                        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div v-if="version.metadata.update_date">
                                <dt class="text-sm font-medium text-muted-foreground">Last Updated (from document)</dt>
                                <dd class="mt-1">
                                    <span class="font-semibold">{{ formatDateOnly(version.metadata.update_date.value) }}</span>
                                    <div class="mt-1 flex items-center gap-2">
                                        <Badge variant="outline" class="text-xs">
                                            {{ Math.round(version.metadata.update_date.confidence * 100) }}% confidence
                                        </Badge>
                                    </div>
                                    <p class="mt-1 text-xs text-muted-foreground italic">
                                        Found: "{{ version.metadata.update_date.raw_match }}"
                                    </p>
                                </dd>
                            </div>
                            <div v-if="version.metadata.effective_date">
                                <dt class="text-sm font-medium text-muted-foreground">Effective Date</dt>
                                <dd class="mt-1">
                                    <span class="font-semibold">{{ formatDateOnly(version.metadata.effective_date.value) }}</span>
                                    <div class="mt-1 flex items-center gap-2">
                                        <Badge variant="outline" class="text-xs">
                                            {{ Math.round(version.metadata.effective_date.confidence * 100) }}% confidence
                                        </Badge>
                                    </div>
                                    <p class="mt-1 text-xs text-muted-foreground italic">
                                        Found: "{{ version.metadata.effective_date.raw_match }}"
                                    </p>
                                </dd>
                            </div>
                            <div v-if="version.metadata.version">
                                <dt class="text-sm font-medium text-muted-foreground">Document Version</dt>
                                <dd class="mt-1">
                                    <Badge variant="secondary" class="text-sm">v{{ version.metadata.version.value }}</Badge>
                                    <div class="mt-1 flex items-center gap-2">
                                        <Badge variant="outline" class="text-xs">
                                            {{ Math.round(version.metadata.version.confidence * 100) }}% confidence
                                        </Badge>
                                    </div>
                                    <p class="mt-1 text-xs text-muted-foreground italic">
                                        Found: "{{ version.metadata.version.raw_match }}"
                                    </p>
                                </dd>
                            </div>
                        </dl>
                        <p v-if="version.metadata.extracted_at" class="mt-4 text-xs text-muted-foreground">
                            Extracted: {{ formatDate(version.metadata.extracted_at) }}
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

            <!-- Technical Metadata -->
            <div class="grid gap-6 md:grid-cols-2">
                <Card v-if="version.extraction_metadata">
                    <CardHeader>
                        <CardTitle class="text-base">Extraction Metadata</CardTitle>
                        <CardDescription>Technical details from the scraping process</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <dl class="space-y-2">
                            <div v-for="(value, key) in version.extraction_metadata" :key="key" class="flex justify-between text-sm">
                                <dt class="text-muted-foreground">{{ key }}</dt>
                                <dd class="font-medium">{{ typeof value === 'object' ? JSON.stringify(value) : value }}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Version Info</CardTitle>
                        <CardDescription>Details about this version</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <dl class="space-y-3">
                            <div class="flex justify-between text-sm">
                                <dt class="text-muted-foreground">Version Number</dt>
                                <dd class="font-medium">{{ version.version_number }}</dd>
                            </div>
                            <div class="flex justify-between text-sm">
                                <dt class="text-muted-foreground">Retrieved At</dt>
                                <dd class="font-medium">{{ formatDate(version.scraped_at) }}</dd>
                            </div>
                            <div class="flex justify-between text-sm">
                                <dt class="text-muted-foreground">Content Hash</dt>
                                <dd class="font-mono text-xs">{{ version.content_hash.substring(0, 16) }}...</dd>
                            </div>
                            <div class="flex justify-between text-sm">
                                <dt class="text-muted-foreground">Is Current</dt>
                                <dd>
                                    <Badge :variant="version.is_current ? 'default' : 'outline'">
                                        {{ version.is_current ? 'Yes' : 'No' }}
                                    </Badge>
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </div>
        </div>
    </AppLayout>
</template>
