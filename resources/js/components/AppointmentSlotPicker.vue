<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoaderCircle } from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';

interface Props {
    modelValue: string;
    timezone: string;
    initialDate: string;
    idPrefix: string;
    except?: number;
    currentStart?: string;
    error?: string;
}

const props = defineProps<Props>();
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const date = ref(props.initialDate);
const slots = ref<string[]>([]);
const loading = ref(false);
const loadError = ref(false);
let requestId = 0;

const timeLabel = (slot: string) =>
    new Intl.DateTimeFormat('en-US', { timeZone: props.timezone, hour: 'numeric', minute: '2-digit' }).format(new Date(slot));

const load = async () => {
    const id = ++requestId;
    loading.value = true;
    loadError.value = false;
    emit('update:modelValue', '');

    try {
        const query = new URLSearchParams({ date: date.value });
        if (props.except) query.set('except', String(props.except));
        const response = await fetch(`${route('admin.slots')}?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const body: { slots: string[] } = await response.json();
        if (id !== requestId) return;
        slots.value = body.slots;
        if (props.currentStart && body.slots.includes(props.currentStart)) emit('update:modelValue', props.currentStart);
    } catch {
        if (id !== requestId) return;
        slots.value = [];
        loadError.value = true;
    } finally {
        if (id === requestId) loading.value = false;
    }
};

watch(date, (value) => value && load());
onMounted(load);
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-date`">Date</Label>
            <Input :id="`${idPrefix}-date`" v-model="date" type="date" class="h-9" required />
        </div>
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-time`">Time</Label>
            <div v-if="loading" class="flex h-9 items-center gap-2 text-sm text-muted-foreground" role="status">
                <LoaderCircle class="h-4 w-4 animate-spin" /> Loading times...
            </div>
            <div v-else-if="loadError" class="flex h-9 items-center gap-2 text-sm" role="alert">
                Couldn't load times.
                <Button type="button" variant="outline" size="sm" class="h-7" @click="load">Retry</Button>
            </div>
            <p v-else-if="slots.length === 0" class="flex h-9 items-center text-sm text-muted-foreground">No open times on this day.</p>
            <select
                v-else
                :id="`${idPrefix}-time`"
                :value="modelValue"
                class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-invalid="Boolean(error)"
                :aria-describedby="`${idPrefix}-time-error`"
                @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
            >
                <option value="" disabled>Pick a time</option>
                <option v-for="slot in slots" :key="slot" :value="slot">{{ timeLabel(slot) }}{{ slot === currentStart ? ' (current)' : '' }}</option>
            </select>
        </div>
        <p class="text-xs text-muted-foreground sm:col-span-2">Only open times are listed, in {{ timezone }}. Minimum notice doesn't apply to you.</p>
        <InputError :id="`${idPrefix}-time-error`" class="sm:col-span-2" :message="error" />
    </div>
</template>
