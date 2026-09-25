<script setup lang="ts">
import { getInitials } from '@/composables/useInitials';
import type { HTMLAttributes } from 'vue';

interface Props {
    name: string;
    class?: HTMLAttributes['class'];
}

const props = defineProps<Props>();
</script>

<template>
    <div :class="['flex aspect-square items-center justify-center rounded-full bg-primary font-semibold text-primary-foreground', props.class]">
        {{ getInitials(name) }}
    </div>
</template>
