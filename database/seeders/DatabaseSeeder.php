<?php

namespace Database\Seeders;

use App\Models\AvailabilityRule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedAdmin();
        $this->seedBookingRules();
    }

    /**
     * There is no public sign-up; the only account is created here from .env.
     */
    private function seedAdmin(): void
    {
        $email = config('booking.admin.email');

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Admin {$email} already exists, skipping.");

            return;
        }

        $password = config('booking.admin.password');

        if (blank($password)) {
            if (app()->isProduction()) {
                throw new \RuntimeException('Set ADMIN_PASSWORD in .env before seeding in production.');
            }

            $password = Str::password(16);
            $this->command?->warn("ADMIN_PASSWORD not set; generated password: {$password}");
        }

        User::create([
            'name' => config('booking.admin.name'),
            'email' => $email,
            'password' => $password,
        ])->markEmailAsVerified();

        $this->command?->info("Admin created: {$email}");
    }

    /**
     * Default rules: 30-minute slots, Monday to Friday, 09:00-17:00.
     */
    private function seedBookingRules(): void
    {
        Setting::current()->update([
            'timezone' => config('booking.timezone'),
        ]);

        if (AvailabilityRule::exists()) {
            return;
        }

        foreach (range(1, 5) as $weekday) {
            AvailabilityRule::create([
                'weekday' => $weekday,
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]);
        }
    }
}
