<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HandleInertiaRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_name_falls_back_to_the_app_name()
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('businessName', config('app.name')));
    }

    public function test_business_name_uses_the_configured_branding()
    {
        Setting::current()->update(['business_name' => 'Northgate Consulting']);

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('businessName', 'Northgate Consulting'));
    }
}
