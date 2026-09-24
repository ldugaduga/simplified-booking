<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'timezone',
        'slot_minutes',
        'buffer_minutes',
        'min_notice_hours',
        'max_days_ahead',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'min_notice_hours' => 'integer',
            'max_days_ahead' => 'integer',
        ];
    }

    /**
     * The booking rules live in a single row; create it with defaults if missing.
     */
    public static function current(): self
    {
        $settings = static::query()->firstOrCreate(['id' => 1]);

        // A freshly inserted row only holds the id; reload it to pick up the column defaults.
        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }
}
