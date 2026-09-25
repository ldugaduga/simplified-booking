<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AppointmentCancelled extends AppointmentMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your appointment on {$this->formattedWhen()} has been cancelled",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.appointment-cancelled',
            with: ['when' => $this->formattedWhen(), 'minutes' => $this->minutes(), 'businessName' => $this->businessName()],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->icsAttachment('CANCEL');
    }
}
