<script setup lang="ts">
import BrandMark from '@/components/BrandMark.vue';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

interface Props {
    class?: string;
}

defineProps<Props>();

const page = usePage<SharedData>();
</script>

<template>
    <BrandMark :name="page.props.businessName" class="size-8 text-sm" />
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate font-semibold leading-none">{{ page.props.businessName }}</span>
    </div>
</template>
