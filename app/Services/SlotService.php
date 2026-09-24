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
     * @return Collection<int, CarbonImmutable>
     */
    public function availableSlots(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $from = $from->utc();
        $to = $to->utc();

        if ($from >= $to) {
            return collect();
        }

        $settings = Setting::current();
        $tz = $settings->timezone;
        $length = $settings->slot_minutes;
        $buffer = $settings->buffer_minutes;

        $earliest = CarbonImmutable::now('UTC')->addHours($settings->min_notice_hours);
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
            ->get(['start_at', 'end_at']);

        $slots = collect();

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

                    if (! $clashes) {
                        $slots->push($start);
                    }
                }
            }
        }

        return $slots->unique(fn (CarbonImmutable $slot) => $slot->timestamp)->sort()->values();
    }

    private function minutesOf(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }
}
