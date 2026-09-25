<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AppointmentBooked extends AppointmentMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're booked: {$this->minutes()}-minute meeting on {$this->formattedWhen()}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.appointment-booked',
            with: ['when' => $this->formattedWhen(), 'minutes' => $this->minutes()],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->icsAttachment('REQUEST');
    }
}
