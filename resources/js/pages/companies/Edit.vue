<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
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
    description: string | null;
    industry: string | null;
    headquarters_country: string | null;
    headquarters_state: string | null;
    is_active: boolean;
}

interface Props {
    company: Company;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Companies', href: '/companies' },
    { title: props.company.name, href: `/companies/${props.company.id}` },
    { title: 'Edit', href: `/companies/${props.company.id}/edit` },
];

const form = useForm({
    name: props.company.name,
    website: props.company.website || '',
    description: props.company.description || '',
    industry: props.company.industry || '',
    headquarters_country: props.company.headquarters_country || '',
    headquarters_state: props.company.headquarters_state || '',
    is_active: props.company.is_active,
});

function submit() {
    form.put(`/companies/${props.company.id}`);
}
</script>

<template>
    <Head :title="`Edit ${company.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="mx-auto w-full max-w-2xl">
                <Card>
                    <CardHeader>
                        <CardTitle>Edit Company</CardTitle>
                        <CardDescription>
                            Update company information.
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

                            <div class="grid grid-cols-2 gap-4">
                                <div class="grid gap-2">
                                    <Label for="headquarters_country">Country</Label>
                                    <Input
                                        id="headquarters_country"
                                        v-model="form.headquarters_country"
                                        type="text"
                                        placeholder="e.g., United States"
                                    />
                                    <InputError :message="form.errors.headquarters_country" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="headquarters_state">State/Province</Label>
                                    <Input
                                        id="headquarters_state"
                                        v-model="form.headquarters_state"
                                        type="text"
                                        placeholder="e.g., California"
                                    />
                                    <InputError :message="form.errors.headquarters_state" />
                                </div>
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

                            <div class="flex items-center space-x-2">
                                <Checkbox
                                    id="is_active"
                                    :checked="form.is_active"
                                    @update:checked="form.is_active = $event"
                                />
                                <Label for="is_active" class="font-normal">
                                    Active (uncheck to disable monitoring)
                                </Label>
                            </div>

                            <div class="flex items-center gap-4">
                                <Button type="submit" :disabled="form.processing">
                                    {{ form.processing ? 'Saving...' : 'Save Changes' }}
                                </Button>
                                <Link :href="`/companies/${company.id}`">
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
