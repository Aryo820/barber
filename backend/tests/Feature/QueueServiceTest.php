<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\QueueLog;
use App\Models\Service;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class QueueServiceTest extends TestCase
{
    use RefreshDatabase;

    private QueueService $queueService;
    private Barber $barberBudi;
    private Barber $barberRaka;
    private Service $serviceHaircut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queueService = app(QueueService::class);

        $this->barberBudi = Barber::create([
            'name' => 'Budi',
            'is_active' => true,
            'current_status' => 'idle',
        ]);

        $this->barberRaka = Barber::create([
            'name' => 'Raka',
            'is_active' => true,
            'current_status' => 'idle',
        ]);

        $this->serviceHaircut = Service::create([
            'name' => 'Regular Haircut',
            'price' => 35000,
            'is_active' => true,
        ]);
    }

    public function test_slots_for_marks_past_and_booked_slots_and_frees_cancelled_slot(): void
    {
        $slotTimes = $this->queueService->generateDailySlotTimes();
        $this->assertCount(24, $slotTimes);
        $this->assertSame('09:00', $slotTimes[0]);
        $this->assertSame('20:30', $slotTimes[23]);

        $nowWib = Carbon::parse('2026-11-28 10:00:00', 'Asia/Jakarta');

        // Reject out-of-range dates (yesterday and H+8)
        foreach (['2026-11-27', '2026-12-06'] as $invalidDate) {
            try {
                $this->queueService->slotsFor($this->barberBudi, $invalidDate, $nowWib);
                $this->fail("Expected ValidationException for out-of-range date {$invalidDate}.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('date', $e->errors());
            }
        }

        // Book 11:00 slot
        $booking = $this->queueService->createOnlineBooking([
            'name' => 'Andi',
            'phone_number' => '0812-3456-7890',
            'barber_id' => $this->barberBudi->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-28',
            'slot' => '11:00',
        ], $nowWib);

        $slots = collect($this->queueService->slotsFor($this->barberBudi, '2026-11-28', $nowWib))
            ->keyBy('slot');

        $this->assertCount(24, $slots);
        $this->assertFalse($slots['09:00']['available']);
        $this->assertSame('past', $slots['09:00']['reason']);
        $this->assertFalse($slots['10:00']['available']);
        $this->assertSame('past', $slots['10:00']['reason']);

        $this->assertTrue($slots['10:30']['available']);
        $this->assertNull($slots['10:30']['reason']);
        $this->assertSame('2026-11-28T10:30:00+07:00', $slots['10:30']['scheduled_time']);

        $this->assertFalse($slots['11:00']['available']);
        $this->assertSame('booked', $slots['11:00']['reason']);

        // Cancel booking -> slot immediately becomes available and re-bookable
        $this->queueService->transitionStatus($booking, 'cancelled', 'admin:1');

        $slotsAfterCancel = collect($this->queueService->slotsFor($this->barberBudi, '2026-11-28', $nowWib))
            ->keyBy('slot');
        $this->assertTrue($slotsAfterCancel['11:00']['available']);
        $this->assertNull($slotsAfterCancel['11:00']['reason']);

        $rebooked = $this->queueService->createOnlineBooking([
            'name' => 'Siti',
            'phone_number' => '081299998888',
            'barber_id' => $this->barberBudi->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-28',
            'slot' => '11:00',
        ], $nowWib);

        $this->assertSame('pending', $rebooked->status);
        $this->assertSame($this->barberBudi->id, $rebooked->barber_id);
    }

    public function test_online_booking_upserts_customer_creates_join_log_and_rejects_conflict(): void
    {
        $nowWib = Carbon::parse('2026-11-28 09:00:00', 'Asia/Jakarta');

        $inactiveBarber = Barber::create(['name' => 'Off Barber', 'is_active' => false]);
        $inactiveService = Service::create(['name' => 'Old Service', 'price' => 10000, 'is_active' => false]);

        // Invalid phone
        try {
            $this->queueService->createOnlineBooking([
                'name' => 'Bad Phone',
                'phone_number' => '12345',
                'barber_id' => $this->barberBudi->id,
                'service_id' => $this->serviceHaircut->id,
                'date' => '2026-11-28',
                'slot' => '10:00',
            ], $nowWib);
            $this->fail('Expected ValidationException on phone_number.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone_number', $e->errors());
        }

        // Inactive barber & service & past slot
        try {
            $this->queueService->createOnlineBooking([
                'name' => 'Andi',
                'phone_number' => '081234567890',
                'barber_id' => $inactiveBarber->id,
                'service_id' => $this->serviceHaircut->id,
                'date' => '2026-11-28',
                'slot' => '10:00',
            ], $nowWib);
            $this->fail('Expected ValidationException on barber_id.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('barber_id', $e->errors());
        }

        try {
            $this->queueService->createOnlineBooking([
                'name' => 'Andi',
                'phone_number' => '081234567890',
                'barber_id' => $this->barberBudi->id,
                'service_id' => $inactiveService->id,
                'date' => '2026-11-28',
                'slot' => '10:00',
            ], $nowWib);
            $this->fail('Expected ValidationException on service_id.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('service_id', $e->errors());
        }

        try {
            $this->queueService->createOnlineBooking([
                'name' => 'Andi',
                'phone_number' => '081234567890',
                'barber_id' => $this->barberBudi->id,
                'service_id' => $this->serviceHaircut->id,
                'date' => '2026-11-28',
                'slot' => '09:00', // equal to $nowWib -> not strictly in future
            ], $nowWib);
            $this->fail('Expected ValidationException on slot.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slot', $e->errors());
        }

        // Valid booking creates customer + join log
        $first = $this->queueService->createOnlineBooking([
            'name' => 'Andi Pertama',
            'phone_number' => '0812-3456-7890',
            'barber_id' => $this->barberBudi->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-28',
            'slot' => '10:00',
        ], $nowWib);

        $this->assertTrue($first->relationLoaded('customer'));
        $this->assertTrue($first->relationLoaded('barber'));
        $this->assertTrue($first->relationLoaded('service'));
        $this->assertSame('online', $first->type);
        $this->assertSame('pending', $first->status);
        $this->assertNull($first->queue_position);
        $this->assertSame('Regular Haircut', $first->service_type);
        $this->assertSame('6281234567890', $first->customer->phone_number);
        $this->assertSame('6281234567890@s.whatsapp.net', $first->customer->wa_id);
        $this->assertSame('2026-11-28 03:00:00', $first->scheduled_time->copy()->utc()->format('Y-m-d H:i:s'));

        $joinLog = QueueLog::where('appointment_id', $first->id)->first();
        $this->assertNotNull($joinLog);
        $this->assertSame('join', $joinLog->action_type);
        $this->assertNull($joinLog->actor_id);

        // Same customer in +62 format upserts name without duplicating customer row
        $second = $this->queueService->createOnlineBooking([
            'name' => 'Andi Updated',
            'phone_number' => '+62 812 3456 7890',
            'barber_id' => $this->barberRaka->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-28',
            'slot' => '10:00',
        ], $nowWib);

        $this->assertSame(1, Customer::count());
        $this->assertSame($first->customer_id, $second->customer_id);
        $this->assertSame('Andi Updated', $second->customer->name);
        $this->assertSame('6281234567890@s.whatsapp.net', $second->customer->wa_id);

        // Conflicting slot on same barber throws ConflictHttpException (409)
        $this->expectException(ConflictHttpException::class);
        $this->queueService->createOnlineBooking([
            'name' => 'Budi Tamu',
            'phone_number' => '81311112222',
            'barber_id' => $this->barberBudi->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-28',
            'slot' => '10:00',
        ], $nowWib);
    }

    public function test_walk_in_assigns_daily_position_and_blocks_duplicate_active_same_day_only(): void
    {
        $dayOne = Carbon::parse('2026-11-28 09:15:00', 'Asia/Jakarta');

        $walkIn1 = $this->queueService->joinWalkIn([
            'name' => 'Dedi',
            'phone_number' => '0812-0000-0001',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => null,
        ], $dayOne);

        $this->assertSame('walk_in', $walkIn1->type);
        $this->assertSame('pending', $walkIn1->status);
        $this->assertSame(1, $walkIn1->queue_position);
        $this->assertNull($walkIn1->scheduled_time);
        $this->assertNull($walkIn1->barber_id);
        $this->assertDatabaseHas('queue_logs', [
            'appointment_id' => $walkIn1->id,
            'action_type' => 'join',
            'actor_id' => null,
        ]);

        $walkIn2 = $this->queueService->joinWalkIn([
            'name' => 'Eko',
            'phone_number' => '0812-0000-0002',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => $this->barberBudi->id,
        ], $dayOne->copy()->addMinutes(5));

        $this->assertSame(2, $walkIn2->queue_position);

        // Duplicate active walk-in for Dedi same day -> 409 mentioning A-1
        try {
            $this->queueService->joinWalkIn([
                'name' => 'Dedi',
                'phone_number' => '+62 812 0000 0001',
                'service_id' => $this->serviceHaircut->id,
                'barber_id' => null,
            ], $dayOne->copy()->addMinutes(10));
            $this->fail('Expected ConflictHttpException for duplicate active walk-in.');
        } catch (ConflictHttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('A-1', $e->getMessage());
        }

        // Transition Dedi to confirmed -> still blocked
        $walkIn1 = $this->queueService->transitionStatus($walkIn1, 'confirmed', 'admin:1', $this->barberBudi->id);
        try {
            $this->queueService->joinWalkIn([
                'name' => 'Dedi',
                'phone_number' => '081200000001',
                'service_id' => $this->serviceHaircut->id,
                'barber_id' => null,
            ], $dayOne->copy()->addMinutes(15));
            $this->fail('Expected ConflictHttpException while walk-in is confirmed.');
        } catch (ConflictHttpException $e) {
            $this->assertStringContainsString('A-1', $e->getMessage());
        }

        // After served -> Dedi can join again same day and receives A-3
        $this->queueService->transitionStatus($walkIn1, 'served', 'admin:1');
        $walkIn3 = $this->queueService->joinWalkIn([
            'name' => 'Dedi',
            'phone_number' => '081200000001',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => null,
        ], $dayOne->copy()->addHours(2));
        $this->assertSame(3, $walkIn3->queue_position);

        // Next WIB day -> queue position resets to 1 and yesterday's active walk-in (Eko) does not block
        $dayTwo = Carbon::parse('2026-11-29 09:00:00', 'Asia/Jakarta');
        $nextDayWalkIn = $this->queueService->joinWalkIn([
            'name' => 'Eko',
            'phone_number' => '0812-0000-0002',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => null,
        ], $dayTwo);

        $this->assertSame(1, $nextDayWalkIn->queue_position);
    }

    public function test_wib_early_morning_boundary_uses_jakarta_date_not_utc(): void
    {
        // 2026-11-27 23:00:00 WIB = 2026-11-27 16:00:00 UTC
        $lateNov27Wib = Carbon::parse('2026-11-27 23:00:00', 'Asia/Jakarta');
        $nov27WalkIn = $this->queueService->joinWalkIn([
            'name' => 'Late Night Customer',
            'phone_number' => '081211110000',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => null,
        ], $lateNov27Wib);
        $this->assertSame(1, $nov27WalkIn->queue_position);

        // 2026-11-28 02:30:00 WIB = 2026-11-27 19:30:00 UTC (UTC is still Nov 27, WIB is Nov 28)
        $earlyNov28Wib = Carbon::parse('2026-11-28 02:30:00', 'Asia/Jakarta');

        $queue = $this->queueService->todayQueue(false, $earlyNov28Wib);
        $this->assertSame('2026-11-28', $queue['date']);
        $this->assertSame('Asia/Jakarta', $queue['timezone']);
        $this->assertSame([], $queue['queue']);
        $this->assertSame(0, $queue['summary']['active_total']);

        // Same customer can join fresh WIB day 2026-11-28 and gets A-1
        $nov28WalkIn = $this->queueService->joinWalkIn([
            'name' => 'Late Night Customer',
            'phone_number' => '081211110000',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => null,
        ], $earlyNov28Wib);
        $this->assertSame(1, $nov28WalkIn->queue_position);

        $queueAfterJoin = $this->queueService->todayQueue(false, $earlyNov28Wib);
        $this->assertCount(1, $queueAfterJoin['queue']);
        $this->assertSame($nov28WalkIn->id, $queueAfterJoin['queue'][0]['id']);

        // slotsFor treats 2026-11-28 as today WIB and rejects 2026-11-27 as past
        $slotsNov28 = $this->queueService->slotsFor($this->barberBudi, '2026-11-28', $earlyNov28Wib);
        $this->assertCount(24, $slotsNov28);
        $this->assertTrue($slotsNov28[0]['available']);

        $this->expectException(ValidationException::class);
        $this->queueService->slotsFor($this->barberBudi, '2026-11-27', $earlyNov28Wib);
    }

    public function test_today_queue_orders_by_hybrid_priority_and_respects_phone_privacy(): void
    {
        Barber::create(['name' => 'Inactive Barber', 'is_active' => false, 'current_status' => 'offline']);

        $nowWib = Carbon::parse('2026-11-28 10:00:00', 'Asia/Jakarta');

        // Tier 4: online pending beyond 30-min priority window (> 10:30)
        $tier4Late = $this->queueService->createOnlineBooking([
            'name' => 'Tier4 11:30',
            'phone_number' => '081200000101',
            'barber_id' => $this->barberBudi->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-28',
            'slot' => '11:30',
        ], $nowWib->copy()->subHour());

        $tier4Early = $this->queueService->createOnlineBooking([
            'name' => 'Tier4 11:00',
            'phone_number' => '081200000102',
            'barber_id' => $this->barberBudi->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-28',
            'slot' => '11:00',
        ], $nowWib->copy()->subHour());

        // Tier 3: walk-in pending
        $tier3First = $this->queueService->joinWalkIn([
            'name' => 'WalkIn 1',
            'phone_number' => '081200000103',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => null,
        ], $nowWib->copy()->subMinutes(40));

        $tier3Second = $this->queueService->joinWalkIn([
            'name' => 'WalkIn 2',
            'phone_number' => '081200000104',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => null,
        ], $nowWib->copy()->subMinutes(35));

        // Tier 2: online pending within 30-min window (<= 10:30)
        $tier2Boundary = $this->queueService->createOnlineBooking([
            'name' => 'Tier2 10:30',
            'phone_number' => '081200000105',
            'barber_id' => $this->barberBudi->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-28',
            'slot' => '10:30',
        ], $nowWib->copy()->subHour());

        $tier2Soon = $this->queueService->createOnlineBooking([
            'name' => 'Tier2 10:00 Late Arrival',
            'phone_number' => '081200000106',
            'barber_id' => $this->barberRaka->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-28',
            'slot' => '10:00',
        ], $nowWib->copy()->subHour());

        // Tier 1: confirmed appointments (ordered by updated_at ASC, id ASC)
        $tier1First = $this->queueService->joinWalkIn([
            'name' => 'Confirmed First',
            'phone_number' => '081200000107',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => $this->barberBudi->id,
        ], $nowWib->copy()->subMinutes(50));
        $tier1First = $this->queueService->transitionStatus($tier1First, 'confirmed', 'admin:1');
        Appointment::where('id', $tier1First->id)->update([
            'updated_at' => $nowWib->copy()->subMinutes(10)->utc(),
        ]);

        $tier1Second = $this->queueService->createOnlineBooking([
            'name' => 'Confirmed Second',
            'phone_number' => '081200000108',
            'barber_id' => $this->barberRaka->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-28',
            'slot' => '10:30',
        ], $nowWib->copy()->subHour());
        $tier1Second = $this->queueService->transitionStatus($tier1Second, 'confirmed', 'admin:1');
        Appointment::where('id', $tier1Second->id)->update([
            'updated_at' => $nowWib->copy()->subMinutes(2)->utc(),
        ]);

        // Served today (counted in served_today, excluded from active queue)
        $servedToday = $this->queueService->joinWalkIn([
            'name' => 'Served Customer',
            'phone_number' => '081200000109',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => $this->barberBudi->id,
        ], $nowWib->copy()->subMinutes(55));
        $servedToday = $this->queueService->transitionStatus($servedToday, 'confirmed', 'admin:1');
        $this->queueService->transitionStatus($servedToday, 'served', 'admin:1');

        // Cancelled today & tomorrow online booking (excluded from active queue and served_today)
        $cancelledToday = $this->queueService->createOnlineBooking([
            'name' => 'Cancelled Customer',
            'phone_number' => '081200000110',
            'barber_id' => $this->barberRaka->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-28',
            'slot' => '12:00',
        ], $nowWib->copy()->subHour());
        $this->queueService->transitionStatus($cancelledToday, 'cancelled', 'admin:1');

        $this->queueService->createOnlineBooking([
            'name' => 'Tomorrow Customer',
            'phone_number' => '081200000111',
            'barber_id' => $this->barberBudi->id,
            'service_id' => $this->serviceHaircut->id,
            'date' => '2026-11-29',
            'slot' => '09:00',
        ], $nowWib);

        // Public view (includeCustomerPhone = false)
        $publicQueue = $this->queueService->todayQueue(false, $nowWib);

        $this->assertSame('2026-11-28', $publicQueue['date']);
        $this->assertSame('Asia/Jakarta', $publicQueue['timezone']);
        $this->assertCount(2, $publicQueue['barbers']);
        $this->assertSame(['Budi', 'Raka'], array_column($publicQueue['barbers'], 'name'));

        $expectedOrderIds = [
            $tier1First->id,
            $tier1Second->id,
            $tier2Soon->id,
            $tier2Boundary->id,
            $tier3First->id,
            $tier3Second->id,
            $tier4Early->id,
            $tier4Late->id,
        ];
        $this->assertSame($expectedOrderIds, array_column($publicQueue['queue'], 'id'));

        $this->assertSame([
            'pending' => 6,
            'confirmed' => 2,
            'served_today' => 1,
            'active_total' => 8,
        ], $publicQueue['summary']);

        // Verify canonical formatting & phone privacy on public view
        foreach ($publicQueue['queue'] as $item) {
            $this->assertArrayNotHasKey('phone_number', $item['customer']);
            $this->assertArrayNotHasKey('wa_id', $item['customer']);
        }

        $firstWalkInItem = collect($publicQueue['queue'])->firstWhere('id', $tier3First->id);
        $this->assertSame("A-{$tier3First->queue_position}", $firstWalkInItem['ticket_code']);
        $this->assertNull($firstWalkInItem['scheduled_time']);
        $this->assertNull($firstWalkInItem['date']);
        $this->assertNull($firstWalkInItem['slot']);
        $this->assertSame(['id' => $this->serviceHaircut->id, 'name' => 'Regular Haircut', 'price' => 35000], $firstWalkInItem['service']);

        $firstOnlineItem = collect($publicQueue['queue'])->firstWhere('id', $tier2Boundary->id);
        $this->assertSame("BK-{$tier2Boundary->id}", $firstOnlineItem['ticket_code']);
        $this->assertSame('2026-11-28T10:30:00+07:00', $firstOnlineItem['scheduled_time']);
        $this->assertSame('2026-11-28', $firstOnlineItem['date']);
        $this->assertSame('10:30', $firstOnlineItem['slot']);

        // Admin view (includeCustomerPhone = true) includes phone_number but NEVER wa_id
        $adminQueue = $this->queueService->todayQueue(true, $nowWib);
        foreach ($adminQueue['queue'] as $item) {
            $this->assertArrayHasKey('phone_number', $item['customer']);
            $this->assertStringStartsWith('62', $item['customer']['phone_number']);
            $this->assertArrayNotHasKey('wa_id', $item['customer']);
        }
    }

    public function test_transition_status_enforces_state_machine_assigns_barber_and_logs_actor(): void
    {
        $nowWib = Carbon::parse('2026-11-28 09:00:00', 'Asia/Jakarta');
        $inactiveBarber = Barber::create(['name' => 'Off Barber', 'is_active' => false]);

        $walkIn = $this->queueService->joinWalkIn([
            'name' => 'Fajar',
            'phone_number' => '081255556666',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => null,
        ], $nowWib);

        // pending -> served is disallowed
        try {
            $this->queueService->transitionStatus($walkIn, 'served', 'admin:1');
            $this->fail('Expected ValidationException when transitioning pending -> served.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // Inactive barber rejected
        try {
            $this->queueService->transitionStatus($walkIn, 'confirmed', 'admin:1', $inactiveBarber->id);
            $this->fail('Expected ValidationException for inactive barber.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('barber_id', $e->errors());
        }

        // pending -> confirmed assigns barber and logs serve
        $confirmed = $this->queueService->transitionStatus($walkIn, 'confirmed', 'admin:42', $this->barberRaka->id);
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame($this->barberRaka->id, $confirmed->barber_id);
        $this->assertSame('Raka', $confirmed->barber->name);
        $this->assertDatabaseHas('queue_logs', [
            'appointment_id' => $confirmed->id,
            'action_type' => 'serve',
            'actor_id' => 'admin:42',
        ]);

        // confirmed -> served logs serve
        $served = $this->queueService->transitionStatus($confirmed, 'served', 'admin:42');
        $this->assertSame('served', $served->status);
        $this->assertSame(
            2,
            QueueLog::where('appointment_id', $served->id)->where('action_type', 'serve')->count()
        );

        // Terminal state -> any transition rejected
        foreach (['pending', 'confirmed', 'cancelled', 'no_show'] as $next) {
            try {
                $this->queueService->transitionStatus($served, $next, 'admin:42');
                $this->fail("Expected ValidationException transitioning from served to {$next}.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        }

        // confirmed -> no_show logs leave and becomes terminal
        $second = $this->queueService->joinWalkIn([
            'name' => 'Gilang',
            'phone_number' => '081277778888',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => $this->barberBudi->id,
        ], $nowWib);
        $second = $this->queueService->transitionStatus($second, 'confirmed', 'admin:7');
        $noShow = $this->queueService->transitionStatus($second, 'no_show', 'admin:7');

        $this->assertSame('no_show', $noShow->status);
        $this->assertDatabaseHas('queue_logs', [
            'appointment_id' => $noShow->id,
            'action_type' => 'leave',
            'actor_id' => 'admin:7',
        ]);

        $this->expectException(ValidationException::class);
        $this->queueService->transitionStatus($noShow, 'confirmed', 'admin:7');
    }

    public function test_transition_status_rejects_stale_in_memory_appointment_without_extra_logs(): void
    {
        $nowWib = Carbon::parse('2026-11-28 09:00:00', 'Asia/Jakarta');

        // Case 1: stale 'pending' instance after DB row was already cancelled
        $walkIn = $this->queueService->joinWalkIn([
            'name' => 'Hendra',
            'phone_number' => '081288889999',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => null,
        ], $nowWib);

        $stalePending = Appointment::findOrFail($walkIn->id);
        $this->queueService->transitionStatus($walkIn, 'cancelled', 'admin:1');
        $logsAfterCancel = QueueLog::where('appointment_id', $walkIn->id)->count();
        $this->assertSame(2, $logsAfterCancel);
        $this->assertSame('pending', $stalePending->status);

        try {
            $this->queueService->transitionStatus($stalePending, 'confirmed', 'admin:2', $this->barberBudi->id);
            $this->fail('Expected ValidationException for stale pending appointment.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $walkIn->refresh();
        $this->assertSame('cancelled', $walkIn->status);
        $this->assertNull($walkIn->barber_id);
        $this->assertSame(2, QueueLog::where('appointment_id', $walkIn->id)->count());

        // Case 2: stale 'confirmed' instance after DB row was already served (double-click guard)
        $walkIn2 = $this->queueService->joinWalkIn([
            'name' => 'Indra',
            'phone_number' => '081277776666',
            'service_id' => $this->serviceHaircut->id,
            'barber_id' => $this->barberBudi->id,
        ], $nowWib);
        $confirmed = $this->queueService->transitionStatus($walkIn2, 'confirmed', 'admin:1');
        $staleConfirmed = Appointment::findOrFail($confirmed->id);

        $this->queueService->transitionStatus($confirmed, 'served', 'admin:1');
        $this->assertSame(3, QueueLog::where('appointment_id', $walkIn2->id)->count());
        $this->assertSame('confirmed', $staleConfirmed->status);

        try {
            $this->queueService->transitionStatus($staleConfirmed, 'served', 'admin:2');
            $this->fail('Expected ValidationException for stale confirmed appointment.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $walkIn2->refresh();
        $this->assertSame('served', $walkIn2->status);
        $this->assertSame(3, QueueLog::where('appointment_id', $walkIn2->id)->count());
    }
}
