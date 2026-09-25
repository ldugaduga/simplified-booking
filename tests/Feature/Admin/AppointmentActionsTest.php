<?php

namespace Tests\Feature\Admin;

use App\Enums\AppointmentStatus;
use App\Mail\AppointmentBooked;
use App\Mail\AppointmentCancelled;
use App\Mail\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\Setting;
use App\Models\User;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AppointmentActionsTest extends TestCase
{
    use RefreshDatabase;

    private const TAKEN = 'That time is no longer available. Please pick another.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 2030-01-07, 09:10 in Manila; the 4h notice would hide slots before 13:30.
        $this->travelTo(CarbonImmutable::parse('2030-01-07 09:10', 'Asia/Manila'));
        Setting::current()->update(['timezone' => 'Asia/Manila', 'slot_minutes' => 30, 'buffer_minutes' => 0, 'min_notice_hours' => 4, 'max_days_ahead' => 60]);

        foreach (range(1, 5) as $weekday) {
            AvailabilityRule::create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00']);
        }

        $this->admin = User::factory()->create();
    }

    public function test_admin_slots_ignore_minimum_notice_and_can_exclude_an_appointment()
    {
        $own = $this->make('2030-01-07 10:00');

        $slots = $this->actingAs($this->admin)->getJson('/admin/slots?date=2030-01-07')->assertOk()->json('slots');
        $this->assertSame($this->utc('2030-01-07 09:30'), $slots[0]);
        $this->assertNotContains($this->utc('2030-01-07 10:00'), $slots);

        $slots = $this->actingAs($this->admin)->getJson("/admin/slots?date=2030-01-07&except={$own->id}")->json('slots');
        $this->assertContains($this->utc('2030-01-07 10:00'), $slots);

        $this->actingAs($this->admin)->getJson('/admin/slots?date=07-01-2030')->assertStatus(422)->assertJsonValidationErrors('date');
        $this->actingAs($this->admin)->getJson('/admin/slots?date=2030-01-07&except=999')->assertStatus(422)->assertJsonValidationErrors('except');
    }

    public function test_admin_can_book_inside_the_notice_window()
    {
        Mail::fake();

        $this->actingAs($this->admin)
            ->post('/admin/appointments', $this->payload('2030-01-07 10:00'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $appointment = Appointment::sole();
        $this->assertSame($this->admin->id, $appointment->created_by);
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame(30, (int) $appointment->start_at->diffInMinutes($appointment->end_at));
        Mail::assertQueued(AppointmentBooked::class, fn (AppointmentBooked $mail) => $mail->hasTo($appointment->email));
    }

    public function test_admin_booking_still_respects_the_other_rules()
    {
        BlockedDate::create(['date' => '2030-01-08']);
        $this->make('2030-01-09 10:00');

        foreach (['2030-01-08 10:00', '2030-01-07 10:10', '2030-01-07 09:00', '2030-01-09 10:00', '2030-01-12 10:00'] as $local) {
            $this->actingAs($this->admin)
                ->post('/admin/appointments', $this->payload($local))
                ->assertSessionHasErrors(['start_at' => self::TAKEN]);
        }

        $this->actingAs($this->admin)->post('/admin/appointments', $this->payload('2030-01-07 10:00', ['name' => '', 'email' => 'nope@gmail']))
            ->assertSessionHasErrors(['name', 'email']);

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_create_race_returns_the_friendly_error()
    {
        $this->make('2030-01-07 10:00');
        $this->mock(SlotService::class)->shouldReceive('availableSlots')
            ->andReturn(collect([CarbonImmutable::parse($this->utc('2030-01-07 10:00'))]));

        $this->actingAs($this->admin)
            ->post('/admin/appointments', $this->payload('2030-01-07 10:00'))
            ->assertSessionHasErrors(['start_at' => self::TAKEN]);

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_reschedule_moves_the_appointment_and_frees_the_old_slot()
    {
        Mail::fake();

        $appointment = $this->make('2030-01-07 10:00', 45);

        $this->actingAs($this->admin)
            ->patch("/admin/appointments/{$appointment->id}/reschedule", ['start_at' => $this->utc('2030-01-07 11:00')])
            ->assertSessionHasNoErrors();

        $appointment->refresh();
        $this->assertSame($this->utc('2030-01-07 11:00'), $appointment->start_at->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(45, (int) $appointment->start_at->diffInMinutes($appointment->end_at));
        $this->assertTrue($appointment->slot_lock->equalTo($appointment->start_at));

        $slots = $this->actingAs($this->admin)->getJson('/admin/slots?date=2030-01-07')->json('slots');
        $this->assertContains($this->utc('2030-01-07 10:00'), $slots);
        Mail::assertQueued(AppointmentRescheduled::class, fn (AppointmentRescheduled $mail) => $mail->hasTo($appointment->email));
    }

    public function test_reschedule_to_its_own_slot_succeeds()
    {
        $appointment = $this->make('2030-01-07 10:00');

        $this->actingAs($this->admin)
            ->patch("/admin/appointments/{$appointment->id}/reschedule", ['start_at' => $this->utc('2030-01-07 10:00')])
            ->assertSessionHasNoErrors();
    }

    public function test_reschedule_rejects_cancelled_and_taken_targets()
    {
        $cancelled = $this->make('2030-01-07 10:00', 30, AppointmentStatus::Cancelled);
        $this->actingAs($this->admin)
            ->patch("/admin/appointments/{$cancelled->id}/reschedule", ['start_at' => $this->utc('2030-01-07 11:00')])
            ->assertSessionHasErrors(['start_at' => 'Only confirmed appointments can be rescheduled.']);

        $appointment = $this->make('2030-01-07 13:00');
        $this->make('2030-01-07 14:00');
        $this->actingAs($this->admin)
            ->patch("/admin/appointments/{$appointment->id}/reschedule", ['start_at' => $this->utc('2030-01-07 14:00')])
            ->assertSessionHasErrors(['start_at' => self::TAKEN]);

        $this->assertSame($this->utc('2030-01-07 13:00'), $appointment->fresh()->start_at->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_reschedule_checks_the_whole_kept_duration_for_overlaps()
    {
        // A 60-minute appointment from before the slot length became 30 minutes.
        $long = $this->make('2030-01-07 13:00', 60);
        $this->make('2030-01-07 15:30');

        $this->actingAs($this->admin)
            ->patch("/admin/appointments/{$long->id}/reschedule", ['start_at' => $this->utc('2030-01-07 15:00')])
            ->assertSessionHasErrors(['start_at' => self::TAKEN]);
        $this->assertSame($this->utc('2030-01-07 13:00'), $long->fresh()->start_at->utc()->format('Y-m-d\TH:i:s\Z'));

        // Ending exactly when the neighbour starts is fine.
        $this->actingAs($this->admin)
            ->patch("/admin/appointments/{$long->id}/reschedule", ['start_at' => $this->utc('2030-01-07 14:30')])
            ->assertSessionHasNoErrors();
        $this->assertSame(60, (int) $long->fresh()->start_at->diffInMinutes($long->fresh()->end_at));
    }

    public function test_cancel_frees_the_slot_and_is_idempotent()
    {
        Mail::fake();

        $appointment = $this->make('2030-01-08 14:00');

        $this->actingAs($this->admin)->patch("/admin/appointments/{$appointment->id}/cancel")->assertRedirect();
        Mail::assertQueued(AppointmentCancelled::class, fn (AppointmentCancelled $mail) => $mail->hasTo($appointment->email));

        Mail::fake();
        $this->actingAs($this->admin)->patch("/admin/appointments/{$appointment->id}/cancel")->assertRedirect();
        Mail::assertNothingQueued();

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertNull($appointment->slot_lock);

        $this->app['auth']->forgetGuards();
        $this->post('/book', [
            'start_at' => $this->utc('2030-01-08 14:00'),
            'name' => 'New Visitor',
            'email' => 'new.visitor@gmail.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Appointment::count());
    }

    public function test_guests_cannot_use_admin_actions()
    {
        $appointment = $this->make('2030-01-07 13:00');

        $this->getJson('/admin/slots?date=2030-01-07')->assertUnauthorized();
        $this->post('/admin/appointments', $this->payload('2030-01-07 14:00'))->assertRedirect('/login');
        $this->patch("/admin/appointments/{$appointment->id}/reschedule", ['start_at' => $this->utc('2030-01-07 14:00')])->assertRedirect('/login');
        $this->patch("/admin/appointments/{$appointment->id}/cancel")->assertRedirect('/login');

        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    private function utc(string $local): string
    {
        return CarbonImmutable::parse($local, 'Asia/Manila')->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function make(string $local, int $minutes = 30, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
    {
        $start = CarbonImmutable::parse($local, 'Asia/Manila')->utc();

        return Appointment::factory()->create(['start_at' => $start, 'end_at' => $start->addMinutes($minutes), 'status' => $status]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(string $local, array $overrides = []): array
    {
        return array_merge([
            'start_at' => $this->utc($local),
            'name' => 'Grace Villanueva',
            'email' => 'grace@villanueva-law.ph',
            'notes' => 'Phone follow-up',
        ], $overrides);
    }
}
