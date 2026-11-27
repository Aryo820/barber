<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\QueueLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_entities_and_relations_persist_correctly(): void
    {
        $customer = Customer::create([
            'name' => 'Budi Santoso',
            'phone_number' => '+6281234567890',
            'wa_id' => '6281234567890@c.us',
        ]);

        $barber = Barber::create([
            'name' => 'Andi',
        ]);
        $barber->refresh();

        $scheduledAt = Carbon::parse('2026-11-28 10:00:00');

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'barber_id' => $barber->id,
            'service_type' => 'haircut',
            'scheduled_time' => $scheduledAt,
            'queue_position' => 1,
            'type' => 'online',
        ]);
        $appointment->refresh();

        $log = QueueLog::create([
            'appointment_id' => $appointment->id,
            'action_type' => 'join',
            'actor_id' => 'system',
        ]);
        $log->refresh();

        $this->assertTrue($barber->is_active === true);
        $this->assertSame('idle', $barber->current_status);
        $this->assertSame('pending', $appointment->status);
        $this->assertSame(1, $appointment->queue_position);
        $this->assertInstanceOf(Carbon::class, $appointment->scheduled_time);
        $this->assertInstanceOf(Carbon::class, $log->timestamp);

        $this->assertCount(1, $customer->appointments);
        $this->assertSame($appointment->id, $customer->appointments->first()->id);

        $this->assertCount(1, $barber->appointments);
        $this->assertSame($appointment->id, $barber->appointments->first()->id);

        $this->assertSame($customer->id, $appointment->customer->id);
        $this->assertSame($barber->id, $appointment->barber->id);

        $this->assertCount(1, $appointment->queueLogs);
        $this->assertSame($log->id, $appointment->queueLogs->first()->id);
        $this->assertSame($appointment->id, $log->appointment->id);
    }

    public function test_customer_phone_number_must_be_unique(): void
    {
        Customer::create([
            'name' => 'Budi Santoso',
            'phone_number' => '+6281234567890',
        ]);

        $this->expectException(QueryException::class);

        Customer::create([
            'name' => 'Budi Duplicate',
            'phone_number' => '+6281234567890',
        ]);
    }

    public function test_walk_in_appointment_allows_nullable_barber_and_scheduled_time(): void
    {
        $customer = Customer::create([
            'name' => 'Siti Aminah',
            'phone_number' => '+6281298765432',
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'barber_id' => null,
            'service_type' => 'haircut',
            'scheduled_time' => null,
            'queue_position' => 1,
            'type' => 'walk_in',
        ]);
        $appointment->refresh();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'customer_id' => $customer->id,
            'barber_id' => null,
            'scheduled_time' => null,
            'queue_position' => 1,
            'status' => 'pending',
            'type' => 'walk_in',
        ]);
        $this->assertNull($appointment->barber_id);
        $this->assertNull($appointment->scheduled_time);
        $this->assertSame(1, $appointment->queue_position);
    }

    public function test_deleting_barber_nullifies_appointment_barber_id(): void
    {
        $customer = Customer::create([
            'name' => 'Rudi Hartono',
            'phone_number' => '+6281112223334',
        ]);

        $barber = Barber::create([
            'name' => 'Dedi',
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'barber_id' => $barber->id,
            'service_type' => 'shave',
            'scheduled_time' => Carbon::parse('2026-11-28 11:00:00'),
            'queue_position' => 2,
            'type' => 'online',
        ]);

        $barber->delete();
        $appointment->refresh();

        $this->assertNull($appointment->barber_id);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'barber_id' => null,
        ]);
    }
}
