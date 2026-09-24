<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBookingRulesRequest;
use App\Http\Requests\Admin\UpdateWeeklyHoursRequest;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\Setting;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AvailabilityController extends Controller
{
    /**
     * Show the weekly hours, booking rules and blocked dates.
     */
    public function edit(): Response
    {
        $settings = Setting::current();
        $today = now($settings->timezone)->toDateString();

        return Inertia::render('admin/Availability', [
            'rules' => AvailabilityRule::query()
                ->orderBy('weekday')
                ->orderBy('start_time')
                ->get()
                ->map(fn (AvailabilityRule $rule) => [
                    'weekday' => $rule->weekday,
                    'start_time' => substr($rule->start_time, 0, 5),
                    'end_time' => substr($rule->end_time, 0, 5),
                ]),
            'settings' => $settings->only(['timezone', 'slot_minutes', 'buffer_minutes', 'min_notice_hours', 'max_days_ahead']),
            'blockedDates' => BlockedDate::query()
                ->orderBy('date')
                ->get()
                ->map(fn (BlockedDate $blocked) => [
                    'id' => $blocked->id,
                    'date' => $blocked->date->toDateString(),
                    'reason' => $blocked->reason,
                    'is_past' => $blocked->date->toDateString() < $today,
                ]),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * Replace the weekly working hours.
     */
    public function updateHours(UpdateWeeklyHoursRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            AvailabilityRule::query()->delete();

            foreach ($request->validated('rules') as $rule) {
                AvailabilityRule::create($rule);
            }
        });

        return back();
    }

    /**
     * Update the booking rules.
     */
    public function updateRules(UpdateBookingRulesRequest $request): RedirectResponse
    {
        Setting::current()->update($request->validated());

        return back();
    }
}
