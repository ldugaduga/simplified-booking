<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_appointments_get_a_private_manage_token()
    {
        $appointment = Appointment::factory()->create();

        $this->assertSame(64, strlen($appointment->manage_token));
        $this->assertArrayNotHasKey('manage_token', $appointment->toArray());
    }

    public function test_two_active_appointments_cannot_share_a_slot()
    {
        $first = Appointment::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Appointment::factory()->create([
            'start_at' => $first->start_at,
            'end_at' => $first->end_at,
        ]);
    }

    public function test_cancelling_frees_the_slot()
    {
        $first = Appointment::factory()->create();

        $first->update(['status' => AppointmentStatus::Cancelled]);

        $second = Appointment::factory()->create([
            'start_at' => $first->start_at,
            'end_at' => $first->end_at,
        ]);

        $this->assertNull($first->fresh()->slot_lock);
        $this->assertTrue($second->start_at->equalTo($second->fresh()->slot_lock));
    }

    public function test_overlapping_scope_finds_clashing_appointments()
    {
        $appointment = Appointment::factory()->create([
            'start_at' => '2030-01-07 10:00:00',
            'end_at' => '2030-01-07 10:30:00',
        ]);

        $clash = Appointment::active()
            ->overlapping(new \DateTime('2030-01-07 10:15:00'), new \DateTime('2030-01-07 10:45:00'))
            ->pluck('id');
        $adjacent = Appointment::active()
            ->overlapping(new \DateTime('2030-01-07 10:30:00'), new \DateTime('2030-01-07 11:00:00'))
            ->pluck('id');

        $this->assertEquals([$appointment->id], $clash->all());
        $this->assertEmpty($adjacent);
    }
}
