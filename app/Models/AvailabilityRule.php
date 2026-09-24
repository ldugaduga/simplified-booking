<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvailabilityRule extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'weekday',
        'start_time',
        'end_time',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }
}
