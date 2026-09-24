<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::instance(fake()->dateTimeBetween('+1 day', '+30 days'))
            ->setTime(fake()->numberBetween(9, 16), fake()->randomElement([0, 30]));

        return [
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => AppointmentStatus::Cancelled]);
    }
}
