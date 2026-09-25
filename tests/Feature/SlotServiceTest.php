<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\Setting;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SlotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 2030-01-02, 00:00 in Manila.
        $this->travelTo(CarbonImmutable::parse('2030-01-02 00:00', 'Asia/Manila'));

        Setting::current()->update([
            'timezone' => 'Asia/Manila',
            'slot_minutes' => 30,
            'buffer_minutes' => 0,
            'min_notice_hours' => 0,
            'max_days_ahead' => 60,
        ]);

        foreach (range(1, 5) as $weekday) {
            $this->rule($weekday, '09:00', '17:00');
        }
    }

    public function test_weekday_returns_every_slot_in_utc()
    {
        $slots = $this->slotsOn('2030-01-07'); // Monday

        $this->assertCount(16, $slots);
        $this->assertSame('2030-01-07 01:00', $slots->first());
        $this->assertSame('2030-01-07 08:30', $slots->last());
    }

    public function test_day_without_rules_is_empty()
    {
        $this->assertEmpty($this->slotsOn('2030-01-05')); // Saturday
    }

    public function test_split_shift_leaves_the_gap_open()
    {
        AvailabilityRule::query()->delete();
        $this->rule(1, '09:00', '12:00');
        $this->rule(1, '13:00', '17:00');

        $local = $this->slotsOn('2030-01-07', 'Asia/Manila');

        $this->assertContains('2030-01-07 11:30', $local);
        $this->assertNotContains('2030-01-07 12:00', $local);
        $this->assertNotContains('2030-01-07 12:30', $local);
        $this->assertContains('2030-01-07 13:00', $local);
        $this->assertCount(14, $local);
    }

    public function test_slot_must_fit_inside_the_range()
    {
        AvailabilityRule::query()->delete();
        $this->rule(1, '09:00', '10:00');
        Setting::current()->update(['slot_minutes' => 45]);

        $this->assertSame(['2030-01-07 09:00'], $this->slotsOn('2030-01-07', 'Asia/Manila')->all());
    }

    public function test_blocked_date_has_no_slots()
    {
        BlockedDate::create(['date' => '2030-01-07', 'reason' => 'Holiday']);

        $this->assertEmpty($this->slotsOn('2030-01-07'));
        $this->assertCount(16, $this->slotsOn('2030-01-08'));
    }

    public function test_minimum_notice_hides_slots_that_start_too_soon()
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-07 09:10', 'Asia/Manila'));
        Setting::current()->update(['min_notice_hours' => 4]);

        $local = $this->slotsOn('2030-01-07', 'Asia/Manila');

        $this->assertSame('2030-01-07 13:30', $local->first());
    }

    public function test_booking_window_limits_how_far_ahead()
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-07 08:00', 'Asia/Manila')); // Monday
        Setting::current()->update(['max_days_ahead' => 2]);

        $this->assertNotEmpty($this->slotsOn('2030-01-09')); // today + 2
        $this->assertEmpty($this->slotsOn('2030-01-10'));    // today + 3
    }

    public function test_confirmed_appointment_and_buffer_block_nearby_slots()
    {
        Setting::current()->update(['buffer_minutes' => 15]);
        $this->appointment('2030-01-07 10:00', '2030-01-07 10:30');

        $local = $this->slotsOn('2030-01-07', 'Asia/Manila');

        $this->assertContains('2030-01-07 09:00', $local);
        $this->assertNotContains('2030-01-07 09:30', $local);
        $this->assertNotContains('2030-01-07 10:00', $local);
        $this->assertNotContains('2030-01-07 10:30', $local);
        $this->assertContains('2030-01-07 11:00', $local);
    }

    public function test_without_buffer_only_the_booked_slot_is_removed()
    {
        $this->appointment('2030-01-07 10:00', '2030-01-07 10:30');

        $local = $this->slotsOn('2030-01-07', 'Asia/Manila');

        $this->assertCount(15, $local);
        $this->assertNotContains('2030-01-07 10:00', $local);
        $this->assertContains('2030-01-07 09:30', $local);
        $this->assertContains('2030-01-07 10:30', $local);
    }

    public function test_cancelled_appointment_does_not_block()
    {
        $this->appointment('2030-01-07 10:00', '2030-01-07 10:30', AppointmentStatus::Cancelled);

        $this->assertCount(16, $this->slotsOn('2030-01-07'));
    }

    public function test_dst_gap_skips_nonexistent_times()
    {
        // US spring forward: 2030-03-10 02:00 jumps to 03:00 (a Sunday).
        $this->travelTo(CarbonImmutable::parse('2030-03-01 00:00', 'America/New_York'));
        Setting::current()->update(['timezone' => 'America/New_York']);
        $this->rule(0, '01:00', '04:00');

        $local = $this->slotsOn('2030-03-10', 'America/New_York');
        $utc = $this->slotsOn('2030-03-10', 'UTC', 'America/New_York');

        $this->assertSame(['2030-03-10 01:00', '2030-03-10 01:30', '2030-03-10 03:00', '2030-03-10 03:30'], $local->all());
        $this->assertSame('2030-03-10 06:00', $utc->first()); // 01:00 EST = UTC-5
        $this->assertSame('2030-03-10 07:00', $utc[2]);       // 03:00 EDT = UTC-4
    }

    public function test_results_are_limited_to_the_window_and_sorted()
    {
        $from = CarbonImmutable::parse('2030-01-07 10:00', 'Asia/Manila');
        $to = CarbonImmutable::parse('2030-01-08 10:00', 'Asia/Manila');

        $slots = app(SlotService::class)->availableSlots($from, $to);
        $local = $slots->map(fn ($s) => $s->setTimezone('Asia/Manila')->format('Y-m-d H:i'));

        $this->assertSame('2030-01-07 10:00', $local->first());
        $this->assertSame('2030-01-08 09:30', $local->last());
        $this->assertCount(14 + 2, $slots);
        $this->assertSame($slots->sort()->values()->all(), $slots->all());
        $this->assertTrue($slots->every(fn ($s) => $s->timezoneName === 'UTC'));
    }

    public function test_admin_can_ignore_minimum_notice_but_not_the_past()
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-07 09:10', 'Asia/Manila'));
        Setting::current()->update(['min_notice_hours' => 4]);

        $from = CarbonImmutable::parse('2030-01-07', 'Asia/Manila')->startOfDay();
        $local = fn ($slots) => $slots->map(fn ($s) => $s->setTimezone('Asia/Manila')->format('H:i'))->values();

        $this->assertSame('13:30', $local(app(SlotService::class)->availableSlots($from, $from->addDay()))->first());
        $admin = $local(app(SlotService::class)->availableSlots($from, $from->addDay(), ignoreMinimumNotice: true));
        $this->assertSame('09:30', $admin->first());
        $this->assertNotContains('09:00', $admin);
    }

    public function test_excluded_appointment_frees_its_own_slot_and_buffer()
    {
        Setting::current()->update(['buffer_minutes' => 15]);
        $own = Appointment::factory()->create([
            'start_at' => CarbonImmutable::parse('2030-01-07 10:00', 'Asia/Manila')->utc(),
            'end_at' => CarbonImmutable::parse('2030-01-07 10:30', 'Asia/Manila')->utc(),
        ]);
        $this->appointment('2030-01-07 14:00', '2030-01-07 14:30');

        $from = CarbonImmutable::parse('2030-01-07', 'Asia/Manila')->startOfDay();
        $local = app(SlotService::class)
            ->availableSlots($from, $from->addDay(), exceptAppointmentId: $own->id)
            ->map(fn ($s) => $s->setTimezone('Asia/Manila')->format('H:i'));

        $this->assertContains('09:30', $local);
        $this->assertContains('10:00', $local);
        $this->assertContains('10:30', $local);
        $this->assertNotContains('14:00', $local);
        $this->assertNotContains('13:30', $local);
    }

    public function test_a_booked_slot_appears_in_taken_and_not_available()
    {
        $this->appointment('2030-01-07 10:00', '2030-01-07 10:30');

        $this->assertContains('2030-01-07 10:00', $this->takenOn('2030-01-07', 'Asia/Manila'));
        $this->assertNotContains('2030-01-07 10:00', $this->slotsOn('2030-01-07', 'Asia/Manila'));
    }

    public function test_slots_outside_the_grid_appear_in_neither_list()
    {
        BlockedDate::create(['date' => '2030-01-08']);

        // Before the minimum notice window.
        Setting::current()->update(['min_notice_hours' => 4]);
        $this->travelTo(CarbonImmutable::parse('2030-01-07 09:10', 'Asia/Manila'));
        $this->assertNotContains('2030-01-07 09:00', $this->takenOn('2030-01-07'));
        $this->assertNotContains('2030-01-07 09:00', $this->slotsOn('2030-01-07'));

        // Blocked date.
        $this->assertEmpty($this->takenOn('2030-01-08'));
        $this->assertEmpty($this->slotsOn('2030-01-08'));

        // Beyond the booking window.
        Setting::current()->update(['max_days_ahead' => 2]);
        $this->assertEmpty($this->takenOn('2030-01-20'));
        $this->assertEmpty($this->slotsOn('2030-01-20'));
    }

    public function test_buffer_widens_taken_the_same_way_it_widens_available()
    {
        Setting::current()->update(['buffer_minutes' => 15]);
        $this->appointment('2030-01-07 10:00', '2030-01-07 10:30');

        $taken = $this->takenOn('2030-01-07', 'Asia/Manila');
        $this->assertContains('2030-01-07 09:30', $taken);
        $this->assertContains('2030-01-07 10:00', $taken);
        $this->assertContains('2030-01-07 10:30', $taken);
        $this->assertNotContains('2030-01-07 09:00', $taken);
        $this->assertNotContains('2030-01-07 11:00', $taken);
    }

    public function test_a_cancelled_appointments_slot_is_available_not_taken()
    {
        $this->appointment('2030-01-07 10:00', '2030-01-07 10:30', AppointmentStatus::Cancelled);

        $this->assertContains('2030-01-07 10:00', $this->slotsOn('2030-01-07', 'Asia/Manila'));
        $this->assertNotContains('2030-01-07 10:00', $this->takenOn('2030-01-07', 'Asia/Manila'));
    }

    private function rule(int $weekday, string $start, string $end): void
    {
        AvailabilityRule::create(['weekday' => $weekday, 'start_time' => $start, 'end_time' => $end]);
    }

    private function appointment(string $start, string $end, AppointmentStatus $status = AppointmentStatus::Confirmed): void
    {
        Appointment::factory()->create([
            'start_at' => CarbonImmutable::parse($start, 'Asia/Manila')->utc(),
            'end_at' => CarbonImmutable::parse($end, 'Asia/Manila')->utc(),
            'status' => $status,
        ]);
    }

    /**
     * Slots for one local calendar day, formatted in $displayTz.
     *
     * @return Collection<int, string>
     */
    private function slotsOn(string $date, string $displayTz = 'UTC', ?string $dayTz = null)
    {
        $dayTz ??= Setting::current()->timezone;
        $from = CarbonImmutable::parse($date, $dayTz)->startOfDay();

        return app(SlotService::class)
            ->availableSlots($from, $from->addDay())
            ->map(fn (CarbonImmutable $slot) => $slot->setTimezone($displayTz)->format('Y-m-d H:i'))
            ->values();
    }

    /**
     * Taken slots for one local calendar day, formatted in $displayTz.
     *
     * @return Collection<int, string>
     */
    private function takenOn(string $date, string $displayTz = 'UTC', ?string $dayTz = null)
    {
        $dayTz ??= Setting::current()->timezone;
        $from = CarbonImmutable::parse($date, $dayTz)->startOfDay();

        return app(SlotService::class)
            ->takenSlots($from, $from->addDay())
            ->map(fn (CarbonImmutable $slot) => $slot->setTimezone($displayTz)->format('Y-m-d H:i'))
            ->values();
    }
}
