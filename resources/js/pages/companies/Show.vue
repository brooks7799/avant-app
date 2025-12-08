<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    ExternalLink,
    Pencil,
    Trash2,
    Plus,
    FileText,
    CheckCircle2,
    Clock,
    XCircle,
    Globe,
    Radar,
    Loader2,
    RefreshCw,
    Package,
    Smartphone,
    Gamepad2,
    Monitor,
    Link2,
    Unlink,
    Download,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Checkbox } from '@/components/ui/checkbox';
import { ref, computed } from 'vue';

interface ProductLink {
    id: number;
    name: string;
    is_primary: boolean;
}

interface Policy {
    // Discovery info
    discovery_index: number | null;
    discovery_job_id: number | null;
    url: string;
    detected_type: string | null;
    confidence: number;
    discovery_method: string;
    link_text: string | null;
    // Document info (if retrieved)
    is_retrieved: boolean;
    document_id: number | null;
    document_type: string | null;
    document_type_slug: string | null;
    scrape_status: string | null;
    last_scraped_at: string | null;
    last_changed_at: string | null;
    has_version: boolean;
    version_count: number;
    overall_score: number | null;
    overall_rating: string | null;
    products: ProductLink[];
}

interface LatestDiscovery {
    id: number;
    status: string;
    policies_found: number;
    urls_crawled: number;
    completed_at: string | null;
}

interface Website {
    id: number;
    url: string;
    base_url: string;
    name: string | null;
    is_primary: boolean;
    is_active: boolean;
    discovery_status: string | null;
    last_discovered_at: string | null;
    latest_discovery: LatestDiscovery | null;
    policies: Policy[];
}

interface ProductDocument {
    id: number;
    type: string;
    type_slug: string;
    is_primary: boolean;
}

interface Product {
    id: number;
    name: string;
    slug: string;
    type: string;
    type_label: string;
    url: string | null;
    app_store_url: string | null;
    play_store_url: string | null;
    description: string | null;
    icon_url: string | null;
    is_active: boolean;
    documents: ProductDocument[];
}

interface DocumentType {
    id: number;
    name: string;
    slug: string;
}

interface Tag {
    id: number;
    name: string;
    slug: string;
    color: string | null;
}

interface Company {
    id: number;
    name: string;
    slug: string;
    website: string | null;
    logo_url: string | null;
    description: string | null;
    industry: string | null;
    headquarters_country: string | null;
    headquarters_state: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

interface Props {
    company: Company;
    websites: Website[];
    products: Product[];
    productTypes: Record<string, string>;
    documentTypes: DocumentType[];
    tags: Tag[];
}

const props = defineProps<Props>();

const showDeleteDialog = ref(false);
const showAddWebsiteDialog = ref(false);
const showAddDocumentDialog = ref(false);
const showAddProductDialog = ref(false);
const showLinkDocumentsDialog = ref(false);
const selectedWebsiteForDocument = ref<Website | null>(null);
const selectedProductForLinking = ref<Product | null>(null);
const expandedWebsites = ref<Set<number>>(new Set(props.websites.map(w => w.id)));
const expandedProducts = ref<Set<number>>(new Set(props.products.map(p => p.id)));

const websiteForm = useForm({
    url: '',
    name: '',
    is_primary: false,
});

const documentForm = useForm({
    url: '',
    document_type_id: '',
    title: '',
    scrape_frequency: 'daily',
    is_monitored: true,
});

const productForm = useForm({
    name: '',
    type: 'product',
    url: '',
    app_store_url: '',
    play_store_url: '',
    description: '',
});

const linkDocumentsForm = useForm({
    document_ids: [] as number[],
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Companies', href: '/companies' },
    { title: props.company.name, href: `/companies/${props.company.id}` },
];

function deleteCompany() {
    router.delete(`/companies/${props.company.id}`);
}

function submitWebsite() {
    websiteForm.post(`/companies/${props.company.id}/websites`, {
        onSuccess: () => {
            showAddWebsiteDialog.value = false;
            websiteForm.reset();
        },
    });
}

function openAddDocumentDialog(website: Website) {
    selectedWebsiteForDocument.value = website;
    documentForm.reset();
    showAddDocumentDialog.value = true;
}

function submitDocument() {
    if (!selectedWebsiteForDocument.value) return;
    documentForm.post(`/websites/${selectedWebsiteForDocument.value.id}/documents`, {
        onSuccess: () => {
            showAddDocumentDialog.value = false;
            documentForm.reset();
            selectedWebsiteForDocument.value = null;
        },
    });
}

function submitProduct() {
    productForm.post(`/companies/${props.company.id}/products`, {
        onSuccess: () => {
            showAddProductDialog.value = false;
            productForm.reset();
        },
    });
}

function openLinkDocumentsDialog(product: Product) {
    selectedProductForLinking.value = product;
    linkDocumentsForm.document_ids = product.documents.map(d => d.id);
    showLinkDocumentsDialog.value = true;
}

function submitLinkDocuments() {
    if (!selectedProductForLinking.value) return;
    linkDocumentsForm.post(`/products/${selectedProductForLinking.value.id}/documents`, {
        onSuccess: () => {
            showLinkDocumentsDialog.value = false;
            linkDocumentsForm.reset();
            selectedProductForLinking.value = null;
        },
    });
}

function unlinkDocument(product: Product, documentId: number) {
    router.delete(`/products/${product.id}/documents/${documentId}`, {
        preserveScroll: true,
    });
}

function discoverPolicies(website: Website) {
    router.post(`/websites/${website.id}/discover`, {}, {
        preserveScroll: true,
    });
}

function scrapeDocument(document: Document) {
    router.post(`/documents/${document.id}/scrape`, {}, {
        preserveScroll: true,
    });
}

function deleteWebsite(website: Website) {
    if (confirm(`Are you sure you want to delete ${website.name || website.url}?`)) {
        router.delete(`/websites/${website.id}`);
    }
}

function deleteDocument(document: Document) {
    if (confirm(`Are you sure you want to delete this ${document.type} document?`)) {
        router.delete(`/documents/${document.id}`);
    }
}

function deleteProduct(product: Product) {
    if (confirm(`Are you sure you want to delete ${product.name}?`)) {
        router.delete(`/products/${product.id}`);
    }
}

function toggleWebsiteExpanded(websiteId: number) {
    if (expandedWebsites.value.has(websiteId)) {
        expandedWebsites.value.delete(websiteId);
    } else {
        expandedWebsites.value.add(websiteId);
    }
}

function toggleProductExpanded(productId: number) {
    if (expandedProducts.value.has(productId)) {
        expandedProducts.value.delete(productId);
    } else {
        expandedProducts.value.add(productId);
    }
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
        case 'pending':
        default:
            return Clock;
    }
}

function getScrapeStatusColor(status: string) {
    switch (status) {
        case 'success':
            return 'text-green-500';
        case 'failed':
        case 'blocked':
            return 'text-red-500';
        case 'running':
            return 'text-blue-500 animate-spin';
        case 'pending':
        default:
            return 'text-yellow-500';
    }
}

function getProductTypeIcon(type: string) {
    switch (type) {
        case 'app':
            return Smartphone;
        case 'game':
            return Gamepad2;
        case 'platform':
        case 'website':
            return Monitor;
        default:
            return Package;
    }
}

function getRatingColor(rating: string | null): string {
    if (!rating) return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';

    const colors: Record<string, string> = {
        'A': 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
        'B': 'bg-lime-100 text-lime-800 dark:bg-lime-900 dark:text-lime-300',
        'C': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        'D': 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
        'F': 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    };
    return colors[rating] || colors['C'];
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

const totalPolicies = computed(() =>
    props.websites.reduce((sum, w) => sum + w.policies.length, 0)
);

const retrievedPolicies = computed(() =>
    props.websites.reduce((sum, w) => sum + w.policies.filter(p => p.is_retrieved).length, 0)
);

// Build a mapping from website ID + policy URL to global index
const policyGlobalIndices = computed(() => {
    const indices = new Map<string, number>();
    let globalIndex = 0;

    for (const website of props.websites) {
        for (const policy of website.policies) {
            const key = `${website.id}:${policy.url}`;
            indices.set(key, globalIndex);
            globalIndex++;
        }
    }

    return indices;
});

function getGlobalPolicyIndex(websiteId: number, policyUrl: string): number {
    return policyGlobalIndices.value.get(`${websiteId}:${policyUrl}`) ?? 0;
}

// Get all retrieved policies for linking to products
const allDocuments = computed(() => {
    return props.websites.flatMap(website =>
        website.policies
            .filter(p => p.is_retrieved && p.document_id)
            .map(p => ({
                id: p.document_id!,
                type: p.document_type || 'Unknown',
                url: p.url,
            }))
    );
});

function isDocumentLinked(documentId: number): boolean {
    return linkDocumentsForm.document_ids.includes(documentId);
}

function toggleDocumentLink(documentId: number) {
    const index = linkDocumentsForm.document_ids.indexOf(documentId);
    if (index === -1) {
        linkDocumentsForm.document_ids.push(documentId);
    } else {
        linkDocumentsForm.document_ids.splice(index, 1);
    }
}

function navigateToPolicyDetail(policyIndex: number) {
    router.visit(`/companies/${props.company.id}/policy/${policyIndex}`);
}

const retrievingPolicies = ref<Set<string>>(new Set());
const retrievingAllForWebsite = ref<Set<number>>(new Set());

function retrievePolicy(website: Website, policy: Policy, event: Event) {
    event.stopPropagation();
    if (policy.discovery_job_id === null || policy.discovery_index === null) return;

    const key = `${policy.discovery_job_id}-${policy.discovery_index}`;
    retrievingPolicies.value.add(key);

    router.post(`/queue/discovery/${policy.discovery_job_id}/policy/${policy.discovery_index}/retrieve`, {}, {
        preserveScroll: true,
        onFinish: () => {
            retrievingPolicies.value.delete(key);
        },
    });
}

function scrapePolicy(policy: Policy, event: Event) {
    event.stopPropagation();
    if (!policy.document_id) return;

    router.post(`/documents/${policy.document_id}/scrape`, {}, {
        preserveScroll: true,
    });
}

function retrieveAllPolicies(website: Website, event: Event) {
    event.stopPropagation();
    if (!website.latest_discovery?.id) return;

    retrievingAllForWebsite.value.add(website.id);

    router.post(`/queue/discovery/${website.latest_discovery.id}/retrieve-all`, {}, {
        preserveScroll: true,
        onFinish: () => {
            retrievingAllForWebsite.value.delete(website.id);
        },
    });
}

function isPolicyRetrieving(policy: Policy): boolean {
    if (policy.discovery_job_id === null || policy.discovery_index === null) return false;
    return retrievingPolicies.value.has(`${policy.discovery_job_id}-${policy.discovery_index}`);
}

function hasUnretrievedPolicies(website: Website): boolean {
    return website.policies.some(p => !p.has_version);
}

function formatDocumentType(type: string | null): string {
    if (!type) return 'Unknown';
    const smallWords = ['of', 'the', 'a', 'an', 'and', 'or', 'for', 'to', 'in', 'on'];
    return type
        .replaceAll('-', ' ')
        .split(' ')
        .map((word, index) => {
            if (index > 0 && smallWords.includes(word.toLowerCase())) {
                return word.toLowerCase();
            }
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        })
        .join(' ');
}
</script>

<template>
    <Head :title="company.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div class="flex items-start gap-4">
                    <div
                        class="flex h-16 w-16 items-center justify-center rounded-lg bg-muted"
                    >
                        <Building2 class="h-8 w-8 text-muted-foreground" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold">{{ company.name }}</h1>
                        <div class="mt-1 flex items-center gap-3 text-sm text-muted-foreground">
                            <span v-if="company.industry">{{ company.industry }}</span>
                            <a
                                v-if="company.website"
                                :href="company.website"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="flex items-center gap-1 hover:text-foreground"
                            >
                                <ExternalLink class="h-3 w-3" />
                                Website
                            </a>
                        </div>
                        <p v-if="company.description" class="mt-2 max-w-2xl text-sm text-muted-foreground">
                            {{ company.description }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Link :href="`/companies/${company.id}/edit`">
                        <Button variant="outline" size="sm">
                            <Pencil class="mr-2 h-4 w-4" />
                            Edit
                        </Button>
                    </Link>
                    <Dialog v-model:open="showDeleteDialog">
                        <DialogTrigger as-child>
                            <Button variant="outline" size="sm" class="text-red-600 hover:text-red-700">
                                <Trash2 class="mr-2 h-4 w-4" />
                                Delete
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Delete Company</DialogTitle>
                                <DialogDescription>
                                    Are you sure you want to delete {{ company.name }}? This will also delete all associated websites, documents, products, and analysis data. This action cannot be undone.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <Button variant="outline" @click="showDeleteDialog = false">
                                    Cancel
                                </Button>
                                <Button variant="destructive" @click="deleteCompany">
                                    Delete
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <!-- Tags -->
            <div v-if="tags.length > 0" class="flex flex-wrap gap-2">
                <Badge
                    v-for="tag in tags"
                    :key="tag.id"
                    variant="secondary"
                    :style="tag.color ? { backgroundColor: tag.color + '20', color: tag.color } : {}"
                >
                    {{ tag.name }}
                </Badge>
            </div>

            <!-- Products Section -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">
                        Products & Services
                        <span class="ml-2 text-sm font-normal text-muted-foreground">
                            {{ products.length }} product{{ products.length !== 1 ? 's' : '' }}
                        </span>
                    </h2>
                    <Dialog v-model:open="showAddProductDialog">
                        <DialogTrigger as-child>
                            <Button size="sm">
                                <Plus class="mr-2 h-4 w-4" />
                                Add Product
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Add Product or Service</DialogTitle>
                                <DialogDescription>
                                    Add a product, service, app, or game for {{ company.name }}.
                                </DialogDescription>
                            </DialogHeader>
                            <form @submit.prevent="submitProduct">
                                <div class="grid gap-4 py-4">
                                    <div class="grid gap-2">
                                        <Label for="product_name">Name</Label>
                                        <Input
                                            id="product_name"
                                            v-model="productForm.name"
                                            type="text"
                                            placeholder="Disney+"
                                            required
                                        />
                                        <p v-if="productForm.errors.name" class="text-sm text-red-500">
                                            {{ productForm.errors.name }}
                                        </p>
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="product_type">Type</Label>
                                        <Select v-model="productForm.type" required>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="(label, value) in productTypes"
                                                    :key="value"
                                                    :value="value"
                                                >
                                                    {{ label }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="product_url">Website URL (optional)</Label>
                                        <Input
                                            id="product_url"
                                            v-model="productForm.url"
                                            type="url"
                                            placeholder="https://disneyplus.com"
                                        />
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div class="grid gap-2">
                                            <Label for="app_store">App Store URL</Label>
                                            <Input
                                                id="app_store"
                                                v-model="productForm.app_store_url"
                                                type="url"
                                                placeholder="https://apps.apple.com/..."
                                            />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label for="play_store">Play Store URL</Label>
                                            <Input
                                                id="play_store"
                                                v-model="productForm.play_store_url"
                                                type="url"
                                                placeholder="https://play.google.com/..."
                                            />
                                        </div>
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="product_description">Description (optional)</Label>
                                        <Input
                                            id="product_description"
                                            v-model="productForm.description"
                                            type="text"
                                            placeholder="Streaming service for movies and TV shows"
                                        />
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        @click="showAddProductDialog = false"
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" :disabled="productForm.processing">
                                        <Loader2
                                            v-if="productForm.processing"
                                            class="mr-2 h-4 w-4 animate-spin"
                                        />
                                        Add Product
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <!-- Products Empty State -->
                <Card v-if="products.length === 0">
                    <CardContent class="flex flex-col items-center justify-center py-8">
                        <Package class="h-10 w-10 text-muted-foreground" />
                        <h3 class="mt-3 text-base font-semibold">No products yet</h3>
                        <p class="mt-1 text-center text-sm text-muted-foreground">
                            Add products and services to link their specific policies.
                        </p>
                        <Button class="mt-3" size="sm" @click="showAddProductDialog = true">
                            <Plus class="mr-2 h-4 w-4" />
                            Add Product
                        </Button>
                    </CardContent>
                </Card>

                <!-- Products List -->
                <div v-else class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <Card v-for="product in products" :key="product.id" class="overflow-hidden">
                        <Collapsible
                            :open="expandedProducts.has(product.id)"
                            @update:open="toggleProductExpanded(product.id)"
                        >
                            <CardHeader class="pb-2">
                                <div class="flex items-start justify-between">
                                    <CollapsibleTrigger class="flex items-start gap-2 text-left hover:opacity-80">
                                        <component
                                            :is="getProductTypeIcon(product.type)"
                                            class="mt-0.5 h-4 w-4 text-muted-foreground"
                                        />
                                        <div>
                                            <CardTitle class="text-sm">{{ product.name }}</CardTitle>
                                            <CardDescription class="text-xs">
                                                {{ product.type_label }}
                                                <span v-if="product.documents.length > 0" class="ml-1">
                                                    - {{ product.documents.length }} doc{{ product.documents.length !== 1 ? 's' : '' }}
                                                </span>
                                            </CardDescription>
                                        </div>
                                    </CollapsibleTrigger>
                                    <div class="flex items-center gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            @click.stop="openLinkDocumentsDialog(product)"
                                            title="Link documents"
                                        >
                                            <Link2 class="h-4 w-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="text-red-600 hover:text-red-700"
                                            @click.stop="deleteProduct(product)"
                                            title="Delete product"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            </CardHeader>
                            <CollapsibleContent>
                                <CardContent class="pt-0">
                                    <div v-if="product.url || product.app_store_url || product.play_store_url" class="mb-2 flex flex-wrap gap-2">
                                        <a
                                            v-if="product.url"
                                            :href="product.url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                        >
                                            <Globe class="h-3 w-3" />
                                            Website
                                        </a>
                                        <a
                                            v-if="product.app_store_url"
                                            :href="product.app_store_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                        >
                                            <Smartphone class="h-3 w-3" />
                                            App Store
                                        </a>
                                        <a
                                            v-if="product.play_store_url"
                                            :href="product.play_store_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                        >
                                            <Smartphone class="h-3 w-3" />
                                            Play Store
                                        </a>
                                    </div>
                                    <div v-if="product.documents.length === 0" class="py-2 text-center text-xs text-muted-foreground">
                                        No documents linked.
                                    </div>
                                    <div v-else class="space-y-1">
                                        <div
                                            v-for="doc in product.documents"
                                            :key="doc.id"
                                            class="flex items-center justify-between rounded bg-muted/50 px-2 py-1"
                                        >
                                            <div class="flex items-center gap-2">
                                                <FileText class="h-3 w-3 text-muted-foreground" />
                                                <span class="text-xs">{{ doc.type }}</span>
                                                <Badge v-if="doc.is_primary" variant="outline" class="text-[10px] px-1 py-0">
                                                    Primary
                                                </Badge>
                                            </div>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                class="h-5 w-5 p-0 text-muted-foreground hover:text-red-600"
                                                @click="unlinkDocument(product, doc.id)"
                                                title="Unlink"
                                            >
                                                <Unlink class="h-3 w-3" />
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </CollapsibleContent>
                        </Collapsible>
                    </Card>
                </div>
            </div>

            <!-- Websites Section -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">
                        Websites & Policies
                        <span class="ml-2 text-sm font-normal text-muted-foreground">
                            {{ websites.length }} website{{ websites.length !== 1 ? 's' : '' }},
                            {{ totalPolicies }} polic{{ totalPolicies !== 1 ? 'ies' : 'y' }}
                            ({{ retrievedPolicies }} retrieved)
                        </span>
                    </h2>
                    <Dialog v-model:open="showAddWebsiteDialog">
                        <DialogTrigger as-child>
                            <Button size="sm">
                                <Plus class="mr-2 h-4 w-4" />
                                Add Website
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Add Website</DialogTitle>
                                <DialogDescription>
                                    Add a website to track legal documents for {{ company.name }}.
                                </DialogDescription>
                            </DialogHeader>
                            <form @submit.prevent="submitWebsite">
                                <div class="grid gap-4 py-4">
                                    <div class="grid gap-2">
                                        <Label for="url">Website URL</Label>
                                        <Input
                                            id="url"
                                            v-model="websiteForm.url"
                                            type="url"
                                            placeholder="https://example.com"
                                            required
                                        />
                                        <p v-if="websiteForm.errors.url" class="text-sm text-red-500">
                                            {{ websiteForm.errors.url }}
                                        </p>
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="name">Display Name (optional)</Label>
                                        <Input
                                            id="name"
                                            v-model="websiteForm.name"
                                            type="text"
                                            placeholder="Main Website"
                                        />
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <Checkbox
                                            id="is_primary"
                                            :checked="websiteForm.is_primary"
                                            @update:checked="websiteForm.is_primary = $event"
                                        />
                                        <Label for="is_primary" class="text-sm font-normal">
                                            Set as primary website
                                        </Label>
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        @click="showAddWebsiteDialog = false"
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" :disabled="websiteForm.processing">
                                        <Loader2
                                            v-if="websiteForm.processing"
                                            class="mr-2 h-4 w-4 animate-spin"
                                        />
                                        Add Website
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <!-- Empty State -->
                <Card v-if="websites.length === 0">
                    <CardContent class="flex flex-col items-center justify-center py-12">
                        <Globe class="h-12 w-12 text-muted-foreground" />
                        <h3 class="mt-4 text-lg font-semibold">No websites yet</h3>
                        <p class="mt-2 text-center text-sm text-muted-foreground">
                            Add websites for this company to start tracking their
                            <br />privacy policies, terms of service, and other legal documents.
                        </p>
                        <Button class="mt-4" size="sm" @click="showAddWebsiteDialog = true">
                            <Plus class="mr-2 h-4 w-4" />
                            Add Website
                        </Button>
                    </CardContent>
                </Card>

                <!-- Websites List -->
                <div v-else class="space-y-4">
                    <Card v-for="website in websites" :key="website.id">
                        <Collapsible
                            :open="expandedWebsites.has(website.id)"
                            @update:open="toggleWebsiteExpanded(website.id)"
                        >
                            <CardHeader class="pb-2">
                                <div class="flex items-start justify-between">
                                    <CollapsibleTrigger class="flex items-start gap-3 text-left hover:opacity-80">
                                        <Globe class="mt-0.5 h-5 w-5 text-muted-foreground" />
                                        <div>
                                            <CardTitle class="text-base">
                                                {{ website.name || website.base_url }}
                                                <Badge v-if="website.is_primary" variant="secondary" class="ml-2 text-xs">
                                                    Primary
                                                </Badge>
                                            </CardTitle>
                                            <CardDescription class="mt-1">
                                                <a
                                                    :href="website.url"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="flex items-center gap-1 hover:text-foreground"
                                                    @click.stop
                                                >
                                                    <ExternalLink class="h-3 w-3" />
                                                    {{ website.url }}
                                                </a>
                                            </CardDescription>
                                        </div>
                                    </CollapsibleTrigger>
                                    <div class="flex items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            @click.stop="discoverPolicies(website)"
                                            :disabled="website.discovery_status === 'running'"
                                        >
                                            <Radar
                                                v-if="website.discovery_status !== 'running'"
                                                class="mr-2 h-4 w-4"
                                            />
                                            <Loader2
                                                v-else
                                                class="mr-2 h-4 w-4 animate-spin"
                                            />
                                            Find Policies
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            @click.stop="openAddDocumentDialog(website)"
                                        >
                                            <Plus class="mr-2 h-4 w-4" />
                                            Manually Add
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="text-red-600 hover:text-red-700"
                                            @click.stop="deleteWebsite(website)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            </CardHeader>
                            <CollapsibleContent>
                                <CardContent class="pt-0">
                                    <!-- Discovery Stats -->
                                    <div
                                        v-if="website.latest_discovery"
                                        class="mb-4 flex items-center justify-between text-sm"
                                    >
                                        <span class="text-muted-foreground">
                                            {{ website.latest_discovery.urls_crawled }} pages crawled
                                        </span>
                                        <Button
                                            v-if="hasUnretrievedPolicies(website)"
                                            size="sm"
                                            @click="retrieveAllPolicies(website, $event)"
                                            :disabled="retrievingAllForWebsite.has(website.id)"
                                        >
                                            <Loader2 v-if="retrievingAllForWebsite.has(website.id)" class="mr-2 h-4 w-4 animate-spin" />
                                            <Download v-else class="mr-2 h-4 w-4" />
                                            Retrieve All
                                        </Button>
                                    </div>

                                    <!-- Unified Policies List -->
                                    <div v-if="website.policies.length === 0" class="py-4 text-center text-sm text-muted-foreground">
                                        No policies discovered for this website yet.
                                    </div>
                                    <div v-else class="space-y-2">
                                        <button
                                            v-for="policy in website.policies"
                                            :key="policy.url"
                                            type="button"
                                            class="w-full text-left flex items-start justify-between gap-3 rounded-lg border p-3 hover:bg-muted/30 transition-colors cursor-pointer"
                                            @click="navigateToPolicyDetail(getGlobalPolicyIndex(website.id, policy.url))"
                                        >
                                            <div class="flex items-start gap-3 flex-1 min-w-0">
                                                <!-- Status icon -->
                                                <div class="mt-0.5">
                                                    <component
                                                        v-if="policy.is_retrieved"
                                                        :is="getScrapeStatusIcon(policy.scrape_status || 'pending')"
                                                        class="h-4 w-4"
                                                        :class="getScrapeStatusColor(policy.scrape_status || 'pending')"
                                                    />
                                                    <Clock v-else class="h-4 w-4 text-muted-foreground" />
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <span class="font-medium">
                                                            {{ policy.document_type || formatDocumentType(policy.detected_type) }}
                                                        </span>
                                                        <Badge
                                                            v-if="policy.has_version"
                                                            variant="default"
                                                            class="text-xs"
                                                        >
                                                            Retrieved
                                                        </Badge>
                                                        <Badge
                                                            v-else-if="policy.is_retrieved && policy.scrape_status === 'pending'"
                                                            variant="outline"
                                                            class="text-xs text-yellow-600"
                                                        >
                                                            Pending
                                                        </Badge>
                                                        <Badge
                                                            v-else-if="policy.is_retrieved && policy.scrape_status === 'failed'"
                                                            variant="outline"
                                                            class="text-xs text-red-600"
                                                        >
                                                            Failed
                                                        </Badge>
                                                        <Badge
                                                            v-else
                                                            variant="outline"
                                                            class="text-xs"
                                                        >
                                                            Not Retrieved
                                                        </Badge>
                                                        <Badge
                                                            v-if="policy.confidence >= 0.9"
                                                            variant="secondary"
                                                            class="text-xs"
                                                        >
                                                            High confidence
                                                        </Badge>
                                                        <Badge
                                                            v-if="policy.overall_rating"
                                                            :class="getRatingColor(policy.overall_rating)"
                                                            class="text-xs"
                                                        >
                                                            {{ policy.overall_rating }}
                                                        </Badge>
                                                        <!-- Product tags -->
                                                        <Badge
                                                            v-for="product in policy.products"
                                                            :key="product.id"
                                                            variant="secondary"
                                                            class="text-xs"
                                                        >
                                                            {{ product.name }}
                                                        </Badge>
                                                    </div>
                                                    <p class="mt-1 text-xs text-muted-foreground break-all">
                                                        {{ policy.url }}
                                                    </p>
                                                    <div class="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                                                        <span class="capitalize">via {{ policy.discovery_method.replace('_', ' ') }}</span>
                                                        <span v-if="policy.is_retrieved && policy.version_count > 0">
                                                            {{ policy.version_count }} version{{ policy.version_count !== 1 ? 's' : '' }}
                                                        </span>
                                                        <span v-if="policy.last_scraped_at">
                                                            Last retrieved: {{ formatDate(policy.last_scraped_at) }}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-1 flex-shrink-0">
                                                <!-- Retrieve button (for policies without versions from discovery) -->
                                                <Button
                                                    v-if="!policy.has_version && policy.discovery_index !== null"
                                                    variant="outline"
                                                    size="sm"
                                                    title="Retrieve this policy"
                                                    @click="retrievePolicy(website, policy, $event)"
                                                    :disabled="isPolicyRetrieving(policy)"
                                                >
                                                    <Loader2 v-if="isPolicyRetrieving(policy)" class="mr-1 h-3 w-3 animate-spin" />
                                                    <Download v-else class="mr-1 h-3 w-3" />
                                                    Retrieve
                                                </Button>
                                                <!-- Re-retrieve button (for policies with versions) -->
                                                <Button
                                                    v-if="policy.has_version"
                                                    variant="ghost"
                                                    size="sm"
                                                    @click="scrapePolicy(policy, $event)"
                                                    :disabled="policy.scrape_status === 'running'"
                                                    title="Retrieve again"
                                                >
                                                    <RefreshCw
                                                        class="h-4 w-4"
                                                        :class="{ 'animate-spin': policy.scrape_status === 'running' }"
                                                    />
                                                </Button>
                                                <a
                                                    :href="policy.url"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    @click.stop
                                                >
                                                    <Button variant="ghost" size="sm" title="Open in new tab">
                                                        <ExternalLink class="h-4 w-4" />
                                                    </Button>
                                                </a>
                                            </div>
                                        </button>
                                    </div>
                                </CardContent>
                            </CollapsibleContent>
                        </Collapsible>
                    </Card>
                </div>
            </div>
        </div>

        <!-- Add Document Dialog -->
        <Dialog v-model:open="showAddDocumentDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add Document</DialogTitle>
                    <DialogDescription v-if="selectedWebsiteForDocument">
                        Add a legal document for {{ selectedWebsiteForDocument.name || selectedWebsiteForDocument.base_url }}.
                    </DialogDescription>
                </DialogHeader>
                <form @submit.prevent="submitDocument">
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <Label for="doc_url">Document URL</Label>
                            <Input
                                id="doc_url"
                                v-model="documentForm.url"
                                type="url"
                                placeholder="https://example.com/privacy-policy"
                                required
                            />
                            <p v-if="documentForm.errors.url" class="text-sm text-red-500">
                                {{ documentForm.errors.url }}
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label for="document_type">Document Type</Label>
                            <Select v-model="documentForm.document_type_id" required>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select document type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="type in documentTypes"
                                        :key="type.id"
                                        :value="String(type.id)"
                                    >
                                        {{ type.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="documentForm.errors.document_type_id" class="text-sm text-red-500">
                                {{ documentForm.errors.document_type_id }}
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label for="title">Title (optional)</Label>
                            <Input
                                id="title"
                                v-model="documentForm.title"
                                type="text"
                                placeholder="Privacy Policy"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="frequency">Retrieval Frequency</Label>
                            <Select v-model="documentForm.scrape_frequency">
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="hourly">Hourly</SelectItem>
                                    <SelectItem value="daily">Daily</SelectItem>
                                    <SelectItem value="weekly">Weekly</SelectItem>
                                    <SelectItem value="monthly">Monthly</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="flex items-center space-x-2">
                            <Checkbox
                                id="is_monitored"
                                :checked="documentForm.is_monitored"
                                @update:checked="documentForm.is_monitored = $event"
                            />
                            <Label for="is_monitored" class="text-sm font-normal">
                                Monitor for changes
                            </Label>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showAddDocumentDialog = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="documentForm.processing">
                            <Loader2
                                v-if="documentForm.processing"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            Add Document
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Link Documents Dialog -->
        <Dialog v-model:open="showLinkDocumentsDialog">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Link Documents to {{ selectedProductForLinking?.name }}</DialogTitle>
                    <DialogDescription>
                        Select which documents apply to this product or service.
                    </DialogDescription>
                </DialogHeader>
                <form @submit.prevent="submitLinkDocuments">
                    <div class="max-h-80 overflow-y-auto py-4">
                        <div v-if="allDocuments.length === 0" class="py-4 text-center text-sm text-muted-foreground">
                            No documents available. Add documents to websites first.
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="doc in allDocuments"
                                :key="doc.id"
                                class="flex items-center space-x-3 rounded-lg border p-3 cursor-pointer hover:bg-muted/50"
                                @click="toggleDocumentLink(doc.id)"
                            >
                                <Checkbox
                                    :checked="isDocumentLinked(doc.id)"
                                    @update:checked="toggleDocumentLink(doc.id)"
                                />
                                <div class="flex-1">
                                    <div class="font-medium text-sm">{{ doc.type }}</div>
                                    <div class="text-xs text-muted-foreground truncate">{{ doc.url }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showLinkDocumentsDialog = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="linkDocumentsForm.processing">
                            <Loader2
                                v-if="linkDocumentsForm.processing"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            Save Links
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

    </AppLayout>
</template>
