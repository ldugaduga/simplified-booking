<script setup lang="ts">
import { TransitionRoot } from '@headlessui/vue';
import { Head, useForm } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Props {
    businessName: string | null;
    businessDescription: string | null;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Branding settings',
        href: '/settings/branding',
    },
];

const form = useForm({
    business_name: props.businessName ?? '',
    business_description: props.businessDescription ?? '',
});

const submit = () => {
    form.patch(route('branding.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Branding settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Business branding"
                    description="Shown to visitors on the public booking page, the confirmation page, the calendar invite, and confirmation emails"
                />

                <form @submit.prevent="submit" class="space-y-6">
                    <div class="grid gap-2">
                        <Label for="business_name">Business name</Label>
                        <Input
                            id="business_name"
                            class="mt-1 block w-full"
                            v-model="form.business_name"
                            maxlength="255"
                            autocomplete="organization"
                            placeholder="Simplified Booking"
                        />
                        <InputError class="mt-2" :message="form.errors.business_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="business_description">Description</Label>
                        <textarea
                            id="business_description"
                            v-model="form.business_description"
                            maxlength="500"
                            rows="3"
                            placeholder="A short line about what you offer"
                            class="mt-1 flex w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                        />
                        <InputError class="mt-2" :message="form.errors.business_description" />
                    </div>

                    <div class="flex items-center gap-4">
                        <Button :disabled="form.processing">Save</Button>

                        <TransitionRoot
                            :show="form.recentlySuccessful"
                            enter="transition ease-in-out"
                            enter-from="opacity-0"
                            leave="transition ease-in-out"
                            leave-to="opacity-0"
                        >
                            <p class="text-sm text-neutral-600">Saved.</p>
                        </TransitionRoot>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
