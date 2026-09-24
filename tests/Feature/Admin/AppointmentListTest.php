<?php

namespace Tests\Feature\Admin;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppointmentListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 2030-01-09, 12:00 in Manila.
        $this->travelTo(CarbonImmutable::parse('2030-01-09 12:00', 'Asia/Manila'));
        Setting::current()->update(['timezone' => 'Asia/Manila']);
        $this->admin = User::factory()->create();
    }

    public function test_guests_are_redirected()
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_tabs_split_and_order_appointments()
    {
        $past = $this->make('2030-01-08 10:00');
        $soon = $this->make('2030-01-10 09:00');
        $later = $this->make('2030-01-11 09:00');
        $ongoing = $this->make('2030-01-09 11:45'); // ends 12:15, still upcoming

        $this->assertSame([$ongoing->id, $soon->id, $later->id], $this->ids());
        $this->assertSame([$past->id], $this->ids(['tab' => 'past']));
        $this->assertSame([$later->id, $soon->id, $ongoing->id, $past->id], $this->ids(['tab' => 'all']));
    }

    public function test_status_date_and_search_filters_narrow_results()
    {
        $a = $this->make('2030-01-10 09:00', ['name' => 'Andrea Lim', 'email' => 'andrea@lim.ph']);
        $b = $this->make('2030-01-10 23:30', ['name' => 'Paolo Cruz', 'email' => 'pcruz@outlook.com']);
        $c = $this->make('2030-01-11 00:30', ['name' => 'Kevin Tan', 'email' => 'kevin@gmail.com', 'status' => AppointmentStatus::Cancelled]);

        $this->assertSame([$c->id], $this->ids(['status' => 'cancelled']));
        $this->assertSame([$a->id, $b->id], $this->ids(['status' => 'confirmed']));
        // Local-day boundary in Manila: 23:30 on the 10th is in, 00:30 on the 11th is out.
        $this->assertSame([$a->id, $b->id], $this->ids(['date' => '2030-01-10']));
        $this->assertSame([$c->id], $this->ids(['date' => '2030-01-11']));
        $this->assertSame([$a->id], $this->ids(['q' => 'ANDREA']));
        $this->assertSame([$b->id], $this->ids(['q' => 'outlook']));
    }

    public function test_search_wildcards_match_literally()
    {
        $plain = $this->make('2030-01-10 09:00', ['name' => 'Ana Reyes', 'email' => 'ana@example.com']);
        $odd = $this->make('2030-01-10 10:00', ['name' => 'Ben 100% Sure', 'email' => 'ben_sure@example.com']);

        $this->assertSame([$odd->id], $this->ids(['q' => '_']));
        $this->assertSame([$odd->id], $this->ids(['q' => '100%']));
        $this->assertSame([], $this->ids(['q' => '!']));
        $this->assertSame([$plain->id, $odd->id], $this->ids(['q' => 'example']));
    }

    public function test_results_are_paginated_and_keep_the_query_string()
    {
        foreach (range(0, 24) as $i) {
            $this->make(CarbonImmutable::parse('2030-01-10 09:00', 'Asia/Manila')->addDays($i)->format('Y-m-d H:i'));
        }

        $this->actingAs($this->admin)->get('/admin?status=confirmed')
            ->assertInertia(fn (Assert $page) => $page
                ->has('appointments.data', 20)
                ->where('appointments.total', 25)
                ->where('appointments.next_page_url', fn ($url) => str_contains($url, 'status=confirmed') && str_contains($url, 'page=2')));

        $this->actingAs($this->admin)->get('/admin?status=confirmed&page=2')
            ->assertInertia(fn (Assert $page) => $page->has('appointments.data', 5));
    }

    public function test_counts_respect_local_boundaries()
    {
        $this->make('2030-01-09 09:00');                 // today
        $this->make('2030-01-09 23:30');                 // today, late
        $this->make('2030-01-10 00:30');                 // tomorrow, this week
        $this->make('2030-01-07 09:00');                 // Monday, this week
        $this->make('2030-01-13 23:30');                 // Sunday, this week
        $this->make('2030-01-14 09:00');                 // next Monday
        $this->make('2030-01-06 23:30');                 // last Sunday
        $this->make('2030-01-09 15:00', ['status' => AppointmentStatus::Cancelled]);

        $old = $this->make('2030-01-12 10:00', ['status' => AppointmentStatus::Cancelled]);
        $old->forceFill(['updated_at' => CarbonImmutable::now()->subDays(31)])->saveQuietly();

        $this->actingAs($this->admin)->get('/admin')
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.today', 2)
                ->where('counts.thisWeek', 5)
                ->where('counts.cancelledLast30Days', 1));
    }

    public function test_invalid_filters_fall_back_to_defaults()
    {
        $this->make('2030-01-10 09:00');

        $this->actingAs($this->admin)->get('/admin?tab=nope&status=weird&date=2030-02-31&q=%20%20')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['tab' => 'upcoming', 'status' => null, 'date' => null, 'q' => null])
                ->has('appointments.data', 1));
    }

    public function test_private_fields_never_reach_the_page()
    {
        $appointment = $this->make('2030-01-10 09:00', ['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin)->get('/admin');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('appointments.data.0.created_by_admin', true)
            ->missing('appointments.data.0.manage_token')
            ->missing('appointments.data.0.slot_lock'));
        $this->assertStringNotContainsString($appointment->manage_token, $response->getContent());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function make(string $localStart, array $attributes = []): Appointment
    {
        $start = CarbonImmutable::parse($localStart, 'Asia/Manila')->utc();

        return Appointment::factory()->create(array_merge([
            'start_at' => $start,
            'end_at' => $start->addMinutes(30),
        ], $attributes));
    }

    /**
     * @param  array<string, string>  $query
     * @return list<int>
     */
    private function ids(array $query = []): array
    {
        $ids = [];
        $this->actingAs($this->admin)
            ->get('/admin?'.http_build_query($query))
            ->assertInertia(function (Assert $page) use (&$ids) {
                $ids = array_column($page->toArray()['props']['appointments']['data'], 'id');
            });

        return $ids;
    }
}
