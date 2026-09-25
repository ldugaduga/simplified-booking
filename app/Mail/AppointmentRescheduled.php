<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AppointmentRescheduled extends AppointmentMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your appointment has moved to {$this->formattedWhen()}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.appointment-rescheduled',
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
