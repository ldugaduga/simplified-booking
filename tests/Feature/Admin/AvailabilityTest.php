<?php

namespace Tests\Feature\Admin;

use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2030-01-02 10:00', 'Asia/Manila'));
        Setting::current()->update(['timezone' => 'Asia/Manila']);
        $this->admin = User::factory()->create();
    }

    public function test_guests_are_redirected_and_nothing_changes()
    {
        $blocked = BlockedDate::create(['date' => '2030-02-01']);

        $this->get('/admin/availability')->assertRedirect('/login');
        $this->put('/admin/availability/hours', ['rules' => []])->assertRedirect('/login');
        $this->put('/admin/availability/rules', $this->validRules())->assertRedirect('/login');
        $this->post('/admin/blocked-dates', ['date' => '2030-02-02'])->assertRedirect('/login');
        $this->delete("/admin/blocked-dates/{$blocked->id}")->assertRedirect('/login');

        $this->assertDatabaseCount('blocked_dates', 1);
        $this->assertSame(30, Setting::current()->slot_minutes);
    }

    public function test_admin_sees_the_availability_page()
    {
        AvailabilityRule::create(['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);
        BlockedDate::create(['date' => '2030-01-01', 'reason' => 'New Year']);
        BlockedDate::create(['date' => '2030-01-10']);

        $this->actingAs($this->admin)
            ->get('/admin/availability')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Availability')
                ->has('rules', 1)
                ->where('rules.0', ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'])
                ->where('settings.timezone', 'Asia/Manila')
                ->has('blockedDates', 2)
                ->where('blockedDates.0.is_past', true)
                ->where('blockedDates.1.is_past', false)
                ->where('blockedDates.0.reason', 'New Year')
                ->has('timezones'));
    }

    public function test_weekly_hours_replace_all_rules()
    {
        AvailabilityRule::create(['weekday' => 6, 'start_time' => '10:00', 'end_time' => '12:00']);

        $this->actingAs($this->admin)->put('/admin/availability/hours', ['rules' => [
            ['weekday' => 2, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['weekday' => 2, 'start_time' => '12:00', 'end_time' => '17:00'],
        ]])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(
            [[2, '09:00', '12:00'], [2, '12:00', '17:00']],
            AvailabilityRule::orderBy('start_time')->get()->map(fn ($r) => [$r->weekday, $r->start_time, $r->end_time])->all(),
        );
    }

    public function test_empty_weekly_hours_clear_all_rules()
    {
        AvailabilityRule::create(['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);

        $this->actingAs($this->admin)->put('/admin/availability/hours', ['rules' => []])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('availability_rules', 0);
    }

    public function test_end_time_must_be_after_start_time()
    {
        $this->actingAs($this->admin)->put('/admin/availability/hours', ['rules' => [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '09:00'],
            ['weekday' => 2, 'start_time' => '17:00', 'end_time' => '09:00'],
        ]])->assertSessionHasErrors(['rules.0.end_time', 'rules.1.end_time']);
    }

    public function test_overlapping_ranges_are_rejected_on_the_later_range()
    {
        AvailabilityRule::create(['weekday' => 4, 'start_time' => '09:00', 'end_time' => '17:00']);

        $this->actingAs($this->admin)->put('/admin/availability/hours', ['rules' => [
            ['weekday' => 4, 'start_time' => '16:30', 'end_time' => '18:00'],
            ['weekday' => 4, 'start_time' => '09:00', 'end_time' => '17:00'],
        ]])->assertSessionHasErrors(['rules.0.start_time' => 'Overlaps with 09:00 - 17:00.']);

        $this->assertDatabaseCount('availability_rules', 1);
    }

    public function test_bad_weekday_or_time_format_is_rejected()
    {
        $this->actingAs($this->admin)->put('/admin/availability/hours', ['rules' => [
            ['weekday' => 7, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['weekday' => 1, 'start_time' => '9am', 'end_time' => '17:00'],
        ]])->assertSessionHasErrors(['rules.0.weekday', 'rules.1.start_time']);
    }

    public function test_booking_rules_are_saved()
    {
        $this->actingAs($this->admin)
            ->put('/admin/availability/rules', $this->validRules())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $settings = Setting::current();
        $this->assertSame(45, $settings->slot_minutes);
        $this->assertSame(10, $settings->buffer_minutes);
        $this->assertSame(24, $settings->min_notice_hours);
        $this->assertSame(30, $settings->max_days_ahead);
        $this->assertSame('Europe/London', $settings->timezone);
    }

    public function test_invalid_booking_rules_are_rejected()
    {
        $this->actingAs($this->admin)->put('/admin/availability/rules', [
            'slot_minutes' => 20,
            'buffer_minutes' => -5,
            'min_notice_hours' => 721,
            'max_days_ahead' => 0,
            'timezone' => 'Mars/Olympus',
        ])->assertSessionHasErrors(['slot_minutes', 'buffer_minutes', 'min_notice_hours', 'max_days_ahead', 'timezone']);

        $this->assertSame('Asia/Manila', Setting::current()->timezone);
    }

    public function test_blocked_date_can_be_added_and_removed()
    {
        $this->actingAs($this->admin)
            ->post('/admin/blocked-dates', ['date' => '2030-01-02', 'reason' => 'Team offsite'])
            ->assertSessionHasNoErrors();

        $blocked = BlockedDate::sole();
        $this->assertSame('2030-01-02', $blocked->date->toDateString());
        $this->assertSame('Team offsite', $blocked->reason);

        $this->actingAs($this->admin)->delete("/admin/blocked-dates/{$blocked->id}")->assertRedirect();
        $this->assertDatabaseCount('blocked_dates', 0);
    }

    public function test_invalid_blocked_dates_are_rejected()
    {
        BlockedDate::create(['date' => '2030-01-05']);

        $this->actingAs($this->admin)
            ->post('/admin/blocked-dates', ['date' => '2030-01-05'])
            ->assertSessionHasErrors(['date' => 'This date is already blocked.']);

        $this->actingAs($this->admin)
            ->post('/admin/blocked-dates', ['date' => '2030-01-01'])
            ->assertSessionHasErrors(['date' => 'Choose today or a future date.']);

        $this->actingAs($this->admin)
            ->post('/admin/blocked-dates', ['date' => '2030-01-06', 'reason' => str_repeat('a', 256)])
            ->assertSessionHasErrors(['reason']);

        $this->assertDatabaseCount('blocked_dates', 1);
    }

    /**
     * @return array<string, int|string>
     */
    private function validRules(): array
    {
        return [
            'slot_minutes' => 45,
            'buffer_minutes' => 10,
            'min_notice_hours' => 24,
            'max_days_ahead' => 30,
            'timezone' => 'Europe/London',
        ];
    }
}
