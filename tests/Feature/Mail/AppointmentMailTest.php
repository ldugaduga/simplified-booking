<?php

namespace Tests\Feature\Mail;

use App\Mail\AppointmentBooked;
use App\Mail\AppointmentCancelled;
use App\Mail\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Tests\TestCase;

class AppointmentMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.timezone', 'UTC');
    }

    public function test_appointment_booked_mail()
    {
        $appointment = $this->appointment();

        $mail = (new AppointmentBooked($appointment))->to($appointment->email);

        $mail->assertHasSubject($mail->envelope()->subject);
        $this->assertStringContainsString('30-minute meeting', $mail->envelope()->subject);
        $mail->assertTo($appointment->email);
        $this->assertIcsAttached($mail, 'REQUEST');

        $rendered = $mail->render();
        $this->assertStringContainsString('30-minute meeting', $rendered);
        $this->assertStringContainsString(config('app.name'), $rendered);
        $this->assertStringNotContainsString($appointment->manage_token, $rendered);
    }

    public function test_appointment_rescheduled_mail()
    {
        $appointment = $this->appointment();

        $mail = (new AppointmentRescheduled($appointment))->to($appointment->email);

        $this->assertStringContainsString('moved', $mail->envelope()->subject);
        $mail->assertTo($appointment->email);
        $this->assertIcsAttached($mail, 'REQUEST');

        $rendered = $mail->render();
        $this->assertStringContainsString(config('app.name'), $rendered);
        $this->assertStringNotContainsString($appointment->manage_token, $rendered);
    }

    public function test_appointment_cancelled_mail()
    {
        $appointment = $this->appointment();

        $mail = (new AppointmentCancelled($appointment))->to($appointment->email);

        $this->assertStringContainsString('cancelled', $mail->envelope()->subject);
        $mail->assertTo($appointment->email);
        $this->assertIcsAttached($mail, 'CANCEL');

        $rendered = $mail->render();
        $this->assertStringContainsString(config('app.name'), $rendered);
        $this->assertStringNotContainsString($appointment->manage_token, $rendered);
    }

    public function test_each_mail_shows_the_configured_business_name_and_falls_back_without_it()
    {
        $appointment = $this->appointment();

        $this->assertStringContainsString(config('app.name'), (new AppointmentBooked($appointment))->render());
        $this->assertStringContainsString(config('app.name'), (new AppointmentRescheduled($appointment))->render());
        $this->assertStringContainsString(config('app.name'), (new AppointmentCancelled($appointment))->render());

        Setting::current()->update(['business_name' => 'Northgate Consulting']);

        $this->assertStringContainsString('Northgate Consulting', (new AppointmentBooked($appointment))->render());
        $this->assertStringContainsString('Northgate Consulting', (new AppointmentRescheduled($appointment))->render());
        $this->assertStringContainsString('Northgate Consulting', (new AppointmentCancelled($appointment))->render());
    }

    private function appointment(): Appointment
    {
        $start = CarbonImmutable::parse('2030-01-07 02:00:00', 'UTC');

        return Appointment::factory()->create([
            'start_at' => $start,
            'end_at' => $start->addMinutes(30),
            'email' => 'visitor@example.com',
        ]);
    }

    private function assertIcsAttached(Mailable $mail, string $method): void
    {
        $attachments = $mail->attachments();
        $this->assertCount(1, $attachments);

        $attachment = $attachments[0];
        $this->assertSame('invitation.ics', $attachment->as);
        $this->assertStringContainsString("method={$method}", $attachment->mime);
    }
}
