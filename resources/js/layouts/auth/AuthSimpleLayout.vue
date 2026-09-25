<script setup lang="ts">
import BrandMark from '@/components/BrandMark.vue';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage<SharedData>();
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link :href="route('home')" class="flex flex-col items-center gap-2 font-medium">
                        <BrandMark :name="page.props.businessName" class="size-10 text-base" />
                        <span class="text-sm font-semibold">{{ page.props.businessName }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">{{ description }}</p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
