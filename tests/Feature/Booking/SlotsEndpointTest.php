<?php

namespace Tests\Feature\Booking;

use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlotsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2030-01-02 00:00', 'Asia/Manila'));
        Setting::current()->update(['timezone' => 'Asia/Manila', 'slot_minutes' => 30, 'buffer_minutes' => 0, 'min_notice_hours' => 0, 'max_days_ahead' => 60]);

        foreach (range(1, 5) as $weekday) {
            AvailabilityRule::create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00']);
        }
    }

    public function test_returns_utc_slots_for_the_window()
    {
        // Monday 2030-01-07 in Manila = 2030-01-06T16:00Z .. 2030-01-07T16:00Z
        $response = $this->getJson('/slots?start=2030-01-06T16:00:00Z&end=2030-01-07T16:00:00Z')->assertOk();

        $slots = $response->json('slots');
        $this->assertCount(16, $slots);
        $this->assertSame('2030-01-07T01:00:00Z', $slots[0]);
        $this->assertSame('2030-01-07T08:30:00Z', $slots[15]);
        $this->assertSame($slots, collect($slots)->sort()->values()->all());
    }

    public function test_offsets_in_the_window_are_accepted()
    {
        $this->getJson('/slots?'.http_build_query(['start' => '2030-01-07T00:00:00+08:00', 'end' => '2030-01-08T00:00:00+08:00']))
            ->assertOk()
            ->assertJsonCount(16, 'slots');
    }

    public function test_booked_and_blocked_times_are_absent()
    {
        Appointment::factory()->create([
            'start_at' => '2030-01-07 02:00:00',
            'end_at' => '2030-01-07 02:30:00',
            'name' => 'Private Person',
            'email' => 'private@example.com',
        ]);
        BlockedDate::create(['date' => '2030-01-08']);

        $response = $this->getJson('/slots?start=2030-01-06T16:00:00Z&end=2030-01-08T16:00:00Z')->assertOk();

        $slots = $response->json('slots');
        $this->assertNotContains('2030-01-07T02:00:00Z', $slots);
        $this->assertCount(15, $slots);
        $this->assertStringNotContainsString('Private Person', $response->getContent());
        $this->assertStringNotContainsString('private@example.com', $response->getContent());
    }

    public function test_invalid_windows_are_rejected()
    {
        $this->getJson('/slots')->assertStatus(422)->assertJsonValidationErrors(['start', 'end']);
        $this->getJson('/slots?start=nope&end=2030-01-07T00:00:00Z')->assertStatus(422)->assertJsonValidationErrors(['start']);
        $this->getJson('/slots?start=2030-01-07T00:00:00Z&end=2030-01-07T00:00:00Z')->assertStatus(422)->assertJsonValidationErrors(['end']);
        $this->getJson('/slots?start=2030-01-01T00:00:00Z&end=2030-02-16T00:00:00Z')->assertStatus(422)->assertJsonValidationErrors(['end']);
        $this->getJson('/slots?start=2030-01-01T00:00:00Z&end=2030-02-15T00:00:00Z')->assertOk();
    }
}
