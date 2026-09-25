<?php

namespace Tests\Feature\Booking;

use App\Enums\AppointmentStatus;
use App\Mail\AppointmentBooked;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\Setting;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BookAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = '2030-01-07T02:00:00Z'; // Monday 10:00 in Manila

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2030-01-02 09:00', 'Asia/Manila'));
        Setting::current()->update(['timezone' => 'Asia/Manila', 'slot_minutes' => 30, 'buffer_minutes' => 0, 'min_notice_hours' => 4, 'max_days_ahead' => 60]);

        foreach (range(1, 5) as $weekday) {
            AvailabilityRule::create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00']);
        }
    }

    public function test_visitor_can_book_an_open_slot()
    {
        Mail::fake();

        $response = $this->post('/book', $this->payload());

        $appointment = Appointment::sole();
        Mail::assertQueued(AppointmentBooked::class, fn (AppointmentBooked $mail) => $mail->hasTo($appointment->email)
            && $mail->appointment->is($appointment));
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame('2030-01-07 02:00:00', $appointment->start_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2030-01-07 02:30:00', $appointment->end_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(64, strlen($appointment->manage_token));
        $this->assertNull($appointment->created_by);

        $location = $response->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/book/confirmed/'.$appointment->id, $location);
        $this->assertStringContainsString('signature=', $location);

        $this->get($location)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('booking/Confirmed')
                ->where('name', 'James Reyes')
                ->where('start_at', self::SLOT)
                ->where('end_at', '2030-01-07T02:30:00Z')
                ->where('slotMinutes', 30)
                ->missing('manage_token')
                ->missing('notes')
                ->missing('id'));
    }

    public function test_unavailable_times_are_rejected()
    {
        BlockedDate::create(['date' => '2030-01-08']);
        Appointment::factory()->create(['start_at' => '2030-01-09 02:00:00', 'end_at' => '2030-01-09 02:30:00']);

        $cases = [
            'off grid' => '2030-01-07T02:10:00Z',
            'inside minimum notice' => '2030-01-02T02:30:00Z',
            'beyond window' => '2030-03-15T02:00:00Z',
            'blocked date' => '2030-01-08T02:00:00Z',
            'already booked' => '2030-01-09T02:00:00Z',
            'weekend' => '2030-01-05T02:00:00Z',
        ];

        foreach ($cases as $label => $start) {
            $this->post('/book', $this->payload(['start_at' => $start]))
                ->assertSessionHasErrors(['start_at' => 'That time is no longer available. Please pick another.'], errorBag: 'default');
            $this->assertDatabaseCount('appointments', 1);
            $this->app['cache']->flush(); // keep the rate limiter out of this test
        }
    }

    public function test_start_time_without_an_offset_is_rejected()
    {
        $this->post('/book', $this->payload(['start_at' => '2030-01-07 02:00:00']))
            ->assertSessionHasErrors('start_at');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_a_rejected_booking_queues_no_email()
    {
        Mail::fake();

        $this->post('/book', $this->payload(['start_at' => '2030-01-07T02:10:00Z']))
            ->assertSessionHasErrors('start_at');

        Mail::assertNothingQueued();
    }

    public function test_double_booking_race_returns_a_friendly_error()
    {
        Appointment::factory()->create(['start_at' => '2030-01-07 02:00:00', 'end_at' => '2030-01-07 02:30:00']);

        // Simulate the other booking landing after our availability check passed.
        $this->mock(SlotService::class)
            ->shouldReceive('availableSlots')
            ->andReturn(collect([CarbonImmutable::parse(self::SLOT)]));

        Mail::fake();

        $this->post('/book', $this->payload())
            ->assertSessionHasErrors(['start_at' => 'That time is no longer available. Please pick another.']);

        $this->assertDatabaseCount('appointments', 1);
        Mail::assertNothingQueued();
    }

    public function test_details_are_validated()
    {
        $this->post('/book', $this->payload(['name' => '']))->assertSessionHasErrors('name');
        $this->post('/book', $this->payload(['email' => 'james.reyes@gmail']))->assertSessionHasErrors('email');
        $this->post('/book', $this->payload(['notes' => str_repeat('a', 1001)]))->assertSessionHasErrors('notes');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_filled_honeypot_is_rejected()
    {
        $this->post('/book', $this->payload(['company_website' => 'https://spam.example']))
            ->assertSessionHasErrors(['company_website' => 'Something went wrong. Please try again.']);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_booking_attempts_are_rate_limited_per_ip()
    {
        foreach (range(1, 5) as $attempt) {
            $this->post('/book', $this->payload(['name' => '']))->assertSessionHasErrors('name');
        }

        $this->post('/book', $this->payload())
            ->assertSessionHasErrors(['start_at' => 'Too many booking attempts. Please try again in 10 minutes.']);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_confirmation_needs_a_valid_signature()
    {
        [$appointment, $other] = Appointment::factory()->count(2)->create();

        $this->get("/book/confirmed/{$appointment->id}")->assertForbidden();

        $signed = URL::temporarySignedRoute('booking.confirmed', now()->addDay(), $appointment);
        $this->get(str_replace("/{$appointment->id}?", "/{$other->id}?", $signed))->assertForbidden();

        $this->travel(25)->hours();
        $this->get($signed)->assertForbidden();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'start_at' => self::SLOT,
            'name' => 'James Reyes',
            'email' => 'james.reyes@gmail.com',
            'notes' => 'Quarterly review',
        ], $overrides);
    }
}
