<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOnlyAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled()
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_cannot_delete_their_own_account()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertMethodNotAllowed();

        $this->assertNotNull($user->fresh());
    }

    public function test_public_booking_page_is_open_to_guests()
    {
        $this->get('/')->assertOk();
    }
}
