<?php

namespace Tests\Feature\Booking;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BookingPageTest extends TestCase
{
    use RefreshDatabase;

    /** Props every Inertia page receives from HandleInertiaRequests. */
    private const SHARED_PROPS = ['name', 'quote', 'auth', 'ziggy', 'errors'];

    public function test_home_renders_the_booking_page_with_only_its_props()
    {
        Setting::current()->update(['slot_minutes' => 45, 'max_days_ahead' => 30]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->component('booking/Book')
                    ->where('businessName', config('app.name'))
                    ->where('slotMinutes', 45)
                    ->where('maxDaysAhead', 30);

                $pageProps = array_values(array_diff(array_keys($page->toArray()['props']), self::SHARED_PROPS));
                sort($pageProps);

                $this->assertSame(['businessName', 'maxDaysAhead', 'slotMinutes'], $pageProps);
            });
    }
}
