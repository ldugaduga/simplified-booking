<?php

namespace Tests\Feature;

use App\Models\AvailabilityRule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_admin_and_default_rules_and_is_idempotent()
    {
        config([
            'booking.admin.email' => 'owner@example.com',
            'booking.admin.password' => 'secret-password',
        ]);

        $this->seed();
        $this->seed();

        $admin = User::sole();
        $this->assertSame('owner@example.com', $admin->email);
        $this->assertTrue(Hash::check('secret-password', $admin->password));
        $this->assertNotNull($admin->email_verified_at);

        $this->assertSame(1, Setting::count());
        $this->assertSame(30, Setting::current()->slot_minutes);
        $this->assertEquals([1, 2, 3, 4, 5], AvailabilityRule::orderBy('weekday')->pluck('weekday')->all());
    }
}
