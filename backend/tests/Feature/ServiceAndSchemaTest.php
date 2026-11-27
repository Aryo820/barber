<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\Service;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServiceAndSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_barberflow_config_defines_operational_defaults(): void
    {
        $this->assertSame('Asia/Jakarta', config('barberflow.timezone'));
        $this->assertSame('09:00', config('barberflow.open_time'));
        $this->assertSame('20:30', config('barberflow.last_slot'));
        $this->assertSame(30, config('barberflow.slot_interval_minutes'));
        $this->assertSame(30, config('barberflow.priority_window_minutes'));
        $this->assertSame(7, config('barberflow.booking_max_days_ahead'));
    }

    public function test_service_persists_without_duration_and_links_to_appointment(): void
    {
        $this->assertFalse(Schema::hasColumn('services', 'duration'));
        $this->assertFalse(Schema::hasColumn('services', 'duration_minutes'));

        $service = Service::create([
            'name' => 'Regular Haircut',
            'price' => 35000,
            'is_active' => true,
        ]);
        $service->refresh();

        $customer = Customer::create([
            'name' => 'Andi Pratama',
            'phone_number' => '+6281234567891',
        ]);

        $barber = Barber::create([
            'name' => 'Budi',
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'service_type' => 'Regular Haircut',
            'scheduled_time' => Carbon::parse('2026-11-28 09:00:00'),
            'queue_position' => 1,
            'type' => 'online',
        ]);
        $appointment->refresh();

        $this->assertSame(35000, $service->price);
        $this->assertTrue($service->is_active === true);
        $this->assertSame($service->id, $appointment->service->id);
        $this->assertCount(1, $service->appointments);
        $this->assertSame($appointment->id, $service->appointments->first()->id);
    }

    public function test_deleting_service_nullifies_service_id_and_preserves_service_type_snapshot(): void
    {
        $service = Service::create([
            'name' => 'Regular Haircut',
            'price' => 35000,
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Siti Rahma',
            'phone_number' => '+6281234567892',
        ]);

        $barber = Barber::create([
            'name' => 'Raka',
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'service_type' => 'Regular Haircut',
            'scheduled_time' => Carbon::parse('2026-11-28 09:30:00'),
            'queue_position' => 1,
            'type' => 'online',
        ]);

        $service->delete();
        $appointment->refresh();

        $this->assertNull($appointment->service_id);
        $this->assertSame('Regular Haircut', $appointment->service_type);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'service_id' => null,
            'service_type' => 'Regular Haircut',
        ]);
    }

    public function test_partial_unique_index_blocks_duplicate_active_online_slot_but_allows_rebooking_after_cancel(): void
    {
        $customerOne = Customer::create([
            'name' => 'Customer One',
            'phone_number' => '+6281234567893',
        ]);
        $customerTwo = Customer::create([
            'name' => 'Customer Two',
            'phone_number' => '+6281234567894',
        ]);
        $barber = Barber::create([
            'name' => 'Dimas',
        ]);
        $slot = Carbon::parse('2026-11-28 10:00:00');

        $first = Appointment::create([
            'customer_id' => $customerOne->id,
            'barber_id' => $barber->id,
            'service_type' => 'Regular Haircut',
            'scheduled_time' => $slot,
            'status' => 'pending',
            'type' => 'online',
        ]);

        $caught = false;
        try {
            Appointment::create([
                'customer_id' => $customerTwo->id,
                'barber_id' => $barber->id,
                'service_type' => 'Regular Haircut',
                'scheduled_time' => $slot,
                'status' => 'confirmed',
                'type' => 'online',
            ]);
        } catch (QueryException) {
            $caught = true;
        }

        $this->assertTrue($caught, 'Expected duplicate active online slot to throw QueryException.');

        $first->update(['status' => 'cancelled']);

        $rebooked = Appointment::create([
            'customer_id' => $customerTwo->id,
            'barber_id' => $barber->id,
            'service_type' => 'Regular Haircut',
            'scheduled_time' => $slot,
            'status' => 'pending',
            'type' => 'online',
        ]);

        $this->assertDatabaseHas('appointments', [
            'id' => $rebooked->id,
            'barber_id' => $barber->id,
            'status' => 'pending',
            'type' => 'online',
        ]);
    }

    public function test_seeders_create_default_barbers_and_services_idempotently(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, Barber::count());
        foreach (['Budi', 'Raka', 'Dimas'] as $barberName) {
            $barber = Barber::where('name', $barberName)->first();
            $this->assertNotNull($barber);
            $this->assertTrue($barber->is_active === true);
            $this->assertSame('idle', $barber->current_status);
        }

        $expectedServices = [
            'Regular Haircut' => 35000,
            'Haircut + Beard Trim' => 50000,
            'Kids Haircut' => 25000,
            'Beard Trim' => 20000,
        ];

        $this->assertSame(4, Service::count());
        foreach ($expectedServices as $name => $price) {
            $service = Service::where('name', $name)->first();
            $this->assertNotNull($service);
            $this->assertSame($price, $service->price);
            $this->assertTrue($service->is_active === true);
        }
    }

    public function test_stage_2_indexes_exist_and_migration_rolls_back_cleanly(): void
    {
        $appointmentIndexes = array_column(Schema::getIndexes('appointments'), 'name');
        $queueLogIndexes = array_column(Schema::getIndexes('queue_logs'), 'name');

        $this->assertContains('appointments_customer_id_index', $appointmentIndexes);
        $this->assertContains('appointments_barber_id_index', $appointmentIndexes);
        $this->assertContains('appointments_service_id_index', $appointmentIndexes);
        $this->assertContains('appointments_active_online_barber_slot_unique', $appointmentIndexes);
        $this->assertContains('queue_logs_appointment_id_index', $queueLogIndexes);

        $migration = require database_path('migrations/2026_11_27_000006_add_stage_2_indexes_and_service_fk.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('appointments', 'service_id'));
        $this->assertNotContains(
            'appointments_active_online_barber_slot_unique',
            array_column(Schema::getIndexes('appointments'), 'name')
        );

        $migration->up();

        $this->assertTrue(Schema::hasColumn('appointments', 'service_id'));
        $this->assertContains(
            'appointments_active_online_barber_slot_unique',
            array_column(Schema::getIndexes('appointments'), 'name')
        );
    }
}
