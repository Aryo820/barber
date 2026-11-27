<?php

namespace Tests\Feature;

use App\Models\Barber;
use App\Models\QueueLog;
use App\Models\Service;
use App\Services\QueueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAuthAndQueueApiTest extends TestCase
{
    use RefreshDatabase;

    private Barber $barberOne;
    private Barber $barberTwo;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-28 10:00:00', 'Asia/Jakarta'));

        config()->set('supabase.url', 'https://barberflow-test.supabase.co');
        config()->set('supabase.anon_key', 'test-anon-key');
        config()->set('supabase.admin_emails', ['owner@barberflow.test']);

        Http::preventStrayRequests();

        $this->barberOne = Barber::create([
            'name' => 'Budi',
            'is_active' => true,
            'current_status' => 'idle',
        ]);

        $this->barberTwo = Barber::create([
            'name' => 'Raka',
            'is_active' => true,
            'current_status' => 'busy',
        ]);

        $this->service = Service::create([
            'name' => 'Regular Haircut',
            'price' => 35000,
            'is_active' => true,
        ]);
    }

    private function fakeAdminUser(
        string $id = 'supabase-uid-1',
        ?string $email = 'owner@barberflow.test',
        array $appMetadata = [],
    ): void {
        $payload = [
            'id' => $id,
            'app_metadata' => $appMetadata,
        ];

        if ($email !== null) {
            $payload['email'] = $email;
        }

        Http::fake([
            'https://barberflow-test.supabase.co/auth/v1/user' => Http::response($payload, 200),
        ]);
    }

    public function test_missing_authorization_header_returns_401(): void
    {
        Http::fake();

        $this->getJson('/api/admin/me')
            ->assertStatus(401)
            ->assertExactJson([
                'message' => 'Token autentikasi tidak ditemukan.',
            ]);

        Http::assertNothingSent();
    }

    public function test_missing_supabase_configuration_returns_500(): void
    {
        Http::fake();
        config()->set('supabase.url', '');

        $this->withToken('some-token')
            ->getJson('/api/admin/me')
            ->assertStatus(500)
            ->assertExactJson([
                'message' => 'Konfigurasi Supabase Auth belum lengkap.',
            ]);

        Http::assertNothingSent();
    }

    public function test_invalid_token_from_supabase_returns_401(): void
    {
        Http::fake([
            'https://barberflow-test.supabase.co/auth/v1/user' => Http::response([
                'error' => 'invalid_token',
            ], 401),
        ]);

        $this->withToken('expired-jwt')
            ->getJson('/api/admin/me')
            ->assertStatus(401)
            ->assertExactJson([
                'message' => 'Sesi login tidak valid atau telah berakhir.',
            ]);

        Http::assertSent(static function (HttpClientRequest $request): bool {
            return $request->url() === 'https://barberflow-test.supabase.co/auth/v1/user'
                && $request->hasHeader('Authorization', 'Bearer expired-jwt')
                && $request->hasHeader('apikey', 'test-anon-key');
        });
    }

    public function test_supabase_payload_without_id_returns_401(): void
    {
        Http::fake([
            'https://barberflow-test.supabase.co/auth/v1/user' => Http::response([
                'email' => 'owner@barberflow.test',
            ], 200),
        ]);

        $this->withToken('malformed-jwt')
            ->getJson('/api/admin/me')
            ->assertStatus(401)
            ->assertExactJson([
                'message' => 'Sesi login tidak valid atau telah berakhir.',
            ]);
    }

    public function test_non_admin_supabase_user_returns_403(): void
    {
        $this->fakeAdminUser(
            id: 'supabase-uid-customer',
            email: 'customer@example.com',
            appMetadata: ['role' => 'customer'],
        );

        $this->withToken('valid-user-jwt')
            ->getJson('/api/admin/me')
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'Akun ini tidak memiliki izin admin.',
            ]);
    }

    public function test_supabase_connection_exception_returns_503(): void
    {
        Http::fake([
            'https://barberflow-test.supabase.co/auth/v1/user' => Http::failedConnection('Connection timed out'),
        ]);

        $this->withToken('valid-jwt')
            ->getJson('/api/admin/me')
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Layanan autentikasi Supabase sedang tidak dapat dijangkau.',
            ]);
    }

    public function test_supabase_server_error_returns_503(): void
    {
        Http::fake([
            'https://barberflow-test.supabase.co/auth/v1/user' => Http::response([
                'message' => 'Internal server error',
            ], 500),
        ]);

        $this->withToken('valid-jwt')
            ->getJson('/api/admin/me')
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Layanan autentikasi Supabase sedang tidak dapat dijangkau.',
            ]);
    }

    public function test_admin_via_email_allowlist_is_case_insensitive_and_returns_profile(): void
    {
        $this->fakeAdminUser(
            id: 'supabase-uid-owner',
            email: '  OWNER@BarberFlow.TEST ',
            appMetadata: [],
        );

        $this->withToken('owner-jwt')
            ->getJson('/api/admin/me')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => 'supabase-uid-owner',
                    'email' => 'owner@barberflow.test',
                    'role' => 'admin',
                ],
            ]);
    }

    public function test_admin_via_app_metadata_role_succeeds_even_outside_email_allowlist(): void
    {
        $this->fakeAdminUser(
            id: 'supabase-uid-meta-admin',
            email: 'staff@otherdomain.test',
            appMetadata: ['role' => 'admin'],
        );

        $this->withToken('meta-admin-jwt')
            ->getJson('/api/admin/me')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => 'supabase-uid-meta-admin',
                    'email' => 'staff@otherdomain.test',
                    'role' => 'admin',
                ],
            ]);
    }

    public function test_get_admin_queue_includes_customer_phone_number_without_wa_id(): void
    {
        $this->fakeAdminUser();

        $queueService = app(QueueService::class);
        $walkIn = $queueService->joinWalkIn([
            'name' => 'Andi Pratama',
            'phone_number' => '0812-3456-7890',
            'service_id' => $this->service->id,
            'barber_id' => null,
        ]);

        $response = $this->withToken('owner-jwt')->getJson('/api/admin/queue');

        $response
            ->assertOk()
            ->assertJsonPath('date', '2026-11-28')
            ->assertJsonPath('timezone', 'Asia/Jakarta')
            ->assertJsonCount(2, 'barbers')
            ->assertJsonPath('summary', [
                'pending' => 1,
                'confirmed' => 0,
                'served_today' => 0,
                'active_total' => 1,
            ])
            ->assertJsonPath('queue.0.id', $walkIn->id)
            ->assertJsonPath('queue.0.customer.name', 'Andi Pratama')
            ->assertJsonPath('queue.0.customer.phone_number', '6281234567890');

        $this->assertArrayNotHasKey('wa_id', $response->json('queue.0.customer'));

        $rawContent = $response->getContent();
        $this->assertIsString($rawContent);
        $this->assertStringNotContainsString('wa_id', $rawContent);
        $this->assertStringNotContainsString('@c.us', $rawContent);
    }

    public function test_patch_appointment_status_transitions_logs_actor_and_blocks_terminal_changes(): void
    {
        $this->fakeAdminUser(id: 'supabase-uid-owner', email: 'owner@barberflow.test');

        $appointment = app(QueueService::class)->joinWalkIn([
            'name' => 'Budi Santoso',
            'phone_number' => '0813-9999-8888',
            'service_id' => $this->service->id,
            'barber_id' => null,
        ]);

        // pending -> confirmed with barber assignment
        $confirmResponse = $this->withToken('owner-jwt')
            ->patchJson("/api/admin/appointments/{$appointment->id}/status", [
                'status' => 'confirmed',
                'barber_id' => $this->barberOne->id,
            ]);

        $confirmResponse
            ->assertOk()
            ->assertJsonPath('data.id', $appointment->id)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.barber.id', $this->barberOne->id)
            ->assertJsonPath('data.customer.phone_number', '6281399998888');

        $this->assertArrayNotHasKey('wa_id', $confirmResponse->json('data.customer'));

        $confirmLog = QueueLog::query()
            ->where('appointment_id', $appointment->id)
            ->where('action_type', 'serve')
            ->latest('id')
            ->first();

        $this->assertNotNull($confirmLog);
        $this->assertSame('serve', $confirmLog->action_type);
        $this->assertSame('owner@barberflow.test', $confirmLog->actor_id);

        // confirmed -> served
        $this->withToken('owner-jwt')
            ->patchJson("/api/admin/appointments/{$appointment->id}/status", [
                'status' => 'served',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'served');

        $this->assertSame(
            2,
            QueueLog::query()
                ->where('appointment_id', $appointment->id)
                ->where('action_type', 'serve')
                ->where('actor_id', 'owner@barberflow.test')
                ->count()
        );

        // Terminal served -> cancelled returns 422
        $this->withToken('owner-jwt')
            ->patchJson("/api/admin/appointments/{$appointment->id}/status", [
                'status' => 'cancelled',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_patch_appointment_status_falls_back_to_admin_id_when_email_is_null(): void
    {
        $this->fakeAdminUser(
            id: 'supabase-uid-no-email',
            email: null,
            appMetadata: ['role' => 'admin'],
        );

        $appointment = app(QueueService::class)->joinWalkIn([
            'name' => 'Candra',
            'phone_number' => '0815-1111-2222',
            'service_id' => $this->service->id,
            'barber_id' => $this->barberOne->id,
        ]);

        $this->withToken('no-email-admin-jwt')
            ->patchJson("/api/admin/appointments/{$appointment->id}/status", [
                'status' => 'cancelled',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $log = QueueLog::query()
            ->where('appointment_id', $appointment->id)
            ->where('action_type', 'leave')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('supabase-uid-no-email', $log->actor_id);
    }

    public function test_patch_barber_status_updates_valid_statuses_and_rejects_invalid(): void
    {
        $this->fakeAdminUser();

        // idle -> busy
        $this->withToken('owner-jwt')
            ->patchJson("/api/admin/barbers/{$this->barberOne->id}/status", [
                'current_status' => 'busy',
            ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $this->barberOne->id,
                    'name' => 'Budi',
                    'current_status' => 'busy',
                ],
            ]);

        $this->assertSame('busy', $this->barberOne->fresh()->current_status);

        // busy -> break
        $this->withToken('owner-jwt')
            ->patchJson("/api/admin/barbers/{$this->barberOne->id}/status", [
                'current_status' => 'break',
            ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $this->barberOne->id,
                    'name' => 'Budi',
                    'current_status' => 'break',
                ],
            ]);

        $this->assertSame('break', $this->barberOne->fresh()->current_status);

        // invalid status 'offline' -> 422
        $this->withToken('owner-jwt')
            ->patchJson("/api/admin/barbers/{$this->barberOne->id}/status", [
                'current_status' => 'offline',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_status']);
    }
}
