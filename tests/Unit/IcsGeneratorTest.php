<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Setting;
use App\Services\IcsGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IcsGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_valid_calendar_block_with_crlf_line_endings()
    {
        $appointment = Appointment::factory()->create([
            'start_at' => CarbonImmutable::parse('2030-01-07 02:00:00', 'UTC'),
            'end_at' => CarbonImmutable::parse('2030-01-07 02:30:00', 'UTC'),
        ]);

        $ics = (new IcsGenerator)->forAppointment($appointment, 'REQUEST');

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("\r\n", $ics);
        $this->assertStringNotContainsString("\r\r\n", $ics);
    }

    public function test_start_and_end_are_utc_basic_format()
    {
        $appointment = Appointment::factory()->create([
            'start_at' => CarbonImmutable::parse('2030-01-07 10:00:00', 'Asia/Manila')->utc(),
            'end_at' => CarbonImmutable::parse('2030-01-07 10:30:00', 'Asia/Manila')->utc(),
        ]);

        $ics = (new IcsGenerator)->forAppointment($appointment, 'REQUEST');

        $this->assertStringContainsString('DTSTART:20300107T020000Z', $ics);
        $this->assertStringContainsString('DTEND:20300107T023000Z', $ics);
    }

    public function test_uid_is_stable_across_calls_for_the_same_appointment()
    {
        $appointment = Appointment::factory()->create();
        $generator = new IcsGenerator;

        $booked = $generator->forAppointment($appointment, 'REQUEST');
        $rescheduled = $generator->forAppointment($appointment, 'REQUEST');

        preg_match('/UID:(.+)/', $booked, $first);
        preg_match('/UID:(.+)/', $rescheduled, $second);

        $this->assertSame($first[1], $second[1]);
        $this->assertStringContainsString("appointment-{$appointment->id}@", $first[1]);
    }

    public function test_method_and_status_match_request_vs_cancel()
    {
        $appointment = Appointment::factory()->create();
        $generator = new IcsGenerator;

        $requested = $generator->forAppointment($appointment, 'REQUEST');
        $cancelled = $generator->forAppointment($appointment, 'CANCEL');

        $this->assertStringContainsString('METHOD:REQUEST', $requested);
        $this->assertStringContainsString('STATUS:CONFIRMED', $requested);
        $this->assertStringContainsString('METHOD:CANCEL', $cancelled);
        $this->assertStringContainsString('STATUS:CANCELLED', $cancelled);
    }

    public function test_a_comma_in_the_business_name_is_escaped_in_summary()
    {
        config(['app.name' => 'Simplified, Booking']);
        $appointment = Appointment::factory()->create(['status' => AppointmentStatus::Confirmed]);

        $ics = (new IcsGenerator)->forAppointment($appointment, 'REQUEST');

        $this->assertStringContainsString('SUMMARY:30-minute meeting with Simplified\\, Booking', $ics);
    }

    public function test_a_configured_business_name_overrides_the_app_name()
    {
        Setting::current()->update(['business_name' => 'Northgate Consulting']);
        $appointment = Appointment::factory()->create(['status' => AppointmentStatus::Confirmed]);

        $ics = (new IcsGenerator)->forAppointment($appointment, 'REQUEST');

        $this->assertStringContainsString('SUMMARY:30-minute meeting with Northgate Consulting', $ics);
    }
}
