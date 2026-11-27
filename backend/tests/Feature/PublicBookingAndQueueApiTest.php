<?php

namespace Tests\Feature;

use App\Models\Barber;
use App\Models\Service;
use App\Services\QueueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicBookingAndQueueApiTest extends TestCase
{
    use RefreshDatabase;

    private Barber $activeBarberOne;
    private Barber $activeBarberTwo;
    private Barber $inactiveBarber;
    private Service $activeServiceOne;
    private Service $activeServiceTwo;
    private Service $inactiveService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-28 10:00:00', 'Asia/Jakarta'));

        $this->activeBarberOne = Barber::create([
            'name' => 'Budi',
            'is_active' => true,
            'current_status' => 'idle',
        ]);

        $this->inactiveBarber = Barber::create([
            'name' => 'Dedi',
            'is_active' => false,
            'current_status' => 'break',
        ]);

        $this->activeBarberTwo = Barber::create([
            'name' => 'Raka',
            'is_active' => true,
            'current_status' => 'busy',
        ]);

        $this->activeServiceOne = Service::create([
            'name' => 'Regular Haircut',
            'price' => 35000,
            'is_active' => true,
        ]);

        $this->inactiveService = Service::create([
            'name' => 'Archived Treatment',
            'price' => 99000,
            'is_active' => false,
        ]);

        $this->activeServiceTwo = Service::create([
            'name' => 'Haircut + Shave',
            'price' => 50000,
            'is_active' => true,
        ]);
    }

    public function test_get_services_returns_only_active_services_without_duration(): void
    {
        $response = $this->getJson('/api/services');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => $this->activeServiceOne->id,
                        'name' => 'Regular Haircut',
                        'price' => 35000,
                    ],
                    [
                        'id' => $this->activeServiceTwo->id,
                        'name' => 'Haircut + Shave',
                        'price' => 50000,
                    ],
                ],
            ]);

        foreach ($response->json('data') as $service) {
            $this->assertArrayNotHasKey('duration', $service);
            $this->assertArrayNotHasKey('duration_minutes', $service);
        }
    }

    public function test_get_barbers_returns_only_active_barbers(): void
    {
        $response = $this->getJson('/api/barbers');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => $this->activeBarberOne->id,
                        'name' => 'Budi',
                        'current_status' => 'idle',
                    ],
                    [
                        'id' => $this->activeBarberTwo->id,
                        'name' => 'Raka',
                        'current_status' => 'busy',
                    ],
                ],
            ]);
    }

    public function test_get_slots_returns_24_slots_with_past_and_booked_flags_and_validates_inputs(): void
    {
        app(QueueService::class)->createOnlineBooking([
            'name' => 'Andi',
            'phone_number' => '081234567890',
            'barber_id' => $this->activeBarberOne->id,
            'service_id' => $this->activeServiceOne->id,
            'date' => '2026-11-28',
            'slot' => '11:00',
        ]);

        $response = $this->getJson("/api/slots?barber_id={$this->activeBarberOne->id}&date=2026-11-28");

        $response
            ->assertOk()
            ->assertJsonPath('barber', [
                'id' => $this->activeBarberOne->id,
                'name' => 'Budi',
                'current_status' => 'idle',
            ])
            ->assertJsonPath('date', '2026-11-28')
            ->assertJsonPath('timezone', 'Asia/Jakarta')
            ->assertJsonCount(24, 'slots');

        $slots = collect($response->json('slots'))->keyBy('slot');
        $this->assertSame('09:00', $response->json('slots.0.slot'));
        $this->assertSame('20:30', $response->json('slots.23.slot'));

        $this->assertFalse($slots['09:00']['available']);
        $this->assertSame('past', $slots['09:00']['reason']);
        $this->assertFalse($slots['10:00']['available']);
        $this->assertSame('past', $slots['10:00']['reason']);

        $this->assertTrue($slots['10:30']['available']);
        $this->assertNull($slots['10:30']['reason']);

        $this->assertFalse($slots['11:00']['available']);
        $this->assertSame('booked', $slots['11:00']['reason']);

        // Past date -> 422
        $this->getJson("/api/slots?barber_id={$this->activeBarberOne->id}&date=2026-11-27")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        // > H+7 date -> 422
        $this->getJson("/api/slots?barber_id={$this->activeBarberOne->id}&date=2026-12-06")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        // Inactive barber -> 422
        $this->getJson("/api/slots?barber_id={$this->inactiveBarber->id}&date=2026-11-28")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['barber_id']);
    }

    public function test_post_bookings_creates_booking_and_handles_conflict_and_validation_errors(): void
    {
        $payload = [
            'name' => 'Bambang',
            'phone_number' => '0812-3456-7890',
            'barber_id' => $this->activeBarberOne->id,
            'service_id' => $this->activeServiceOne->id,
            'date' => '2026-11-28',
            'slot' => '11:30',
        ];

        $response = $this->postJson('/api/bookings', $payload);

        $response->assertCreated();
        $id = $response->json('data.id');

        $response
            ->assertJsonPath('data.ticket_code', "BK-{$id}")
            ->assertJsonPath('data.type', 'online')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.date', '2026-11-28')
            ->assertJsonPath('data.slot', '11:30')
            ->assertJsonPath('data.barber', [
                'id' => $this->activeBarberOne->id,
                'name' => 'Budi',
                'current_status' => 'idle',
            ])
            ->assertJsonPath('data.service', [
                'id' => $this->activeServiceOne->id,
                'name' => 'Regular Haircut',
                'price' => 35000,
            ])
            ->assertJsonPath('data.customer.name', 'Bambang');

        $this->assertArrayNotHasKey('phone_number', $response->json('data.customer'));
        $this->assertArrayNotHasKey('wa_id', $response->json('data.customer'));

        // Duplicate barber + slot -> 409
        $this->postJson('/api/bookings', [
            ...$payload,
            'name' => 'Pelanggan Lain',
            'phone_number' => '081399998888',
        ])
            ->assertStatus(409)
            ->assertJsonStructure(['message']);

        // Invalid phone -> 422
        $this->postJson('/api/bookings', [
            ...$payload,
            'phone_number' => '123',
            'slot' => '12:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);

        // Invalid slot interval -> 422
        $this->postJson('/api/bookings', [
            ...$payload,
            'slot' => '09:15',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slot']);

        // Inactive barber -> 422
        $this->postJson('/api/bookings', [
            ...$payload,
            'barber_id' => $this->inactiveBarber->id,
            'slot' => '12:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['barber_id']);

        // Inactive service -> 422
        $this->postJson('/api/bookings', [
            ...$payload,
            'service_id' => $this->inactiveService->id,
            'slot' => '12:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_post_queue_join_accepts_nullable_barber_and_rejects_duplicate_active_walk_in(): void
    {
        $response = $this->postJson('/api/queue/join', [
            'name' => 'Walk-in Guest',
            'phone_number' => '0812-8888-7777',
            'service_id' => $this->activeServiceTwo->id,
            'barber_id' => null,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.ticket_code', 'A-1')
            ->assertJsonPath('data.type', 'walk_in')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.queue_position', 1)
            ->assertJsonPath('data.barber', null)
            ->assertJsonPath('data.service.id', $this->activeServiceTwo->id);

        $this->assertArrayNotHasKey('phone_number', $response->json('data.customer'));
        $this->assertArrayNotHasKey('wa_id', $response->json('data.customer'));

        // Duplicate active walk-in same phone (different format, same normalized number) -> 409
        $this->postJson('/api/queue/join', [
            'name' => 'Walk-in Guest',
            'phone_number' => '+6281288887777',
            'service_id' => $this->activeServiceOne->id,
        ])
            ->assertStatus(409)
            ->assertJsonStructure(['message']);
    }

    public function test_get_queue_returns_hybrid_ordered_queue_without_exposing_phone_or_wa_id(): void
    {
        $queueService = app(QueueService::class);

        // Tier 4: future online booking beyond 30-min priority window (11:30 when now is 10:00)
        $futureOnline = $queueService->createOnlineBooking([
            'name' => 'Future Online',
            'phone_number' => '081111111111',
            'barber_id' => $this->activeBarberOne->id,
            'service_id' => $this->activeServiceOne->id,
            'date' => '2026-11-28',
            'slot' => '11:30',
        ]);

        // Tier 3: walk-in pending
        $walkIn = $queueService->joinWalkIn([
            'name' => 'Walk-in Customer',
            'phone_number' => '081222222222',
            'service_id' => $this->activeServiceOne->id,
            'barber_id' => null,
        ]);

        // Tier 2: online booking within 30-min priority window (10:30 when now is 10:00)
        $soonOnline = $queueService->createOnlineBooking([
            'name' => 'Soon Online',
            'phone_number' => '081333333333',
            'barber_id' => $this->activeBarberTwo->id,
            'service_id' => $this->activeServiceTwo->id,
            'date' => '2026-11-28',
            'slot' => '10:30',
        ]);

        // Tier 1: confirmed appointment
        $confirmedWalkIn = $queueService->joinWalkIn([
            'name' => 'Confirmed Customer',
            'phone_number' => '081444444444',
            'service_id' => $this->activeServiceOne->id,
            'barber_id' => $this->activeBarberOne->id,
        ]);
        $queueService->transitionStatus($confirmedWalkIn, 'confirmed', 'admin:1');

        $response = $this->getJson('/api/queue');

        $response
            ->assertOk()
            ->assertJsonPath('date', '2026-11-28')
            ->assertJsonPath('timezone', 'Asia/Jakarta')
            ->assertJsonPath('summary', [
                'pending' => 3,
                'confirmed' => 1,
                'served_today' => 0,
                'active_total' => 4,
            ]);

        $orderedIds = collect($response->json('queue'))->pluck('id')->all();
        $this->assertSame(
            [$confirmedWalkIn->id, $soonOnline->id, $walkIn->id, $futureOnline->id],
            $orderedIds
        );

        // Privacy check: raw JSON must never contain phone numbers, phone_number key, or wa_id
        $rawContent = $response->getContent();
        $this->assertIsString($rawContent);
        $this->assertStringNotContainsString('phone_number', $rawContent);
        $this->assertStringNotContainsString('wa_id', $rawContent);
        $this->assertStringNotContainsString('628111111111', $rawContent);
        $this->assertStringNotContainsString('628122222222', $rawContent);
        $this->assertStringNotContainsString('628133333333', $rawContent);
        $this->assertStringNotContainsString('628144444444', $rawContent);
        $this->assertStringNotContainsString('@c.us', $rawContent);
    }

    public function test_removed_default_api_user_route_returns_404(): void
    {
        $this->getJson('/api/user')->assertNotFound();
    }
}
