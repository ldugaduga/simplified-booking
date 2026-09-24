<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    name: string;
    email: string;
    start_at: string;
    end_at: string;
    slotMinutes: number;
    businessName: string;
}

const props = defineProps<Props>();

const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const when = computed(() => {
    const start = new Date(props.start_at);
    const end = new Date(props.end_at);
    const date = new Intl.DateTimeFormat('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }).format(start);
    const time = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit' });
    return `${date} · ${time.format(start)} - ${time.format(end)}`;
});
</script>

<template>
    <Head title="You're booked" />

    <div class="flex min-h-screen items-center justify-center bg-background px-4 py-8 text-foreground">
        <Card class="w-full max-w-xl p-6 text-center sm:p-8">
            <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-full bg-accent text-accent-foreground">
                <Check class="h-7 w-7" stroke-width="2.5" />
            </div>
            <h1 class="mb-2 text-2xl font-semibold tracking-tight">You're booked</h1>
            <p class="text-muted-foreground">
                Thanks, <span class="font-medium text-foreground">{{ name }}</span
                >. Your time is reserved.
            </p>

            <dl class="my-6 divide-y divide-border rounded-md border border-border text-left">
                <div class="grid gap-1 px-4 py-3 sm:grid-cols-[120px_1fr] sm:gap-3">
                    <dt class="text-sm text-muted-foreground">What</dt>
                    <dd class="font-medium">{{ slotMinutes }}-minute meeting with {{ businessName }}</dd>
                </div>
                <div class="grid gap-1 px-4 py-3 sm:grid-cols-[120px_1fr] sm:gap-3">
                    <dt class="text-sm text-muted-foreground">When</dt>
                    <dd class="font-medium">{{ when }}</dd>
                </div>
                <div class="grid gap-1 px-4 py-3 sm:grid-cols-[120px_1fr] sm:gap-3">
                    <dt class="text-sm text-muted-foreground">Timezone</dt>
                    <dd class="font-medium">{{ timezone }}</dd>
                </div>
                <div class="grid gap-1 px-4 py-3 sm:grid-cols-[120px_1fr] sm:gap-3">
                    <dt class="text-sm text-muted-foreground">Email</dt>
                    <dd class="break-all font-medium">{{ email }}</dd>
                </div>
            </dl>

            <Button variant="outline" as-child>
                <Link :href="route('home')">Book another time</Link>
            </Button>
        </Card>
    </div>
</template>
