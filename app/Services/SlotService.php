<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SlotService
{
    /**
     * Open slot start times (UTC) in [$from, $to), ascending.
     *
     * The admin may book inside the minimum-notice window (never in the past), and a
     * rescheduled appointment must not block its own slot.
     *
     * @return Collection<int, CarbonImmutable>
     */
    public function availableSlots(
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $ignoreMinimumNotice = false,
        ?int $exceptAppointmentId = null,
    ): Collection {
        return $this->candidates($from, $to, $ignoreMinimumNotice, $exceptAppointmentId)
            ->filter(fn (array $candidate) => $candidate['available'])
            ->pluck('start')
            ->values();
    }

    /**
     * Grid slot start times (UTC) in [$from, $to), ascending, that clash with an active
     * appointment. These are within working hours, not blocked, and inside the booking
     * window, exactly like `availableSlots()`, but taken rather than open. Used to show
     * a booked time as unavailable on the public page instead of omitting it.
     *
     * @return Collection<int, CarbonImmutable>
     */
    public function takenSlots(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->candidates($from, $to, ignoreMinimumNotice: false, exceptAppointmentId: null)
            ->reject(fn (array $candidate) => $candidate['available'])
            ->pluck('start')
            ->values();
    }

    /**
     * Every grid slot in [$from, $to) that passes the working-hours, blocked-date,
     * minimum-notice, and booking-window filters, each tagged with whether it clashes
     * with an active appointment.
     *
     * @return Collection<int, array{start: CarbonImmutable, available: bool}>
     */
    private function candidates(
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $ignoreMinimumNotice,
        ?int $exceptAppointmentId,
    ): Collection {
        $from = $from->utc();
        $to = $to->utc();

        if ($from >= $to) {
            return collect();
        }

        $settings = Setting::current();
        $tz = $settings->timezone;
        $length = $settings->slot_minutes;
        $buffer = $settings->buffer_minutes;

        $earliest = CarbonImmutable::now('UTC')->addHours($ignoreMinimumNotice ? 0 : $settings->min_notice_hours);
        $lastDate = CarbonImmutable::now($tz)->startOfDay()->addDays($settings->max_days_ahead)->toDateString();

        $firstDay = $from->setTimezone($tz)->startOfDay();
        $lastDay = $to->setTimezone($tz)->startOfDay();

        $rulesByWeekday = AvailabilityRule::query()
            ->orderBy('start_time')
            ->get()
            ->groupBy('weekday');

        $blocked = BlockedDate::query()
            ->whereBetween('date', [$firstDay->toDateString(), $lastDay->toDateString()])
            ->pluck('date')
            ->map(fn ($date) => $date->toDateString())
            ->flip();

        $appointments = Appointment::active()
            ->overlapping($from->subMinutes($buffer + $length), $to->addMinutes($buffer + $length))
            ->when($exceptAppointmentId, fn ($query) => $query->whereKeyNot($exceptAppointmentId))
            ->get(['start_at', 'end_at']);

        $candidates = collect();

        for ($day = $firstDay; $day <= $lastDay; $day = $day->addDay()) {
            $date = $day->toDateString();

            if ($blocked->has($date) || $date > $lastDate) {
                continue;
            }

            foreach ($rulesByWeekday->get($day->dayOfWeek, []) as $rule) {
                $rangeStart = $this->minutesOf($rule->start_time);
                $rangeEnd = $this->minutesOf($rule->end_time);

                // Step through wall-clock minutes so the grid stays fixed across DST changes.
                for ($minute = $rangeStart; $minute + $length <= $rangeEnd; $minute += $length) {
                    $wallClock = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
                    $local = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$wallClock}", $tz);

                    // A time inside a DST gap does not exist and comes back shifted.
                    if ($local->format('H:i') !== $wallClock) {
                        continue;
                    }

                    $start = $local->utc();
                    $end = $start->addMinutes($length);

                    if ($start < $from || $start >= $to || $start < $earliest) {
                        continue;
                    }

                    $clashes = $appointments->contains(fn (Appointment $a) => $a->start_at->subMinutes($buffer) < $end
                        && $a->end_at->addMinutes($buffer) > $start);

                    $candidates->push(['start' => $start, 'available' => ! $clashes]);
                }
            }
        }

        return $candidates->unique(fn (array $candidate) => $candidate['start']->timestamp)->sortBy(fn (array $candidate) => $candidate['start']->timestamp)->values();
    }

    private function minutesOf(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }
}
