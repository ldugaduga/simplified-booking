<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login()
    {
        $this->get('/settings/branding')->assertRedirect('/login');
        $this->patch('/settings/branding', ['business_name' => 'Acme'])->assertRedirect('/login');
    }

    public function test_admin_can_view_and_save_branding()
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->patch('/settings/branding', [
                'business_name' => 'Northgate Consulting',
                'business_description' => 'Bookkeeping and tax advice for small businesses.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $settings = Setting::current();
        $this->assertSame('Northgate Consulting', $settings->business_name);
        $this->assertSame('Bookkeeping and tax advice for small businesses.', $settings->business_description);

        $this->actingAs($admin)
            ->get('/settings/branding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Branding')
                ->where('businessName', 'Northgate Consulting')
                ->where('businessDescription', 'Bookkeeping and tax advice for small businesses.'));
    }

    public function test_either_field_can_be_cleared_back_to_null()
    {
        $admin = User::factory()->create();
        Setting::current()->update(['business_name' => 'Northgate', 'business_description' => 'Something']);

        $this->actingAs($admin)
            ->patch('/settings/branding', ['business_name' => '', 'business_description' => ''])
            ->assertSessionHasNoErrors();

        $settings = Setting::current()->fresh();
        $this->assertNull($settings->business_name);
        $this->assertNull($settings->business_description);
    }

    public function test_overlong_values_are_rejected()
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->patch('/settings/branding', ['business_name' => str_repeat('a', 256)])
            ->assertSessionHasErrors('business_name');

        $this->actingAs($admin)
            ->patch('/settings/branding', ['business_description' => str_repeat('a', 501)])
            ->assertSessionHasErrors('business_description');

        $this->assertNull(Setting::current()->business_name);
    }
}
