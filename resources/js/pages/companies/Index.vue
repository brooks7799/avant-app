<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, Plus, ExternalLink, AlertCircle, CheckCircle2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

interface Company {
    id: number;
    name: string;
    slug: string;
    website: string | null;
    industry: string | null;
    is_active: boolean;
    documents_count: number;
    active_documents_count: number;
    has_analysis: boolean;
    overall_score: number | null;
    overall_rating: string | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    companies: Company[];
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Companies', href: '/companies' },
];

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

function getStatusInfo(company: Company) {
    if (company.has_analysis && company.overall_score !== null) {
        return {
            label: `Score: ${company.overall_score}`,
            variant: 'default' as const,
            icon: CheckCircle2,
        };
    }
    if (company.documents_count > 0) {
        return {
            label: 'Processing',
            variant: 'secondary' as const,
            icon: AlertCircle,
        };
    }
    return {
        label: 'Pending',
        variant: 'outline' as const,
        icon: AlertCircle,
    };
}
</script>

<template>
    <Head title="Companies" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl px-4 py-6">
        <div class="flex h-full flex-1 flex-col gap-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Companies</h1>
                    <p class="text-muted-foreground">
                        Manage companies and their legal documents
                    </p>
                </div>
                <Link href="/companies/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Add Company
                    </Button>
                </Link>
            </div>

            <!-- Empty State -->
            <div
                v-if="companies.length === 0"
                class="flex flex-1 flex-col items-center justify-center rounded-lg border border-dashed p-8 text-center"
            >
                <Building2 class="h-12 w-12 text-muted-foreground" />
                <h3 class="mt-4 text-lg font-semibold">No companies yet</h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    Get started by adding a company to track their legal documents.
                </p>
                <Link href="/companies/create" class="mt-4">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Add Company
                    </Button>
                </Link>
            </div>

            <!-- Company Grid -->
            <div
                v-else
                class="grid gap-4 md:grid-cols-2 lg:grid-cols-3"
            >
                <Link
                    v-for="company in companies"
                    :key="company.id"
                    :href="`/companies/${company.id}`"
                    class="block"
                >
                    <Card class="h-full transition-colors hover:bg-muted/50">
                        <CardHeader class="pb-3">
                            <div class="flex items-start justify-between">
                                <div class="space-y-1">
                                    <CardTitle class="text-lg">
                                        {{ company.name }}
                                    </CardTitle>
                                    <CardDescription v-if="company.industry">
                                        {{ company.industry }}
                                    </CardDescription>
                                </div>
                                <Badge
                                    v-if="company.has_analysis"
                                    :class="getRatingColor(company.overall_rating)"
                                >
                                    {{ company.overall_rating || 'N/A' }}
                                </Badge>
                                <Badge v-else variant="outline">
                                    Pending
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div class="flex items-center justify-between text-sm">
                                <div class="flex items-center gap-4 text-muted-foreground">
                                    <span>
                                        {{ company.documents_count }} document{{ company.documents_count !== 1 ? 's' : '' }}
                                    </span>
                                    <span v-if="company.website" class="flex items-center gap-1">
                                        <ExternalLink class="h-3 w-3" />
                                        Website
                                    </span>
                                </div>
                                <component
                                    :is="getStatusInfo(company).icon"
                                    class="h-4 w-4"
                                    :class="{
                                        'text-green-500': company.has_analysis,
                                        'text-yellow-500': !company.has_analysis && company.documents_count > 0,
                                        'text-muted-foreground': !company.has_analysis && company.documents_count === 0,
                                    }"
                                />
                            </div>
                        </CardContent>
                    </Card>
                </Link>
            </div>
        </div>
        </div>
    </AppLayout>
</template>
