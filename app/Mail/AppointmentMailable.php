<?php

namespace App\Mail;

use App\Models\Appointment;
use App\Models\Setting;
use App\Services\IcsGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

abstract class AppointmentMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Appointment $appointment) {}

    /**
     * The appointment's date and time, formatted in the business's timezone.
     */
    protected function formattedWhen(): string
    {
        $start = $this->appointment->start_at->setTimezone(Setting::current()->timezone);

        return sprintf('%s at %s (%s)', $start->format('l, F j, Y'), $start->format('g:i A'), $start->format('T'));
    }

    protected function minutes(): int
    {
        return (int) $this->appointment->start_at->diffInMinutes($this->appointment->end_at);
    }

    protected function businessName(): string
    {
        return Setting::current()->business_name ?: config('app.name');
    }

    /**
     * @return array<int, Attachment>
     */
    protected function icsAttachment(string $method): array
    {
        return [
            Attachment::fromData(fn () => (new IcsGenerator)->forAppointment($this->appointment, $method), 'invitation.ics')
                ->withMime("text/calendar; charset=utf-8; method={$method}"),
        ];
    }
}
