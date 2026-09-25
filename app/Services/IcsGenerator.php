<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Setting;
use Illuminate\Support\Carbon;

class IcsGenerator
{
    /**
     * Build a minimal RFC 5545 calendar invite for one appointment.
     */
    public function forAppointment(Appointment $appointment, string $method): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $status = $method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Simplified Booking//EN',
            "METHOD:{$method}",
            'BEGIN:VEVENT',
            "UID:appointment-{$appointment->id}@{$host}",
            'DTSTAMP:'.Carbon::now('UTC')->format('Ymd\THis\Z'),
            'DTSTART:'.$appointment->start_at->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$appointment->end_at->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escape($this->summary($appointment)),
            "STATUS:{$status}",
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    private function summary(Appointment $appointment): string
    {
        $minutes = (int) $appointment->start_at->diffInMinutes($appointment->end_at);
        $businessName = Setting::current()->business_name ?: config('app.name');

        return "{$minutes}-minute meeting with {$businessName}";
    }

    /**
     * Escape the characters RFC 5545 reserves in TEXT values.
     */
    private function escape(string $value): string
    {
        return addcslashes($value, "\;,\n");
    }
}
