<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return $this === self::Confirmed;
    }
}
