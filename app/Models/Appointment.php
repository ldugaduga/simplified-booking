<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'start_at',
        'end_at',
        'status',
        'name',
        'email',
        'notes',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'manage_token',
        'slot_lock',
    ];

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment) {
            $appointment->manage_token ??= Str::random(64);
        });

        static::saving(function (Appointment $appointment) {
            $appointment->slot_lock = $appointment->status->isActive()
                ? $appointment->start_at
                : null;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'slot_lock' => 'immutable_datetime',
            'status' => AppointmentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', AppointmentStatus::Confirmed);
    }

    /**
     * Appointments overlapping the given UTC range.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): void
    {
        $query->where('start_at', '<', $end)->where('end_at', '>', $start);
    }
}
