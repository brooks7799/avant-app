<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Link, router } from '@inertiajs/vue3';
import {
    Mail,
    Building2,
    ExternalLink,
    Check,
    X,
    ArrowLeft,
    Calendar,
    AtSign,
    Shield,
    Info
} from 'lucide-vue-next';

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
        from: string | null;
        date: string | null;
        snippet: string | null;
        body_html: string | null;
    } | null;
    detected_policy_urls: string[] | null;
    company_id: number | null;
    gmail_message_id: string | null;
    created_at: string;
}

const props = defineProps<{
    discovered: DiscoveredCompany;
}>();

function getConfidenceBadgeVariant(level: string) {
    switch (level) {
        case 'high': return 'default';
        case 'medium': return 'secondary';
        case 'low': return 'outline';
        default: return 'outline';
    }
}

function formatDate(dateString: string | null): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString();
}

function importCompany() {
    router.post(`/email-discovery/${props.discovered.id}/import`);
}

function dismissCompany() {
    router.post(`/email-discovery/${props.discovered.id}/dismiss`);
}

function getGmailUrl(): string | null {
    if (!props.discovered.gmail_message_id) return null;
    return `https://mail.google.com/mail/u/0/#inbox/${props.discovered.gmail_message_id}`;
}
</script>

<template>
    <AppLayout title="Email Preview">
        <div class="flex h-full flex-1 flex-col gap-6 p-6 max-w-4xl mx-auto">
            <!-- Back Button -->
            <div>
                <Button variant="ghost" size="sm" as-child>
                    <Link href="/email-discovery">
                        <ArrowLeft class="mr-2 h-4 w-4" />
                        Back to Email Discovery
                    </Link>
                </Button>
            </div>

            <!-- Header -->
            <div class="flex items-start justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10">
                        <Building2 class="h-6 w-6 text-primary" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold">{{ discovered.name }}</h1>
                        <p class="text-muted-foreground">{{ discovered.domain }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Badge :variant="getConfidenceBadgeVariant(discovered.confidence_level)">
                        {{ Math.round(discovered.confidence_score * 100) }}% confidence
                    </Badge>
                    <Badge variant="outline">{{ discovered.detection_source_label }}</Badge>
                    <Badge
                        :variant="discovered.status === 'pending' ? 'secondary' : discovered.status === 'imported' ? 'default' : 'outline'"
                    >
                        {{ discovered.status }}
                    </Badge>
                </div>
            </div>

            <!-- Import Explanation -->
            <div v-if="discovered.status === 'pending'" class="flex items-start gap-2 rounded-lg bg-blue-50 dark:bg-blue-950/30 p-4 text-sm text-blue-800 dark:text-blue-200">
                <Info class="h-4 w-4 mt-0.5 flex-shrink-0" />
                <div>
                    <strong>What happens when you import?</strong>
                    <p class="mt-1 text-blue-700 dark:text-blue-300">
                        Importing creates a company profile and automatically discovers their privacy policy, terms of service, and other legal documents. You'll be able to track changes and get notified when policies are updated.
                    </p>
                </div>
            </div>

            <!-- Email Details Card -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Mail class="h-5 w-5" />
                        Email Details
                    </CardTitle>
                    <CardDescription>
                        Information extracted from the detected email
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Subject -->
                    <div v-if="discovered.email_metadata?.subject">
                        <h4 class="text-sm font-medium text-muted-foreground mb-1">Subject</h4>
                        <p class="text-base">{{ discovered.email_metadata.subject }}</p>
                    </div>

                    <!-- From -->
                    <div class="flex items-center gap-6">
                        <div>
                            <h4 class="text-sm font-medium text-muted-foreground mb-1">From</h4>
                            <div class="flex items-center gap-2">
                                <AtSign class="h-4 w-4 text-muted-foreground" />
                                <span>{{ discovered.email_address }}</span>
                            </div>
                        </div>
                        <div v-if="discovered.email_metadata?.date">
                            <h4 class="text-sm font-medium text-muted-foreground mb-1">Date</h4>
                            <div class="flex items-center gap-2">
                                <Calendar class="h-4 w-4 text-muted-foreground" />
                                <span>{{ formatDate(discovered.email_metadata.date) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Email Content -->
                    <div v-if="discovered.email_metadata?.body_html">
                        <h4 class="text-sm font-medium text-muted-foreground mb-2">Email Content</h4>
                        <div class="rounded-lg border bg-white overflow-hidden">
                            <iframe
                                :srcdoc="discovered.email_metadata.body_html"
                                class="w-full min-h-[400px] border-0"
                                sandbox="allow-same-origin"
                                title="Email preview"
                            />
                        </div>
                    </div>
                    <div v-else-if="discovered.email_metadata?.snippet">
                        <h4 class="text-sm font-medium text-muted-foreground mb-1">Preview</h4>
                        <p class="text-sm text-muted-foreground bg-muted/50 rounded-lg p-3">
                            {{ discovered.email_metadata.snippet }}
                        </p>
                    </div>

                    <!-- View in Gmail -->
                    <div v-if="getGmailUrl()" class="pt-2">
                        <Button variant="outline" as-child>
                            <a :href="getGmailUrl()!" target="_blank" rel="noopener noreferrer">
                                <Mail class="mr-2 h-4 w-4" />
                                View Full Email in Gmail
                                <ExternalLink class="ml-2 h-4 w-4" />
                            </a>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Detected Policy URLs -->
            <Card v-if="discovered.detected_policy_urls && discovered.detected_policy_urls.length > 0">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Shield class="h-5 w-5" />
                        Detected Policy URLs
                    </CardTitle>
                    <CardDescription>
                        Legal document URLs found in the email
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ul class="space-y-2">
                        <li v-for="url in discovered.detected_policy_urls" :key="url" class="flex items-center gap-2">
                            <ExternalLink class="h-4 w-4 text-muted-foreground flex-shrink-0" />
                            <a
                                :href="url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-sm text-primary hover:underline truncate"
                            >
                                {{ url }}
                            </a>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <!-- Company Website -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Building2 class="h-5 w-5" />
                        Company Website
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <ExternalLink class="h-4 w-4 text-muted-foreground" />
                            <a
                                :href="`https://${discovered.domain}`"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-primary hover:underline"
                            >
                                {{ discovered.domain }}
                            </a>
                        </div>
                        <Button variant="outline" size="sm" as-child>
                            <a :href="`https://${discovered.domain}`" target="_blank" rel="noopener noreferrer">
                                Visit Website
                                <ExternalLink class="ml-2 h-4 w-4" />
                            </a>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Actions -->
            <div v-if="discovered.status === 'pending'" class="flex items-center justify-end gap-3 pt-4 border-t">
                <Button variant="outline" @click="dismissCompany">
                    <X class="mr-2 h-4 w-4" />
                    Dismiss
                </Button>
                <Button @click="importCompany">
                    <Check class="mr-2 h-4 w-4" />
                    Import Company
                </Button>
            </div>

            <!-- Already Processed -->
            <div v-else class="flex items-center justify-center gap-2 pt-4 border-t text-muted-foreground">
                <template v-if="discovered.status === 'imported'">
                    <Check class="h-5 w-5 text-green-600" />
                    <span>This company has been imported.</span>
                    <Button v-if="discovered.company_id" variant="link" as-child>
                        <Link :href="`/companies/${discovered.company_id}`">
                            View Company
                        </Link>
                    </Button>
                </template>
                <template v-else-if="discovered.status === 'dismissed'">
                    <X class="h-5 w-5" />
                    <span>This company has been dismissed.</span>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
