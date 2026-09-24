<script setup lang="ts">
import AppointmentSlotPicker from '@/components/AppointmentSlotPicker.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AppointmentRow, BreadcrumbItem, Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type Tab = 'upcoming' | 'past' | 'all';

interface Filters {
    tab: Tab;
    status: 'confirmed' | 'cancelled' | null;
    date: string | null;
    q: string | null;
}

interface Props {
    appointments: Paginated<AppointmentRow>;
    filters: Filters;
    counts: { today: number; thisWeek: number; cancelledLast30Days: number };
    timezone: string;
    slotMinutes: number;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Appointments', href: '/admin' }];
const tabs: { value: Tab; label: string }[] = [
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'past', label: 'Past' },
    { value: 'all', label: 'All' },
];
const controlClass =
    'h-9 rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

// Formatting in the admin's timezone.
const localDate = (instant: string | Date) =>
    new Intl.DateTimeFormat('en-CA', { timeZone: props.timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(instant));
const timeLabel = (instant: string) =>
    new Intl.DateTimeFormat('en-US', { timeZone: props.timezone, hour: 'numeric', minute: '2-digit' }).format(new Date(instant));
const dayLabel = (instant: string) =>
    new Intl.DateTimeFormat('en-US', { timeZone: props.timezone, weekday: 'long', month: 'short', day: 'numeric' }).format(new Date(instant));
const longLabel = (instant: string) =>
    new Intl.DateTimeFormat('en-US', { timeZone: props.timezone, weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }).format(
        new Date(instant),
    );
const minutesBetween = (row: AppointmentRow) => Math.round((new Date(row.end_at).getTime() - new Date(row.start_at).getTime()) / 60_000);
const today = localDate(new Date());

const groups = computed(() => {
    const result: { key: string; label: string; rows: AppointmentRow[] }[] = [];
    for (const row of props.appointments.data) {
        const key = localDate(row.start_at);
        let group = result.find((g) => g.key === key);
        if (!group) {
            group = { key, label: key === today ? `Today · ${dayLabel(row.start_at)}` : dayLabel(row.start_at), rows: [] };
            result.push(group);
        }
        group.rows.push(row);
    }
    return result;
});

// Filters drive the query string.
const tab = ref<Tab>(props.filters.tab);
const status = ref(props.filters.status ?? '');
const date = ref(props.filters.date ?? '');
const search = ref(props.filters.q ?? '');

const applyFilters = () => {
    const query: Record<string, string> = {};
    if (tab.value !== 'upcoming') query.tab = tab.value;
    if (status.value) query.status = status.value;
    if (date.value) query.date = date.value;
    if (search.value.trim()) query.q = search.value.trim();
    router.get(route('dashboard'), query, { preserveState: true, preserveScroll: true, replace: true });
};

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
});
watch([tab, status, date], applyFilters);

const hasFilters = computed(() => Boolean(props.filters.status || props.filters.date || props.filters.q));
const clearFilters = () => {
    status.value = '';
    date.value = '';
    search.value = '';
};
const emptyMessage = computed(() => {
    if (hasFilters.value) return 'No appointments match these filters.';
    return { upcoming: 'No upcoming appointments yet.', past: 'No past appointments.', all: 'No appointments yet.' }[props.filters.tab];
});

const goToPage = (url: string | null) => url && router.get(url, {}, { preserveState: true, preserveScroll: true });

// New appointment.
const createOpen = ref(false);
const createForm = useForm({ start_at: '', name: '', email: '', notes: '' });
const openCreate = () => {
    createForm.reset();
    createForm.clearErrors();
    createOpen.value = true;
};
const submitCreate = () => {
    createForm.post(route('admin.appointments.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createOpen.value = false;
            createForm.reset();
        },
    });
};

// Reschedule.
const rescheduling = ref<AppointmentRow | null>(null);
const rescheduleForm = useForm({ start_at: '' });
const openReschedule = (row: AppointmentRow) => {
    rescheduleForm.reset();
    rescheduleForm.clearErrors();
    rescheduling.value = row;
};
const submitReschedule = () => {
    if (!rescheduling.value) return;
    rescheduleForm.patch(route('admin.appointments.reschedule', rescheduling.value.id), {
        preserveScroll: true,
        onSuccess: () => (rescheduling.value = null),
    });
};

// Cancel.
const cancelling = ref<AppointmentRow | null>(null);
const cancelProcessing = ref(false);
const confirmCancel = () => {
    if (!cancelling.value) return;
    cancelProcessing.value = true;
    router.patch(
        route('admin.appointments.cancel', cancelling.value.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => (cancelling.value = null),
            onFinish: () => (cancelProcessing.value = false),
        },
    );
};
</script>

<template>
    <Head title="Appointments" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid grid-cols-[minmax(0,1fr)] gap-6 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-semibold tracking-tight">Appointments</h1>
                <Button size="sm" @click="openCreate"><Plus class="h-4 w-4" /> New appointment</Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <Card class="p-4">
                    <p class="text-sm text-muted-foreground">Today</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ counts.today }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-sm text-muted-foreground">This week</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ counts.thisWeek }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-sm text-muted-foreground">Cancelled (30 days)</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ counts.cancelledLast30Days }}</p>
                </Card>
            </div>

            <Card class="overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-border p-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex gap-0.5 self-start rounded-md bg-muted p-0.5" role="tablist" aria-label="Appointments">
                        <button
                            v-for="item in tabs"
                            :key="item.value"
                            type="button"
                            role="tab"
                            :aria-selected="tab === item.value"
                            class="rounded px-3 py-1.5 text-sm font-medium transition-colors"
                            :class="tab === item.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                            @click="tab = item.value"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Label for="search" class="sr-only">Search name or email</Label>
                        <Input
                            id="search"
                            v-model="search"
                            type="search"
                            placeholder="Search name or email"
                            maxlength="100"
                            class="h-9 w-full sm:w-52"
                        />
                        <Label for="status" class="sr-only">Status</Label>
                        <select id="status" v-model="status" :class="controlClass">
                            <option value="">Any status</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <Label for="date-filter" class="sr-only">Date</Label>
                        <Input id="date-filter" v-model="date" type="date" class="h-9 w-auto" />
                        <span class="text-xs text-muted-foreground">Times in {{ timezone }}</span>
                    </div>
                </div>

                <div v-if="appointments.data.length === 0" class="grid justify-items-center gap-3 px-4 py-12 text-center">
                    <p class="text-sm text-muted-foreground">{{ emptyMessage }}</p>
                    <Button v-if="hasFilters" variant="outline" size="sm" @click="clearFilters">Clear filters</Button>
                </div>

                <div v-else class="relative overflow-x-auto">
                    <table class="w-full min-w-[640px] border-collapse text-sm">
                        <thead>
                            <tr class="bg-muted text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <th class="px-4 py-2 font-medium">When</th>
                                <th class="px-4 py-2 font-medium">Who</th>
                                <th class="hidden px-4 py-2 font-medium md:table-cell">Notes</th>
                                <th class="px-4 py-2 font-medium">Status</th>
                                <th class="px-4 py-2"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody v-for="group in groups" :key="group.key">
                            <tr>
                                <th
                                    colspan="5"
                                    scope="colgroup"
                                    class="bg-background px-4 py-2 text-left text-xs font-semibold text-muted-foreground"
                                >
                                    {{ group.label }}
                                </th>
                            </tr>
                            <tr
                                v-for="row in group.rows"
                                :key="row.id"
                                class="border-t border-border hover:bg-muted/50"
                                :class="row.status === 'cancelled' && 'text-muted-foreground'"
                            >
                                <td class="px-4 py-3 align-middle">
                                    <p class="font-semibold tabular-nums" :class="row.status === 'cancelled' && 'line-through'">
                                        {{ timeLabel(row.start_at) }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ minutesBetween(row) }} min<template v-if="row.created_by_admin"> · by admin</template>
                                    </p>
                                </td>
                                <td class="px-4 py-3 align-middle">
                                    <p class="font-medium" :class="row.status === 'cancelled' && 'line-through'">{{ row.name }}</p>
                                    <p class="break-all text-xs text-muted-foreground">{{ row.email }}</p>
                                </td>
                                <td
                                    class="hidden max-w-[260px] truncate px-4 py-3 align-middle text-muted-foreground md:table-cell"
                                    :title="row.notes ?? ''"
                                >
                                    {{ row.notes || '-' }}
                                </td>
                                <td class="px-4 py-3 align-middle">
                                    <span
                                        class="inline-flex h-6 items-center rounded-full px-2 text-xs font-medium"
                                        :class="row.status === 'confirmed' ? 'bg-accent text-accent-foreground' : 'bg-muted text-muted-foreground'"
                                    >
                                        {{ row.status === 'confirmed' ? 'Confirmed' : 'Cancelled' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right align-middle">
                                    <div v-if="row.status === 'confirmed'" class="flex justify-end gap-1">
                                        <Button variant="ghost" size="sm" :aria-label="`Reschedule ${row.name}`" @click="openReschedule(row)"
                                            >Reschedule</Button
                                        >
                                        <Button variant="ghost" size="sm" :aria-label="`Cancel ${row.name}`" @click="cancelling = row">Cancel</Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="appointments.total > 0"
                    class="flex items-center justify-between gap-3 border-t border-border px-4 py-3 text-sm text-muted-foreground"
                >
                    <span>Showing {{ appointments.from }}-{{ appointments.to }} of {{ appointments.total }}</span>
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" :disabled="!appointments.prev_page_url" @click="goToPage(appointments.prev_page_url)">
                            Previous
                        </Button>
                        <Button variant="outline" size="sm" :disabled="!appointments.next_page_url" @click="goToPage(appointments.next_page_url)">
                            Next
                        </Button>
                    </div>
                </div>
            </Card>
        </div>

        <Dialog v-model:open="createOpen">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>New appointment</DialogTitle>
                    <DialogDescription>Book a {{ slotMinutes }}-minute meeting for someone.</DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" novalidate @submit.prevent="submitCreate">
                    <AppointmentSlotPicker
                        v-model="createForm.start_at"
                        id-prefix="create"
                        :timezone="timezone"
                        :initial-date="today"
                        :error="createForm.errors.start_at"
                    />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="create-name">Full name</Label>
                            <Input id="create-name" v-model="createForm.name" maxlength="255" :aria-invalid="Boolean(createForm.errors.name)" />
                            <InputError :message="createForm.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="create-email">Email</Label>
                            <Input
                                id="create-email"
                                v-model="createForm.email"
                                type="email"
                                maxlength="255"
                                :aria-invalid="Boolean(createForm.errors.email)"
                            />
                            <InputError :message="createForm.errors.email" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="create-notes">Notes <span class="font-normal text-muted-foreground">(optional)</span></Label>
                        <textarea
                            id="create-notes"
                            v-model="createForm.notes"
                            rows="3"
                            maxlength="1000"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <InputError :message="createForm.errors.notes" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="createOpen = false">Cancel</Button>
                        <Button :disabled="createForm.processing || !createForm.start_at">Book appointment</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="rescheduling !== null" @update:open="(open: boolean) => !open && (rescheduling = null)">
            <DialogContent v-if="rescheduling" class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Reschedule appointment</DialogTitle>
                    <DialogDescription>
                        {{ rescheduling.name }} · currently {{ longLabel(rescheduling.start_at) }} at {{ timeLabel(rescheduling.start_at) }}
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="submitReschedule">
                    <AppointmentSlotPicker
                        v-model="rescheduleForm.start_at"
                        id-prefix="reschedule"
                        :timezone="timezone"
                        :initial-date="localDate(rescheduling.start_at)"
                        :except="rescheduling.id"
                        :current-start="rescheduling.start_at"
                        :error="rescheduleForm.errors.start_at"
                    />
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="rescheduling = null">Keep current time</Button>
                        <Button :disabled="rescheduleForm.processing || !rescheduleForm.start_at">Reschedule</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="cancelling !== null" @update:open="(open: boolean) => !open && (cancelling = null)">
            <DialogContent v-if="cancelling" class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Cancel appointment?</DialogTitle>
                    <DialogDescription>
                        Cancel {{ cancelling.name }}'s appointment on {{ longLabel(cancelling.start_at) }} at {{ timeLabel(cancelling.start_at) }}?
                        The time becomes available to book again.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="cancelling = null">Keep appointment</Button>
                    <Button type="button" variant="destructive" :disabled="cancelProcessing" @click="confirmCancel">Cancel appointment</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
