<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_creates_the_row_with_column_defaults()
    {
        $settings = Setting::current();

        $this->assertSame('UTC', $settings->timezone);
        $this->assertSame(30, $settings->slot_minutes);
        $this->assertSame(0, $settings->buffer_minutes);
        $this->assertSame(4, $settings->min_notice_hours);
        $this->assertSame(60, $settings->max_days_ahead);
        $this->assertSame(1, Setting::count());
        $this->assertSame($settings->id, Setting::current()->id);
    }
}
