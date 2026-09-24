<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AvailabilityRule, BlockedDate, BookingSettings, BreadcrumbItem } from '@/types';
import { TransitionRoot } from '@headlessui/vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    rules: AvailabilityRule[];
    settings: BookingSettings;
    blockedDates: BlockedDate[];
    timezones: string[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Availability', href: '/admin/availability' }];

const weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const slotLengths = [15, 30, 45, 60];
const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-50';

const toMinutes = (time: string) => Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));
const toTime = (minutes: number) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
const label12h = (time: string) => {
    const minutes = toMinutes(time);
    const hour = Math.floor(minutes / 60);
    return `${hour % 12 || 12}:${String(minutes % 60).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`;
};
const timeOptions = Array.from({ length: 96 }, (_, i) => toTime(i * 15));
const LAST_TIME = toMinutes('23:45');

const copyRules = (rules: AvailabilityRule[]) => rules.map((rule) => ({ ...rule }));

// The form's own defaults share nested objects with its data, so keep a separate saved copy for Discard and dirty tracking.
const savedRules = ref(copyRules(props.rules));
const hoursForm = useForm({ rules: copyRules(props.rules) });
const hoursDirty = computed(() => JSON.stringify(hoursForm.rules) !== JSON.stringify(savedRules.value));

const discardHours = () => {
    hoursForm.rules = copyRules(savedRules.value);
    hoursForm.clearErrors();
};

const rangesFor = (weekday: number) =>
    hoursForm.rules
        .map((rule, index) => ({ rule, index }))
        .filter(({ rule }) => rule.weekday === weekday)
        .sort((a, b) => a.rule.start_time.localeCompare(b.rule.start_time));

const toggleDay = (weekday: number, on: boolean) => {
    if (on) {
        hoursForm.rules.push({ weekday, start_time: '09:00', end_time: '17:00' });
    } else {
        hoursForm.rules = hoursForm.rules.filter((rule) => rule.weekday !== weekday);
    }
};

const lastEnd = (weekday: number) => Math.max(...rangesFor(weekday).map(({ rule }) => toMinutes(rule.end_time)));
const canAddRange = (weekday: number) => lastEnd(weekday) < LAST_TIME;

const addRange = (weekday: number) => {
    const start = lastEnd(weekday);
    hoursForm.rules.push({ weekday, start_time: toTime(start), end_time: toTime(Math.min(start + 60, LAST_TIME)) });
};

const removeRange = (index: number) => {
    hoursForm.rules.splice(index, 1);
};

const rowError = (weekday: number) =>
    rangesFor(weekday)
        .flatMap(({ index }) => [
            hoursForm.errors[`rules.${index}.start_time` as keyof typeof hoursForm.errors],
            hoursForm.errors[`rules.${index}.end_time` as keyof typeof hoursForm.errors],
            hoursForm.errors[`rules.${index}.weekday` as keyof typeof hoursForm.errors],
        ])
        .find(Boolean);

const hasError = (index: number, field: 'start_time' | 'end_time') =>
    Boolean(hoursForm.errors[`rules.${index}.${field}` as keyof typeof hoursForm.errors]);

const saveHours = () => {
    hoursForm.put(route('admin.availability.hours.update'), {
        preserveScroll: true,
        onSuccess: () => (savedRules.value = copyRules(hoursForm.rules)),
    });
};

const rulesForm = useForm({ ...props.settings });

const saveRules = () => {
    rulesForm.put(route('admin.availability.rules.update'), {
        preserveScroll: true,
        onSuccess: () => rulesForm.defaults(),
    });
};

const blockForm = useForm({ date: '', reason: '' });

const addBlockedDate = () => {
    blockForm.post(route('admin.blocked-dates.store'), {
        preserveScroll: true,
        onSuccess: () => blockForm.reset(),
    });
};

const removingId = ref<number | null>(null);

const removeBlockedDate = (blocked: BlockedDate) => {
    removingId.value = blocked.id;
    router.delete(route('admin.blocked-dates.destroy', blocked.id), {
        preserveScroll: true,
        onFinish: () => (removingId.value = null),
    });
};

const formatDate = (date: string) =>
    new Date(`${date}T00:00:00Z`).toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    });
</script>

<template>
    <Head title="Availability" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h1 class="text-xl font-semibold tracking-tight">Availability</h1>
                <p class="text-sm text-muted-foreground">Hours are in {{ settings.timezone }}</p>
            </div>

            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Weekly hours</CardTitle>
                        <CardDescription>When people can book you each week.</CardDescription>
                    </CardHeader>

                    <form @submit.prevent="saveHours">
                        <p
                            v-if="hoursForm.rules.length === 0"
                            class="mx-6 mb-4 rounded-md border border-border bg-muted px-3 py-2 text-sm text-muted-foreground"
                        >
                            No weekly hours set. Visitors won't see any open times.
                        </p>

                        <ul class="border-t border-border">
                            <li
                                v-for="(dayName, weekday) in weekdays"
                                :key="dayName"
                                class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 border-b border-border px-6 py-3 last:border-b-0 sm:grid-cols-[140px_1fr_auto]"
                            >
                                <div class="flex h-9 items-center gap-3">
                                    <Checkbox
                                        :id="`day-${weekday}`"
                                        :checked="rangesFor(weekday).length > 0"
                                        @update:checked="(on: boolean) => toggleDay(weekday, on)"
                                    />
                                    <Label :for="`day-${weekday}`" class="font-medium">{{ dayName }}</Label>
                                </div>

                                <div class="col-span-2 row-start-2 grid gap-2 sm:col-span-1 sm:row-start-auto">
                                    <p v-if="rangesFor(weekday).length === 0" class="flex h-9 items-center text-sm text-muted-foreground">
                                        Unavailable
                                    </p>
                                    <div v-for="({ rule, index }, position) in rangesFor(weekday)" :key="index" class="flex items-center gap-2">
                                        <select
                                            v-model="rule.start_time"
                                            :class="[selectClass, hasError(index, 'start_time') && 'border-destructive']"
                                            :aria-label="`${dayName} range ${position + 1} start time`"
                                            :aria-invalid="hasError(index, 'start_time')"
                                        >
                                            <option v-for="time in timeOptions" :key="time" :value="time">{{ label12h(time) }}</option>
                                        </select>
                                        <span class="text-muted-foreground">-</span>
                                        <select
                                            v-model="rule.end_time"
                                            :class="[selectClass, hasError(index, 'end_time') && 'border-destructive']"
                                            :aria-label="`${dayName} range ${position + 1} end time`"
                                            :aria-invalid="hasError(index, 'end_time')"
                                        >
                                            <option v-for="time in timeOptions" :key="time" :value="time">{{ label12h(time) }}</option>
                                        </select>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="h-9 w-9 shrink-0"
                                            :aria-label="`Remove ${dayName} range ${position + 1}`"
                                            @click="removeRange(index)"
                                        >
                                            <X class="h-4 w-4" />
                                        </Button>
                                    </div>
                                    <InputError :message="rowError(weekday)" />
                                </div>

                                <Button
                                    v-if="rangesFor(weekday).length > 0"
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="col-start-2 row-start-1 h-9 w-9 sm:col-start-3"
                                    :disabled="!canAddRange(weekday)"
                                    :aria-label="`Add a ${dayName} range`"
                                    @click="addRange(weekday)"
                                >
                                    <Plus class="h-4 w-4" />
                                </Button>
                            </li>
                        </ul>

                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-b-xl border-t border-border bg-muted px-6 py-3">
                            <p v-if="hoursDirty" class="flex items-center gap-2 text-sm font-medium text-foreground">
                                <span class="h-1.5 w-1.5 rounded-full bg-primary" aria-hidden="true" />Unsaved changes
                            </p>
                            <TransitionRoot
                                v-else
                                :show="hoursForm.recentlySuccessful"
                                enter="transition ease-in-out"
                                enter-from="opacity-0"
                                leave="transition ease-in-out"
                                leave-to="opacity-0"
                            >
                                <p class="text-sm text-muted-foreground">Saved.</p>
                            </TransitionRoot>
                            <div class="ml-auto flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="!hoursDirty || hoursForm.processing"
                                    @click="discardHours"
                                >
                                    Discard
                                </Button>
                                <Button size="sm" :disabled="hoursForm.processing">Save hours</Button>
                            </div>
                        </div>
                    </form>
                </Card>

                <div class="grid gap-6">
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">Booking rules</CardTitle>
                            <CardDescription>Apply to every booking.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form class="grid gap-4" @submit.prevent="saveRules">
                                <div class="grid gap-2">
                                    <Label for="slot_minutes">Meeting length</Label>
                                    <select id="slot_minutes" v-model.number="rulesForm.slot_minutes" :class="selectClass">
                                        <option v-for="minutes in slotLengths" :key="minutes" :value="minutes">{{ minutes }} minutes</option>
                                    </select>
                                    <InputError :message="rulesForm.errors.slot_minutes" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="buffer_minutes">Buffer between meetings</Label>
                                    <div class="flex items-center gap-2">
                                        <Input
                                            id="buffer_minutes"
                                            v-model.number="rulesForm.buffer_minutes"
                                            type="number"
                                            min="0"
                                            max="120"
                                            class="h-9 w-24"
                                        />
                                        <span class="text-sm text-muted-foreground">minutes</span>
                                    </div>
                                    <InputError :message="rulesForm.errors.buffer_minutes" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="min_notice_hours">Minimum notice</Label>
                                    <div class="flex items-center gap-2">
                                        <Input
                                            id="min_notice_hours"
                                            v-model.number="rulesForm.min_notice_hours"
                                            type="number"
                                            min="0"
                                            max="720"
                                            class="h-9 w-24"
                                        />
                                        <span class="text-sm text-muted-foreground">hours before start</span>
                                    </div>
                                    <InputError :message="rulesForm.errors.min_notice_hours" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="max_days_ahead">Booking window</Label>
                                    <div class="flex items-center gap-2">
                                        <Input
                                            id="max_days_ahead"
                                            v-model.number="rulesForm.max_days_ahead"
                                            type="number"
                                            min="1"
                                            max="365"
                                            class="h-9 w-24"
                                        />
                                        <span class="text-sm text-muted-foreground">days into the future</span>
                                    </div>
                                    <InputError :message="rulesForm.errors.max_days_ahead" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="timezone">Timezone</Label>
                                    <select id="timezone" v-model="rulesForm.timezone" :class="selectClass" aria-describedby="timezone-hint">
                                        <option v-for="timezone in timezones" :key="timezone" :value="timezone">{{ timezone }}</option>
                                    </select>
                                    <p id="timezone-hint" class="text-xs text-muted-foreground">
                                        Changing this keeps the same hours in the new timezone.
                                    </p>
                                    <InputError :message="rulesForm.errors.timezone" />
                                </div>

                                <div class="flex items-center gap-3">
                                    <Button size="sm" :disabled="rulesForm.processing">Save rules</Button>
                                    <TransitionRoot
                                        :show="rulesForm.recentlySuccessful"
                                        enter="transition ease-in-out"
                                        enter-from="opacity-0"
                                        leave="transition ease-in-out"
                                        leave-to="opacity-0"
                                    >
                                        <p class="text-sm text-muted-foreground">Saved.</p>
                                    </TransitionRoot>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">Blocked dates</CardTitle>
                            <CardDescription>Days off and holidays. No one can book these.</CardDescription>
                        </CardHeader>
                        <CardContent class="grid gap-4">
                            <form class="grid gap-2" @submit.prevent="addBlockedDate">
                                <div class="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                                    <div>
                                        <Label for="blocked_date" class="sr-only">Date</Label>
                                        <Input
                                            id="blocked_date"
                                            v-model="blockForm.date"
                                            type="date"
                                            class="h-9"
                                            required
                                            :aria-invalid="Boolean(blockForm.errors.date)"
                                            aria-describedby="blocked-date-error"
                                        />
                                    </div>
                                    <div>
                                        <Label for="blocked_reason" class="sr-only">Reason (optional)</Label>
                                        <Input
                                            id="blocked_reason"
                                            v-model="blockForm.reason"
                                            placeholder="Reason (optional)"
                                            maxlength="255"
                                            class="h-9"
                                            :aria-invalid="Boolean(blockForm.errors.reason)"
                                        />
                                    </div>
                                    <Button variant="outline" size="sm" class="h-9" :disabled="blockForm.processing">Add</Button>
                                </div>
                                <div id="blocked-date-error">
                                    <InputError :message="blockForm.errors.date" />
                                    <InputError :message="blockForm.errors.reason" />
                                </div>
                            </form>

                            <p v-if="blockedDates.length === 0" class="text-sm text-muted-foreground">No blocked dates yet.</p>
                            <ul v-else class="divide-y divide-border rounded-md border border-border">
                                <li v-for="blocked in blockedDates" :key="blocked.id" class="flex items-center justify-between gap-2 px-3 py-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium tabular-nums" :class="blocked.is_past && 'text-muted-foreground'">
                                            {{ formatDate(blocked.date) }}
                                        </p>
                                        <p class="truncate text-xs text-muted-foreground">
                                            <template v-if="blocked.is_past">Past</template>
                                            <template v-if="blocked.is_past && blocked.reason"> · </template>
                                            {{ blocked.reason }}
                                        </p>
                                    </div>
                                    <Button
                                        v-if="!blocked.is_past"
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        class="h-8 w-8 shrink-0"
                                        :disabled="removingId === blocked.id"
                                        :aria-label="`Unblock ${formatDate(blocked.date)}`"
                                        @click="removeBlockedDate(blocked)"
                                    >
                                        <X class="h-4 w-4" />
                                    </Button>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
