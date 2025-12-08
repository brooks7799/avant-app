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
    Calendar,
    Search,
    Link2,
    Hash,
    Percent,
    Download,
    Loader2,
    CheckCircle2,
    Clock,
    History,
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
import { ref } from 'vue';

interface Policy {
    url: string;
    detected_type: string | null;
    document_type_id: number | null;
    confidence: number;
    discovery_method: string;
    link_text?: string;
}

interface DocumentVersion {
    id: number;
    version_number: string;
    scraped_at: string | null;
    word_count: number;
    is_current: boolean;
    content_hash: string;
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

function formatConfidence(confidence: number): string {
    return Math.round(confidence * 100) + '%';
}

function getConfidenceColor(confidence: number): string {
    if (confidence >= 0.8) return 'text-green-600 dark:text-green-400';
    if (confidence >= 0.5) return 'text-yellow-600 dark:text-yellow-400';
    return 'text-red-600 dark:text-red-400';
}

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

function formatDiscoveryMethod(method: string): string {
    return method.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

function formatDetectedType(type: string | null): string {
    if (!type) return 'Unknown';
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
                        v-if="discoveryJobId !== null && discoveryIndex !== null"
                        @click="retrievePolicy"
                        :disabled="isRetrieving"
                    >
                        <Loader2 v-if="isRetrieving" class="mr-2 h-4 w-4 animate-spin" />
                        <Download v-else class="mr-2 h-4 w-4" />
                        Retrieve
                    </Button>
                </div>
            </div>

            <!-- Main Content -->
            <div class="grid gap-6 lg:grid-cols-3">
                <!-- URL Card -->
                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Link2 class="h-5 w-5" />
                            URL
                        </CardTitle>
                        <CardDescription>The policy document URL</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="rounded-lg bg-muted p-4">
                            <p class="break-all font-mono text-sm">{{ policy.url }}</p>
                        </div>
                        <div class="mt-4">
                            <a
                                :href="policy.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                            >
                                <ExternalLink class="h-4 w-4" />
                                Open in new tab
                            </a>
                        </div>
                    </CardContent>
                </Card>

                <!-- Quick Stats -->
                <Card>
                    <CardHeader>
                        <CardTitle>Detection Info</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-muted-foreground">Confidence</span>
                            <span class="text-lg font-semibold" :class="getConfidenceColor(policy.confidence)">
                                {{ formatConfidence(policy.confidence) }}
                            </span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
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
                        <div class="flex items-center justify-between pt-2">
                            <span class="text-sm text-muted-foreground">Discovery Method</span>
                            <Badge variant="secondary">
                                {{ formatDiscoveryMethod(policy.discovery_method) }}
                            </Badge>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Details Grid -->
            <div class="grid gap-6 md:grid-cols-2">
                <!-- Policy Details -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <FileText class="h-5 w-5" />
                            Policy Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl class="space-y-4">
                            <div class="flex items-start justify-between">
                                <dt class="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Hash class="h-4 w-4" />
                                    Detected Type
                                </dt>
                                <dd class="text-sm font-medium">
                                    {{ formatDetectedType(policy.detected_type) }}
                                </dd>
                            </div>
                            <div class="flex items-start justify-between">
                                <dt class="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Search class="h-4 w-4" />
                                    Discovery Method
                                </dt>
                                <dd class="text-sm font-medium">
                                    {{ formatDiscoveryMethod(policy.discovery_method) }}
                                </dd>
                            </div>
                            <div class="flex items-start justify-between">
                                <dt class="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Percent class="h-4 w-4" />
                                    Confidence Score
                                </dt>
                                <dd class="text-sm font-medium" :class="getConfidenceColor(policy.confidence)">
                                    {{ formatConfidence(policy.confidence) }}
                                </dd>
                            </div>
                            <div v-if="policy.link_text" class="border-t pt-4">
                                <dt class="mb-2 flex items-center gap-2 text-sm text-muted-foreground">
                                    <Link2 class="h-4 w-4" />
                                    Link Text Found
                                </dt>
                                <dd class="rounded bg-muted px-3 py-2 text-sm italic">
                                    "{{ policy.link_text }}"
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <!-- Source Information -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Globe class="h-5 w-5" />
                            Source Information
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl class="space-y-4">
                            <div>
                                <dt class="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Building2 class="h-4 w-4" />
                                    Company
                                </dt>
                                <dd class="mt-1">
                                    <Link
                                        :href="`/companies/${company.id}`"
                                        class="text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400"
                                    >
                                        {{ company.name }}
                                    </Link>
                                </dd>
                            </div>
                            <div>
                                <dt class="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Globe class="h-4 w-4" />
                                    Website
                                </dt>
                                <dd class="mt-1">
                                    <span class="text-sm font-medium">{{ website.url }}</span>
                                    <a
                                        :href="website.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="ml-2 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                    >
                                        <ExternalLink class="h-3 w-3" />
                                    </a>
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>

            <!-- Retrieved Versions -->
            <Card v-if="document && document.versions.length > 0">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <History class="h-5 w-5" />
                                Retrieved Versions
                            </CardTitle>
                            <CardDescription>
                                {{ document.versions.length }} version{{ document.versions.length !== 1 ? 's' : '' }} retrieved
                            </CardDescription>
                        </div>
                        <Link :href="`/documents/${document.id}`">
                            <Button variant="outline" size="sm">
                                <FileText class="mr-2 h-4 w-4" />
                                View Document
                            </Button>
                        </Link>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2">
                        <Link
                            v-for="version in document.versions"
                            :key="version.id"
                            :href="`/queue/version/${version.id}`"
                            class="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50 transition-colors cursor-pointer"
                        >
                            <div class="flex items-center gap-3">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-muted">
                                    <span class="text-sm font-medium">{{ version.version_number }}</span>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">Version {{ version.version_number }}</span>
                                        <Badge v-if="version.is_current" variant="default" class="text-xs">
                                            Current
                                        </Badge>
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ formatVersionDate(version.scraped_at) }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-4 text-sm text-muted-foreground">
                                <span>{{ version.word_count.toLocaleString() }} words</span>
                                <span class="font-mono text-xs">{{ version.content_hash }}...</span>
                                <ArrowRight class="h-4 w-4" />
                            </div>
                        </Link>
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
                                    Previous Policy
                                </Button>
                            </Link>
                            <span class="text-sm text-muted-foreground">
                                {{ index + 1 }} / {{ totalPolicies }}
                            </span>
                            <Link
                                v-if="index < totalPolicies - 1"
                                :href="`/companies/${company.id}/policy/${index + 1}`"
                            >
                                <Button variant="outline" size="sm">
                                    Next Policy
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
