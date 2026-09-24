<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Clock, Globe, LoaderCircle } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

interface Props {
    businessName: string;
    slotMinutes: number;
    maxDaysAhead: number;
}

const props = defineProps<Props>();

const detectedTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const timezones = (() => {
    const zones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];
    return zones.includes(detectedTimezone) ? zones : [detectedTimezone, ...zones];
})();
const timezone = ref(detectedTimezone);

// Date helpers. A "local date" is YYYY-MM-DD in the selected timezone.
const localDate = (instant: Date, tz: string) =>
    new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(instant);

const offsetMs = (instant: Date, tz: string) => {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-US', {
            timeZone: tz,
            hourCycle: 'h23',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        })
            .formatToParts(instant)
            .map((part) => [part.type, part.value]),
    );
    const wallClock = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute, +parts.second);
    return wallClock - instant.getTime();
};

// The UTC instant of local midnight on the given date in tz (checked twice to settle DST edges).
const startOfLocalDay = (year: number, month: number, day: number, tz: string) => {
    const guess = Date.UTC(year, month - 1, day);
    const first = guess - offsetMs(new Date(guess), tz);
    return new Date(guess - offsetMs(new Date(first), tz));
};

const pad = (n: number) => String(n).padStart(2, '0');
const monthKey = (year: number, month: number) => `${year}-${pad(month)}`;

const today = computed(() => localDate(new Date(), timezone.value));
const lastBookable = computed(() => {
    const [y, m, d] = today.value.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d + props.maxDaysAhead));
    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
});

const initial = today.value.split('-').map(Number);
const view = ref({ year: initial[0], month: initial[1] });

const canGoBack = computed(() => monthKey(view.value.year, view.value.month) > today.value.slice(0, 7));
const canGoForward = computed(() => monthKey(view.value.year, view.value.month) < lastBookable.value.slice(0, 7));

const shiftMonth = (delta: number) => {
    const date = new Date(Date.UTC(view.value.year, view.value.month - 1 + delta, 1));
    view.value = { year: date.getUTCFullYear(), month: date.getUTCMonth() + 1 };
};

const monthLabel = computed(() =>
    new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(
        new Date(Date.UTC(view.value.year, view.value.month - 1, 1)),
    ),
);
const monthName = computed(() =>
    new Intl.DateTimeFormat('en-US', { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(view.value.year, view.value.month - 1, 1))),
);

// Slots for the visible month.
const slots = ref<string[]>([]);
const loading = ref(false);
const loadError = ref(false);
let requestId = 0;

const loadSlots = async () => {
    const id = ++requestId;
    const { year, month } = view.value;
    const start = startOfLocalDay(year, month, 1, timezone.value);
    const end = startOfLocalDay(year, month + 1, 1, timezone.value);

    loading.value = true;
    loadError.value = false;

    try {
        const query = new URLSearchParams({ start: start.toISOString(), end: end.toISOString() });
        const response = await fetch(`${route('booking.slots')}?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const body: { slots: string[] } = await response.json();
        if (id !== requestId) return;
        slots.value = body.slots;
    } catch {
        if (id !== requestId) return;
        slots.value = [];
        loadError.value = true;
    } finally {
        if (id === requestId) loading.value = false;
    }
};

const slotsByDate = computed(() => {
    const groups = new Map<string, string[]>();
    for (const slot of slots.value) {
        const date = localDate(new Date(slot), timezone.value);
        groups.set(date, [...(groups.get(date) ?? []), slot]);
    }
    return groups;
});

const selectedDate = ref<string | null>(null);
const pickedSlot = ref<string | null>(null);
const notice = ref('');

watch(slotsByDate, (groups) => {
    if (!selectedDate.value || !groups.has(selectedDate.value)) {
        selectedDate.value = groups.keys().next().value ?? null;
    }
    if (pickedSlot.value && !slots.value.includes(pickedSlot.value)) {
        pickedSlot.value = null;
    }
});

watch([view, timezone], loadSlots);
onMounted(loadSlots);

interface CalendarDay {
    date: string;
    day: number;
    count: number;
    isToday: boolean;
    label: string;
}

const calendar = computed(() => {
    const { year, month } = view.value;
    const leading = new Date(Date.UTC(year, month - 1, 1)).getUTCDay();
    const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const cells: (CalendarDay | null)[] = Array.from({ length: leading }, () => null);

    for (let day = 1; day <= daysInMonth; day++) {
        const date = `${year}-${pad(month)}-${pad(day)}`;
        const count = slotsByDate.value.get(date)?.length ?? 0;
        const long = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(
            new Date(Date.UTC(year, month - 1, day)),
        );
        cells.push({
            date,
            day,
            count,
            isToday: date === today.value,
            label: count ? `${long}, ${count} open ${count === 1 ? 'time' : 'times'}` : `${long}, no open times`,
        });
    }
    return cells;
});

const timeLabel = (slot: string) =>
    new Intl.DateTimeFormat('en-US', { timeZone: timezone.value, hour: 'numeric', minute: '2-digit' }).format(new Date(slot));

const selectedDayLabel = computed(() => {
    if (!selectedDate.value) return '';
    const [y, m, d] = selectedDate.value.split('-').map(Number);
    return new Intl.DateTimeFormat('en-US', { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' }).format(
        new Date(Date.UTC(y, m - 1, d)),
    );
});
const dayTimes = computed(() => (selectedDate.value ? (slotsByDate.value.get(selectedDate.value) ?? []) : []));

// Details step.
const step = ref<'times' | 'details'>('times');
const form = useForm({ start_at: '', name: '', email: '', notes: '', company_website: '' });

const pickedSummary = computed(() => {
    if (!pickedSlot.value) return { date: '', time: '' };
    const start = new Date(pickedSlot.value);
    const end = new Date(start.getTime() + props.slotMinutes * 60_000);
    return {
        date: new Intl.DateTimeFormat('en-US', { timeZone: timezone.value, weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(
            start,
        ),
        time: `${timeLabel(start.toISOString())} - ${timeLabel(end.toISOString())}`,
    };
});

const goToDetails = () => {
    if (!pickedSlot.value) return;
    notice.value = '';
    form.clearErrors();
    step.value = 'details';
    nextTick(() => document.getElementById('name')?.focus());
};

const backToTimes = () => {
    step.value = 'times';
};

const submit = () => {
    form.start_at = pickedSlot.value ?? '';
    form.post(route('booking.store'), {
        preserveScroll: true,
        onError: (errors) => {
            if (errors.start_at) {
                notice.value = errors.start_at;
                pickedSlot.value = null;
                step.value = 'times';
                loadSlots();
                return;
            }
            const firstInvalid = (['name', 'email', 'notes'] as const).find((field) => errors[field]);
            if (firstInvalid) nextTick(() => document.getElementById(firstInvalid)?.focus());
        },
    });
};
</script>

<template>
    <Head title="Book a time" />

    <div class="flex min-h-screen items-start justify-center bg-background px-4 py-8 text-foreground md:items-center">
        <Card v-if="step === 'times'" class="grid w-full max-w-5xl overflow-hidden md:grid-cols-[240px_minmax(0,1fr)_220px]">
            <section class="border-b border-border p-6 md:border-b-0 md:border-r">
                <div class="mb-5 flex items-center gap-3">
                    <div class="grid h-10 w-10 place-items-center rounded-md bg-primary font-semibold text-primary-foreground">
                        {{ businessName.slice(0, 1) }}
                    </div>
                    <p class="font-semibold">{{ businessName }}</p>
                </div>
                <h1 class="mb-4 text-xl font-semibold tracking-tight">{{ slotMinutes }}-minute meeting</h1>
                <div class="grid gap-3 text-sm text-muted-foreground">
                    <p class="flex items-center gap-2"><Clock class="h-4 w-4 shrink-0" />{{ slotMinutes }} min</p>
                    <p class="flex items-center gap-2"><Globe class="h-4 w-4 shrink-0" />{{ timezone }}</p>
                </div>
            </section>

            <section class="border-b border-border p-6 md:border-b-0 md:border-r">
                <p
                    v-if="notice"
                    role="alert"
                    class="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {{ notice }}
                </p>

                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-semibold" aria-live="polite">{{ monthLabel }}</h2>
                    <div class="flex gap-1">
                        <Button
                            variant="outline"
                            size="icon"
                            class="h-8 w-8"
                            :disabled="!canGoBack"
                            aria-label="Previous month"
                            @click="shiftMonth(-1)"
                        >
                            <ChevronLeft class="h-4 w-4" />
                        </Button>
                        <Button
                            variant="outline"
                            size="icon"
                            class="h-8 w-8"
                            :disabled="!canGoForward"
                            aria-label="Next month"
                            @click="shiftMonth(1)"
                        >
                            <ChevronRight class="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-1" :aria-busy="loading">
                    <div
                        v-for="weekday in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']"
                        :key="weekday"
                        class="pb-2 text-center text-xs font-medium uppercase tracking-wide text-muted-foreground"
                    >
                        {{ weekday }}
                    </div>
                    <template v-for="(cell, index) in calendar" :key="cell?.date ?? `blank-${index}`">
                        <div v-if="!cell" />
                        <button
                            v-else
                            type="button"
                            :disabled="loading || cell.count === 0"
                            :aria-label="cell.label"
                            :aria-pressed="cell.date === selectedDate"
                            class="relative grid aspect-square place-items-center rounded-md text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="[
                                cell.date === selectedDate
                                    ? 'bg-primary font-semibold text-primary-foreground'
                                    : cell.count
                                      ? 'bg-accent font-semibold text-accent-foreground hover:ring-1 hover:ring-primary'
                                      : 'text-muted-foreground/60',
                                loading && 'animate-pulse',
                            ]"
                            @click="
                                selectedDate = cell.date;
                                pickedSlot = null;
                            "
                        >
                            {{ cell.day }}
                            <span v-if="cell.isToday" class="absolute bottom-1.5 h-1 w-1 rounded-full bg-current" aria-hidden="true" />
                        </button>
                    </template>
                </div>

                <div class="mt-5 grid max-w-xs gap-2">
                    <Label for="timezone">Times shown in</Label>
                    <select
                        id="timezone"
                        v-model="timezone"
                        class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option v-for="zone in timezones" :key="zone" :value="zone">
                            {{ zone }}{{ zone === detectedTimezone ? ' (detected)' : '' }}
                        </option>
                    </select>
                </div>
            </section>

            <section class="p-6">
                <div v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground" role="status">
                    <LoaderCircle class="h-4 w-4 animate-spin" /> Loading times...
                </div>

                <div v-else-if="loadError" class="grid gap-3" role="alert">
                    <p class="text-sm">Couldn't load available times.</p>
                    <Button variant="outline" size="sm" class="justify-self-start" @click="loadSlots">Retry</Button>
                </div>

                <div v-else-if="slots.length === 0" class="grid gap-3">
                    <p class="text-sm text-muted-foreground">No open times in {{ monthName }}.</p>
                    <Button v-if="canGoForward" variant="outline" size="sm" class="justify-self-start" @click="shiftMonth(1)">Next month</Button>
                </div>

                <template v-else-if="selectedDate">
                    <h3 class="mb-4 text-sm font-semibold">
                        {{ selectedDayLabel }} <span class="font-normal text-muted-foreground">- {{ dayTimes.length }} open</span>
                    </h3>
                    <ul class="grid max-h-[420px] gap-2 overflow-y-auto pr-0.5">
                        <li v-for="slot in dayTimes" :key="slot">
                            <div v-if="slot === pickedSlot" class="grid grid-cols-2 gap-2">
                                <span class="grid h-10 place-items-center rounded-md bg-muted text-sm font-semibold">{{ timeLabel(slot) }}</span>
                                <Button class="h-10" @click="goToDetails">Next</Button>
                            </div>
                            <button
                                v-else
                                type="button"
                                class="h-10 w-full rounded-md border border-primary/40 bg-card text-sm font-semibold text-primary transition-colors hover:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                @click="pickedSlot = slot"
                            >
                                {{ timeLabel(slot) }}
                            </button>
                        </li>
                    </ul>
                </template>
            </section>
        </Card>

        <Card v-else class="w-full max-w-xl p-6 sm:p-8">
            <button
                type="button"
                class="mb-5 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                @click="backToTimes"
            >
                <ChevronLeft class="h-4 w-4" /> Back to times
            </button>
            <h1 class="text-xl font-semibold tracking-tight">Enter your details</h1>

            <div class="my-5 flex items-start justify-between gap-4 rounded-md border border-border bg-muted p-4">
                <div>
                    <p class="font-semibold">{{ pickedSummary.date }}</p>
                    <p class="text-sm text-muted-foreground">{{ pickedSummary.time }} · {{ timezone }} · {{ slotMinutes }}-minute meeting</p>
                </div>
                <button type="button" class="whitespace-nowrap text-sm text-primary hover:underline" @click="backToTimes">Change</button>
            </div>

            <form class="grid gap-4" novalidate @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="name">Full name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            autocomplete="name"
                            required
                            maxlength="255"
                            :aria-invalid="Boolean(form.errors.name)"
                            aria-describedby="name-error"
                        />
                        <InputError id="name-error" :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="email">Email</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                            required
                            maxlength="255"
                            :class="form.errors.email && 'border-destructive'"
                            :aria-invalid="Boolean(form.errors.email)"
                            aria-describedby="email-error"
                        />
                        <InputError id="email-error" :message="form.errors.email" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="notes">Anything we should know? <span class="font-normal text-muted-foreground">(optional)</span></Label>
                    <textarea
                        id="notes"
                        v-model="form.notes"
                        rows="4"
                        maxlength="1000"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :aria-invalid="Boolean(form.errors.notes)"
                        aria-describedby="notes-hint notes-error"
                    />
                    <p id="notes-hint" class="text-xs text-muted-foreground">Max 1,000 characters.</p>
                    <InputError id="notes-error" :message="form.errors.notes" />
                </div>

                <div class="absolute -left-[9999px]" aria-hidden="true">
                    <label for="company_website">Company website</label>
                    <input id="company_website" v-model="form.company_website" name="company_website" tabindex="-1" autocomplete="off" />
                </div>

                <InputError :message="form.errors.company_website" />

                <Button class="h-10 w-full" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Confirm booking
                </Button>
                <p class="text-xs text-muted-foreground">Your details are only used for this appointment.</p>
            </form>
        </Card>
    </div>
</template>
