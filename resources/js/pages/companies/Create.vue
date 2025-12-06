<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Companies', href: '/companies' },
    { title: 'Add Company', href: '/companies/create' },
];

const form = useForm({
    name: '',
    website: '',
    description: '',
    industry: '',
});

function submit() {
    form.post('/companies');
}
</script>

<template>
    <Head title="Add Company" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="mx-auto w-full max-w-2xl">
                <Card>
                    <CardHeader>
                        <CardTitle>Add Company</CardTitle>
                        <CardDescription>
                            Add a new company to track their legal documents.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form @submit.prevent="submit" class="space-y-6">
                            <div class="grid gap-2">
                                <Label for="name">Company Name *</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    type="text"
                                    placeholder="e.g., Acme Corporation"
                                    required
                                    autofocus
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="website">Website</Label>
                                <Input
                                    id="website"
                                    v-model="form.website"
                                    type="url"
                                    placeholder="https://example.com"
                                />
                                <InputError :message="form.errors.website" />
                                <p class="text-xs text-muted-foreground">
                                    The company's main website URL
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <Label for="industry">Industry</Label>
                                <Input
                                    id="industry"
                                    v-model="form.industry"
                                    type="text"
                                    placeholder="e.g., Technology, Finance, Healthcare"
                                />
                                <InputError :message="form.errors.industry" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">Description</Label>
                                <textarea
                                    id="description"
                                    v-model="form.description"
                                    class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                    placeholder="Brief description of the company..."
                                    rows="3"
                                />
                                <InputError :message="form.errors.description" />
                            </div>

                            <div class="flex items-center gap-4">
                                <Button type="submit" :disabled="form.processing">
                                    {{ form.processing ? 'Creating...' : 'Create Company' }}
                                </Button>
                                <Link href="/companies">
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </Link>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
