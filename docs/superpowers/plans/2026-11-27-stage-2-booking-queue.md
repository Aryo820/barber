# Stage 2 — Hybrid Booking, Live Queue & Supabase Admin Auth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build BarberFlow's hybrid online booking (fixed 30-minute slots per barber), walk-in queue with auto-incrementing daily positions, dynamic `services` catalog (no duration), live 5-second polling queue board, and Supabase Auth-protected Admin Board.

**Architecture:** Laravel REST API (`backend/`) handles catalog/slots/booking/walk-in/live-queue via a focused `QueueService` and verifies admin Bearer tokens against Supabase `/auth/v1/user` via `SupabaseAuthMiddleware`. React + Vite (`frontend/`) provides `#customer` and `#admin` views using `@supabase/supabase-js` for admin sessions and 5-second visibility-aware polling.

**Tech Stack:** Laravel 13 (PHP 8.3.6), Supabase PostgreSQL & Supabase Auth, PHPUnit (SQLite `:memory:`), React 19 + Vite 8 + TypeScript, `@supabase/supabase-js`, Node built-in test runner (`node --test`), `oxlint`.

**Spec:** `docs/superpowers/specs/2026-11-27-stage-2-booking-queue-design.md`

## Global Constraints

- Workspace `E:\web\Web-booking-barber` is NOT a Git repo; skip git commit steps and verify each task with its test/lint/build commands.
- Local machine has PHP 8.3.6 (`pdo_pgsql` enabled), Composer 2.8, Node 24, npm; no Docker and no Redis.
- All backend automated tests MUST run isolated on SQLite `:memory:` (`backend/phpunit.xml`) and mock Supabase HTTP calls via `Http::fake()`; never hit live Supabase during tests.
- Never print or read secrets from `backend/.env`.
- `services` table MUST NOT have a duration column (`name`, `price`, `is_active` only).
- Public `GET /api/queue` MUST NOT expose `phone_number` or `wa_id`.
- Only new frontend dependency allowed is `@supabase/supabase-js`; no CSS/UI component frameworks or state libraries.
- Operational timezone is `Asia/Jakarta` (WIB); booking slots are 30-minute intervals `09:00`–`20:30`, today through H+7.

## Review Focus

1. **Re-booking a cancelled/no-show online slot:** Cancelling or marking an online booking `no_show` or `served` must immediately free the slot in `GET /api/slots` and allow a new `POST /api/bookings` without violating the partial unique index — pinned in Task 1 & Task 2.
2. **WIB (`Asia/Jakarta`) vs UTC day-boundary drift:** Requests between `00:00` and `06:59 WIB` (previous calendar day in UTC) must calculate today's slots, walk-in `queue_position` reset, and active queue strictly in `Asia/Jakarta` — pinned in Task 2.
3. **Walk-in duplicate guard lifecycle:** Same phone number returns `409` while it has an active (`pending`/`confirmed`) walk-in today, but is allowed to join again once `served`/`cancelled`/`no_show` or on the next WIB day — pinned in Task 2.
4. **Supabase Auth upstream failure:** If `GET {SUPABASE_URL}/auth/v1/user` times out or returns 5xx, `SupabaseAuthMiddleware` returns JSON `503` (never grants access, never leaks stack trace) — pinned in Task 4.
5. **Missing frontend Supabase env vars:** Opening `#admin` when `VITE_SUPABASE_URL` or `VITE_SUPABASE_ANON_KEY` is blank must not throw from `createClient('', '')` and must render a clear setup message — pinned in Task 5 & Task 6.

---

## File Map

**Backend (`backend/`):**
- Create: `backend/config/barberflow.php` — operational hours, timezone, slot interval, priority window, max booking days ahead.
- Create: `backend/config/supabase.php` — Supabase URL, anon key, parsed admin email allowlist.
- Create: `backend/database/migrations/2026_11_27_000005_create_services_table.php` — `services` table (`name`, `price`, `is_active`).
- Create: `backend/database/migrations/2026_11_27_000006_add_stage_2_indexes_and_service_fk.php` — `appointments.service_id` nullable FK, PostgreSQL FK indexes, partial unique index for active online slots.
- Create: `backend/app/Models/Service.php` — Eloquent model for `services`.
- Modify: `backend/app/Models/Appointment.php` — add `service_id` fillable and `service()` relation.
- Create: `backend/database/seeders/BarberSeeder.php`, `backend/database/seeders/ServiceSeeder.php` — idempotent seeders.
- Modify: `backend/database/seeders/DatabaseSeeder.php` — call `BarberSeeder` and `ServiceSeeder`.
- Create: `backend/app/Services/PhoneNumberNormalizer.php` — Indonesian phone normalization (`62...`) & `wa_id` formatting.
- Create: `backend/app/Services/QueueService.php` — slot generation, online booking, walk-in join, hybrid live queue query, admin status transitions + `queue_logs`.
- Create: `backend/app/Http/Middleware/SupabaseAuthMiddleware.php` — Bearer token verification via Supabase `/auth/v1/user` + admin authorization.
- Modify: `backend/bootstrap/app.php` — register `'supabase.admin'` middleware alias.
- Create: `backend/app/Http/Controllers/PublicCatalogController.php` — `services`, `barbers`, `slots`.
- Create: `backend/app/Http/Controllers/BookingController.php` — `store` (`POST /api/bookings`).
- Create: `backend/app/Http/Controllers/QueueController.php` — `index` (`GET /api/queue`), `join` (`POST /api/queue/join`).
- Create: `backend/app/Http/Controllers/AdminQueueController.php` — `me`, `queue`, `updateAppointmentStatus`, `updateBarberStatus`.
- Modify: `backend/routes/api.php` — remove unused `/api/user`, register all public & admin endpoints.
- Modify: `backend/.env.example` — add `SUPABASE_URL`, `SUPABASE_ANON_KEY`, `SUPABASE_ADMIN_EMAILS`.
- Create tests: `backend/tests/Feature/ServiceAndSchemaTest.php`, `backend/tests/Unit/PhoneNumberNormalizerTest.php`, `backend/tests/Feature/QueueServiceTest.php`, `backend/tests/Feature/PublicBookingAndQueueApiTest.php`, `backend/tests/Feature/AdminAuthAndQueueApiTest.php`.

**Frontend (`frontend/`):**
- Modify: `frontend/package.json` — add `@supabase/supabase-js`.
- Modify: `frontend/.env.example` — add `VITE_SUPABASE_URL`, `VITE_SUPABASE_ANON_KEY`.
- Create: `frontend/src/lib/supabase.ts` — safe Supabase browser client (`getSupabaseClient()`, `isSupabaseConfigured()`).
- Modify: `frontend/src/lib/api.ts` — typed `ApiError` + all public & admin API helpers.
- Modify: `frontend/tests/api.test.ts` — unit tests for new API helpers, auth headers, error codes, and Supabase config helper.
- Modify: `frontend/index.html`, `frontend/src/index.css`, `frontend/src/App.css` — clean responsive styling replacing Vite starter styles.
- Create: `frontend/src/components/CustomerView.tsx` — Booking Online + Walk-in forms, confirmation ticket, live queue panel (5s polling).
- Create: `frontend/src/components/AdminView.tsx` — Supabase login, Admin Live Queue Board (5s polling), appointment actions, barber status controls.
- Modify: `frontend/src/App.tsx` — hash-routed `#customer` / `#admin` shell with compact API health indicator.

---

### Task 1: Database Schema (`services`, FK Indexes, Partial Unique Index), Config & Seeders

**Files:**
- Create: `backend/config/barberflow.php`
- Create: `backend/database/migrations/2026_11_27_000005_create_services_table.php`
- Create: `backend/database/migrations/2026_11_27_000006_add_stage_2_indexes_and_service_fk.php`
- Create: `backend/app/Models/Service.php`
- Modify: `backend/app/Models/Appointment.php`
- Create: `backend/database/seeders/BarberSeeder.php`
- Create: `backend/database/seeders/ServiceSeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Test: `backend/tests/Feature/ServiceAndSchemaTest.php`

**Interfaces:**
- Consumes: Stage 1 models & tables (`customers`, `barbers`, `appointments`, `queue_logs`).
- Produces:
  - `config('barberflow')`: `timezone` (`'Asia/Jakarta'`), `open_time` (`'09:00'`), `last_slot` (`'20:30'`), `slot_interval_minutes` (`30`), `priority_window_minutes` (`30`), `booking_max_days_ahead` (`7`).
  - `App\Models\Service` (`fillable`: `name`, `price`, `is_active`; `casts`: `price => integer`, `is_active => boolean`; `appointments(): HasMany`).
  - `App\Models\Appointment`: adds `service_id` to `$fillable`, `service(): BelongsTo`.
  - Partial unique index `appointments_active_online_barber_slot_unique` on `(barber_id, scheduled_time) WHERE type = 'online' AND status IN ('pending', 'confirmed') AND barber_id IS NOT NULL AND scheduled_time IS NOT NULL`.
  - Seeders `BarberSeeder` (Budi, Raka, Dimas) and `ServiceSeeder` (Regular Haircut 35000, Haircut + Beard Trim 50000, Kids Haircut 25000, Beard Trim 20000).

- [ ] **Step 1: Write failing test `backend/tests/Feature/ServiceAndSchemaTest.php`**

Test methods & assertions:
- `test_service_persists_without_duration_and_links_to_appointment`: creates `Service` (`Regular Haircut`, `price: 35000`, `is_active: true`), verifies `services` table has no `duration` or `duration_minutes` column via `Schema::hasColumn`, creates `Appointment` with `service_id` and `service_type: 'Regular Haircut'`, verifies `$appointment->service->id === $service->id` and `$service->appointments` count is 1.
- `test_deleting_service_nullifies_service_id_and_preserves_service_type_snapshot`: deletes `Service`, refreshes `Appointment`, asserts `$appointment->service_id === null` and `$appointment->service_type === 'Regular Haircut'`.
- `test_partial_unique_index_blocks_duplicate_active_online_slot_but_allows_rebooking_after_cancel`: inserts two `online` appointments for same `barber_id` and `scheduled_time`:
  - Second insert while first is `pending` throws `QueryException`.
  - Update first appointment status to `cancelled`, then second insert at same `barber_id` + `scheduled_time` succeeds.
- `test_seeders_create_default_barbers_and_services_idempotently`: runs `DatabaseSeeder` twice via `$this->seed(DatabaseSeeder::class)`, asserts `Barber::count() === 3` (`Budi`, `Raka`, `Dimas`, all `is_active = true`, `current_status = 'idle'`) and `Service::count() === 4` (`Regular Haircut` 35000, `Haircut + Beard Trim` 50000, `Kids Haircut` 25000, `Beard Trim` 20000).

- [ ] **Step 2: Run test to verify failure**

Run: `php artisan test tests/Feature/ServiceAndSchemaTest.php` (workdir: `backend`)
Expected: FAIL (class `App\Models\Service` not found / table missing).

- [ ] **Step 3: Implement config, migrations, models, and seeders**

1. `backend/config/barberflow.php`: return array with exact keys in `Produces` (`timezone => 'Asia/Jakarta'`, `open_time => '09:00'`, `last_slot => '20:30'`, `slot_interval_minutes => 30`, `priority_window_minutes => 30`, `booking_max_days_ahead => 7`).
2. `backend/database/migrations/2026_11_27_000005_create_services_table.php`: `id`, `name` (`string()->unique()`), `price` (`unsignedInteger`), `is_active` (`boolean()->default(true)`), `timestamps`.
3. `backend/database/migrations/2026_11_27_000006_add_stage_2_indexes_and_service_fk.php`:
   - In `appointments`: add `foreignId('service_id')->nullable()->after('barber_id')->constrained('services')->nullOnDelete()`, plus explicit indexes on `customer_id`, `barber_id`, `service_id`.
   - In `queue_logs`: add explicit index on `appointment_id`.
   - Use `DB::statement(...)` to create partial unique index `appointments_active_online_barber_slot_unique` on `appointments (barber_id, scheduled_time) WHERE type = 'online' AND status IN ('pending', 'confirmed') AND barber_id IS NOT NULL AND scheduled_time IS NOT NULL` (works identically in SQLite 3.8+ and PostgreSQL). Implement clean `down()` dropping the index and columns/indexes.
4. `backend/app/Models/Service.php` and update `backend/app/Models/Appointment.php` (`service_id` in `$fillable`, `service(): BelongsTo`).
5. `BarberSeeder`, `ServiceSeeder` using `updateOrCreate` on `name`, and update `DatabaseSeeder::run()` to call `[BarberSeeder::class, ServiceSeeder::class]` (remove unused demo `User::factory()`).

- [ ] **Step 4: Run backend tests to verify pass**

Run: `php artisan test` (workdir: `backend`)
Expected: All existing Stage 1 tests + all 4 `ServiceAndSchemaTest` tests PASS.

---

### Task 2: Phone Normalizer & Hybrid Queue Service (`QueueService`)

**Files:**
- Create: `backend/app/Services/PhoneNumberNormalizer.php`
- Create: `backend/app/Services/QueueService.php`
- Test: `backend/tests/Unit/PhoneNumberNormalizerTest.php`
- Test: `backend/tests/Feature/QueueServiceTest.php`

**Interfaces:**
- Consumes: `Customer`, `Barber`, `Service`, `Appointment`, `QueueLog`, `config('barberflow')`.
- Produces:
  - `PhoneNumberNormalizer::normalize(string $raw): ?string` — returns `62...` (10–15 digits) or `null` if invalid.
  - `PhoneNumberNormalizer::toWaId(string $normalizedPhone): string` — returns `"{$normalizedPhone}@s.whatsapp.net"`.
  - `QueueService::generateDailySlotTimes(): array` — `['09:00', '09:30', ..., '20:30']`.
  - `QueueService::slotsFor(Barber $barber, string $dateYmd, ?Carbon $now = null): array` — returns `array<int, array{slot: string, scheduled_time: string, available: bool, reason: ?string}>` (`reason`: `'booked' | 'past' | null`).
  - `QueueService::createOnlineBooking(array $data, ?Carbon $now = null): Appointment` — `$data`: `['name', 'phone_number', 'barber_id', 'service_id', 'date', 'slot']`. Throws `ValidationException` (422) for inactive barber/service, invalid/past slot, or out-of-range date; throws `ConflictHttpException` (409) when slot is already booked.
  - `QueueService::joinWalkIn(array $data, ?Carbon $now = null): Appointment` — `$data`: `['name', 'phone_number', 'service_id', 'barber_id' => ?int]`. Throws `ValidationException` (422) for inactive service/barber; throws `ConflictHttpException` (409) if customer already has an active walk-in today WIB.
  - `QueueService::todayQueue(bool $includeCustomerPhone = false, ?Carbon $now = null): array` — returns `['date' => 'YYYY-MM-DD', 'timezone' => 'Asia/Jakarta', 'barbers' => [...], 'queue' => [...], 'summary' => ['pending' => int, 'confirmed' => int, 'served_today' => int, 'active_total' => int]]`.
  - `QueueService::transitionStatus(Appointment $appointment, string $newStatus, string $actorId, ?int $barberId = null): Appointment` — enforces allowed transitions, assigns optional active `barber_id` on `confirmed`, inserts `QueueLog`, throws `ValidationException` (422) on terminal/invalid transitions or inactive barber.

- [ ] **Step 1: Write failing unit & feature tests**

In `backend/tests/Unit/PhoneNumberNormalizerTest.php`:
- Normalizes `0812-3456-7890` -> `6281234567890`, `+62 812 3456 7890` -> `6281234567890`, `81234567890` -> `6281234567890`, `6281234567890` -> `6281234567890`.
- Rejects too-short (`08123`), too-long (16+ digits), non-Indonesian prefix (`12345678901`), empty string -> `null`.
- `toWaId('6281234567890') === '6281234567890@s.whatsapp.net'`.

In `backend/tests/Feature/QueueServiceTest.php`:
- `test_slots_for_marks_past_and_booked_slots_and_frees_cancelled_slot`: fixes `$now = Carbon::parse('2026-11-28 10:15:00', 'Asia/Jakarta')`, books `11:00` (`pending`) and `11:30` (`cancelled`); asserts `09:00`/`09:30`/`10:00` have `available: false, reason: 'past'`, `10:30` has `available: true`, `11:00` has `available: false, reason: 'booked'`, `11:30` has `available: true` (Review Focus #1).
- `test_online_booking_upserts_customer_creates_join_log_and_rejects_conflict`: creates booking, asserts customer normalized to `62...` with `wa_id`, `service_type` snapshot matches service name, `queue_logs` has `action_type: 'join'`, and second booking on same barber/date/slot throws `ConflictHttpException`.
- `test_walk_in_assigns_daily_position_and_blocks_duplicate_active_same_day_only`: joins walk-in -> `queue_position: 1`; second join same phone same day throws `ConflictHttpException`; after transitioning first to `served`, same phone can join again same day and gets `queue_position: 2`; next WIB day resets `queue_position` to `1` (Review Focus #3).
- `test_wib_early_morning_boundary_uses_jakarta_date_not_utc`: sets `$now = Carbon::parse('2026-11-28 02:30:00', 'Asia/Jakarta')` (which is `2026-11-27 19:30:00 UTC`), creates walk-in on `2026-11-27 23:00:00 Asia/Jakarta` and another at `$now`; asserts new walk-in gets `queue_position: 1` (fresh WIB day) and `todayQueue(false, $now)` returns `date: '2026-11-28'` containing only the `2026-11-28 WIB` item (Review Focus #2).
- `test_today_queue_orders_by_hybrid_priority_and_respects_phone_privacy`: sets `$now = Carbon::parse('2026-11-28 10:00:00', 'Asia/Jakarta')`, creates:
  - online `13:00` (`pending` — >30m away -> tier 4)
  - walk-in pos 1 (`pending` -> tier 3)
  - online `10:30` (`pending` — <=30m -> tier 2)
  - walk-in pos 2 (`confirmed` -> tier 1)
  Asserts exact order `[confirmed walk-in, online 10:30, walk-in pos 1, online 13:00]`; asserts `includeCustomerPhone: false` omits `phone_number` and `includeCustomerPhone: true` includes it.
- `test_transition_status_enforces_state_machine_assigns_barber_and_logs_actor`: `pending -> confirmed` (assigns barber, logs `serve` with `actor_id`), `confirmed -> served` (logs `serve`), `served -> cancelled` throws `ValidationException`.

- [ ] **Step 2: Run tests to verify failure**

Run: `php artisan test tests/Unit/PhoneNumberNormalizerTest.php tests/Feature/QueueServiceTest.php` (workdir: `backend`)
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement `PhoneNumberNormalizer` and `QueueService`**

Key implementation details:
- All day boundaries use `$dayStartUtc = $wibNow->copy()->startOfDay()->utc()` and `$dayEndUtc = $wibNow->copy()->endOfDay()->utc()`.
- `createOnlineBooking` wraps conflict check + insert + `QueueLog::create` inside `DB::transaction()` and catches `QueryException` on unique violation -> rethrows `ConflictHttpException`.
- `joinWalkIn` wraps active-duplicate check + `MAX(queue_position)` query for today's WIB range + insert + `QueueLog::create` inside `DB::transaction()`.
- `todayQueue` loads active barbers ordered by `id`, fetches today's `pending`/`confirmed` appointments (`walk_in` created today WIB OR `online` scheduled today WIB) with `['customer', 'barber', 'service']`, sorts in PHP by `(tier, sortKey)` matching the 4-tier spec, and counts `served_today` in the same WIB day window.

- [ ] **Step 4: Run tests to verify pass**

Run: `php artisan test tests/Unit/PhoneNumberNormalizerTest.php tests/Feature/QueueServiceTest.php` (workdir: `backend`)
Expected: PASS.

---

### Task 3: Public Booking & Live Queue API Endpoints

**Files:**
- Create: `backend/app/Http/Controllers/PublicCatalogController.php`
- Create: `backend/app/Http/Controllers/BookingController.php`
- Create: `backend/app/Http/Controllers/QueueController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/PublicBookingAndQueueApiTest.php`

**Interfaces:**
- Consumes: `QueueService`, `PhoneNumberNormalizer`, `Service`, `Barber`.
- Produces HTTP JSON endpoints:
  - `GET /api/services` -> `{ data: [{ id, name, price }] }` (active only, ordered by `id`).
  - `GET /api/barbers` -> `{ data: [{ id, name, current_status }] }` (active only, ordered by `id`).
  - `GET /api/slots?barber_id={id}&date={YYYY-MM-DD}` -> `{ barber: { id, name }, date, timezone, slots: [{ slot, scheduled_time, available, reason }] }`.
  - `GET /api/queue` -> `{ date, timezone, barbers, queue, summary }` (no `phone_number` / `wa_id` anywhere in response).
  - `POST /api/bookings` -> `201` `{ data: { id, ticket_code, type, status, customer_name, barber, service, scheduled_time, slot, date } }` | `422` | `409`.
  - `POST /api/queue/join` -> `201` `{ data: { id, ticket_code, queue_position, type, status, customer_name, barber, service } }` | `422` | `409`.
  - Removes unused `GET /api/user` route from `backend/routes/api.php`.

- [ ] **Step 1: Write failing feature tests `backend/tests/Feature/PublicBookingAndQueueApiTest.php`**

Assertions:
- `GET /api/services` returns only `is_active = true` services with `id`, `name`, `price`.
- `GET /api/barbers` returns only `is_active = true` barbers with `id`, `name`, `current_status`.
- `GET /api/slots` validates required `barber_id` (active) and `date` (within today..H+7 WIB), returns 24 slots (`09:00`..`20:30`), returns `422` for past date or >H+7 or inactive barber.
- `POST /api/bookings` creates booking (`201`, `ticket_code: "BK-{id}"`), duplicate slot returns `409`, invalid phone returns `422`, invalid slot (`09:15`) returns `422`.
- `POST /api/queue/join` accepts nullable `barber_id` (`201`, `ticket_code: "A-1"`, `queue_position: 1`), duplicate active walk-in same phone returns `409`.
- `GET /api/queue` returns hybrid-ordered active items and asserts JSON string does NOT contain customer phone number (`628...`) or `wa_id`.
- `GET /api/user` returns `404` (removed).

- [ ] **Step 2: Run test to verify failure**

Run: `php artisan test tests/Feature/PublicBookingAndQueueApiTest.php` (workdir: `backend`)
Expected: FAIL (404 routes not registered).

- [ ] **Step 3: Implement controllers and routes**

1. `PublicCatalogController`: `services()`, `barbers()`, `slots(Request $request, QueueService $queueService)` (validates `barber_id` exists & `is_active = true`, `date` format `Y-m-d` within `[today, today + max_days]`).
2. `BookingController`: `store(Request $request, QueueService $queueService)` validates payload & normalized phone, delegates to `$queueService->createOnlineBooking(...)`, returns `201`.
3. `QueueController`: `index(QueueService $queueService)` returns `$queueService->todayQueue(false)`; `join(Request $request, QueueService $queueService)` validates payload & delegates to `$queueService->joinWalkIn(...)`, returns `201`.
4. Update `backend/routes/api.php`: keep `GET /api/health`, remove `GET /api/user`, register the 6 public routes.

- [ ] **Step 4: Run backend tests to verify pass**

Run: `php artisan test` (workdir: `backend`)
Expected: All backend tests PASS.

---

### Task 4: Supabase Admin Auth Middleware & Admin Queue/Barber API

**Files:**
- Create: `backend/config/supabase.php`
- Modify: `backend/.env.example`
- Create: `backend/app/Http/Middleware/SupabaseAuthMiddleware.php`
- Modify: `backend/bootstrap/app.php`
- Create: `backend/app/Http/Controllers/AdminQueueController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AdminAuthAndQueueApiTest.php`

**Interfaces:**
- Consumes: `QueueService`, `Appointment`, `Barber`, `config('supabase')`.
- Produces:
  - `config('supabase')`: `url` (`SUPABASE_URL`), `anon_key` (`SUPABASE_ANON_KEY`), `admin_emails` (`array<string>` lowercased from comma-separated `SUPABASE_ADMIN_EMAILS`).
  - Middleware alias `'supabase.admin'` (`SupabaseAuthMiddleware`): sets `$request->attributes->set('supabase_admin', ['id' => string, 'email' => ?string, 'role' => 'admin'])`.
  - Admin endpoints (all behind `supabase.admin`):
    - `GET /api/admin/me` -> `{ data: { id, email, role } }`.
    - `GET /api/admin/queue` -> same shape as `GET /api/queue`, with `customer.phone_number` included on every queue item.
    - `PATCH /api/admin/appointments/{appointment}/status` -> body `{ status: 'confirmed'|'served'|'cancelled'|'no_show', barber_id?: int }` -> `200` `{ data: ... }` | `422`.
    - `PATCH /api/admin/barbers/{barber}/status` -> body `{ current_status: 'idle'|'busy'|'break' }` -> `200` `{ data: { id, name, current_status } }` | `422`.

- [ ] **Step 1: Write failing feature tests `backend/tests/Feature/AdminAuthAndQueueApiTest.php`**

Configure `config()->set('supabase.url', 'https://demo.supabase.co')`, `config()->set('supabase.anon_key', 'test-anon-key')`, `config()->set('supabase.admin_emails', ['owner@barberflow.test'])` in `setUp()`.
Assertions (all using `Http::fake()`):
- Missing `Authorization` header -> `401`.
- Invalid token (Supabase returns `401`) -> `401`.
- Valid Supabase user whose email is NOT in `admin_emails` and `app_metadata.role` is not `'admin'` -> `403`.
- Supabase upstream timeout (`Http::failedConnection()` / `ConnectionException`) or `500` -> `503` JSON error without crashing (Review Focus #4).
- Valid admin via `SUPABASE_ADMIN_EMAILS` -> `GET /api/admin/me` returns `200` with email & `role: 'admin'`.
- Valid admin via `app_metadata.role === 'admin'` (even when not in `admin_emails`) -> `200`.
- `GET /api/admin/queue` includes `customer.phone_number` and `summary`.
- `PATCH /api/admin/appointments/{id}/status` updates `pending -> confirmed` with `barber_id` assignment, writes `queue_logs` with `action_type: 'serve'` and `actor_id: 'owner@barberflow.test'`; transitioning terminal `served -> cancelled` returns `422`.
- `PATCH /api/admin/barbers/{id}/status` updates `idle -> busy -> break`, rejects invalid value (`offline`) with `422`.

- [ ] **Step 2: Run test to verify failure**

Run: `php artisan test tests/Feature/AdminAuthAndQueueApiTest.php` (workdir: `backend`)
Expected: FAIL (routes / middleware missing).

- [ ] **Step 3: Implement config, middleware, controller, routes, and `.env.example`**

1. `backend/config/supabase.php` & append `SUPABASE_URL=`, `SUPABASE_ANON_KEY=`, `SUPABASE_ADMIN_EMAILS=` to `backend/.env.example`.
2. `SupabaseAuthMiddleware::handle`:
   - Check `$token = $request->bearerToken()`; if empty -> `401`.
   - Check `config('supabase.url')` & `config('supabase.anon_key')`; if empty -> `500`.
   - Try `Http::timeout(5)->acceptJson()->withHeaders(['Authorization' => "Bearer {$token}", 'apikey' => $anonKey])->get("{$url}/auth/v1/user")`.
   - Catch `ConnectionException` or `$response->serverError()` -> `503` (`Layanan autentikasi Supabase sedang tidak dapat dijangkau.`).
   - If `!$response->successful()` -> `401`.
   - Parse `id`, `email` (lowercased), `app_metadata.role`. Allow if `role === 'admin'` or `in_array($email, config('supabase.admin_emails'), true)`. Else -> `403`.
   - Attach `supabase_admin` attribute and pass `$next($request)`.
3. Register `'supabase.admin' => SupabaseAuthMiddleware::class` in `backend/bootstrap/app.php`.
4. Implement `AdminQueueController` and add `Route::prefix('admin')->middleware('supabase.admin')->group(...)` in `backend/routes/api.php`.

- [ ] **Step 4: Run full backend test suite**

Run: `php artisan test` (workdir: `backend`)
Expected: All backend tests PASS (0 failures).

---

### Task 5: Frontend Supabase Client & Typed API Layer

**Files:**
- Modify: `frontend/package.json`
- Modify: `frontend/.env.example`
- Create: `frontend/src/lib/supabase.ts`
- Modify: `frontend/src/lib/api.ts`
- Modify: `frontend/tests/api.test.ts`

**Interfaces:**
- Consumes: Backend API contracts from Tasks 3 & 4.
- Produces:
  - `frontend/src/lib/supabase.ts`: `isSupabaseConfigured(url?, anonKey?): boolean`, `getSupabaseClient(): SupabaseClient | null`.
  - `frontend/src/lib/api.ts`:
    - `ApiError` class (`status: number`, `message: string`, `errors?: Record<string, string[]>`).
    - Types: `ServiceItem`, `BarberItem`, `BarberStatus`, `SlotItem`, `SlotsResponse`, `AppointmentStatus`, `QueueItem`, `QueueBoardResponse`, `BookingTicket`, `WalkInTicket`, `AdminProfile`.
    - Functions (all support optional `fetchImpl` & `baseUrl` for testing):
      - `fetchHealth`
      - `fetchServices`
      - `fetchBarbers`
      - `fetchSlots(barberId, date)`
      - `fetchPublicQueue()`
      - `createBooking(payload)`
      - `joinWalkInQueue(payload)`
      - `fetchAdminMe(accessToken)`
      - `fetchAdminQueue(accessToken)`
      - `updateAppointmentStatus(accessToken, appointmentId, payload)`
      - `updateBarberStatus(accessToken, barberId, currentStatus)`

- [ ] **Step 1: Install `@supabase/supabase-js` and update `frontend/.env.example`**

Run: `npm install @supabase/supabase-js` (workdir: `frontend`)
Update `frontend/.env.example` to include `VITE_SUPABASE_URL=` and `VITE_SUPABASE_ANON_KEY=`.

- [ ] **Step 2: Write failing unit tests in `frontend/tests/api.test.ts`**

Add tests covering:
- `isSupabaseConfigured` returns `false` when url or key is missing/blank and `true` when both provided (Review Focus #5).
- `fetchSlots` builds `/api/slots?barber_id=1&date=2026-11-28` and parses response.
- `createBooking` sends `POST /api/bookings` JSON body and throws `ApiError` with `status: 409` on conflict and `status: 422` + field `errors` on validation failure.
- `joinWalkInQueue` sends `POST /api/queue/join` with nullable `barber_id`.
- `fetchAdminQueue` and `updateAppointmentStatus` include `Authorization: Bearer <token>` header and throw `ApiError` with `status: 401` / `403` on auth errors.

- [ ] **Step 3: Run tests to verify failure**

Run: `node --test tests/api.test.ts` (workdir: `frontend`)
Expected: FAIL (missing exports).

- [ ] **Step 4: Implement `frontend/src/lib/supabase.ts` and extend `frontend/src/lib/api.ts`**

- Keep `getSupabaseClient()` lazy & cached: returns `null` if `!isSupabaseConfigured()` so unconfigured env never throws at import or render time.
- Implement a single internal `requestJson<T>()` helper in `api.ts` handling JSON parsing, `Accept`/`Content-Type`/`Authorization` headers, and throwing `ApiError` with server-supplied `message` and `errors`.

- [ ] **Step 5: Run frontend unit tests & lint**

Run: `node --test tests/api.test.ts && npm run lint` (workdir: `frontend`)
Expected: All tests PASS and lint reports 0 errors.

---

### Task 6: Frontend Customer UI & Admin Live Queue Board

**Files:**
- Modify: `frontend/index.html`
- Modify: `frontend/src/index.css`
- Modify: `frontend/src/App.css`
- Create: `frontend/src/components/CustomerView.tsx`
- Create: `frontend/src/components/AdminView.tsx`
- Modify: `frontend/src/App.tsx`

**Interfaces:**
- Consumes: `frontend/src/lib/api.ts`, `frontend/src/lib/supabase.ts`.
- Produces: Complete Stage 2 UI with `#customer` (Booking Online, Walk-in, Confirmation Card, Live Queue Panel polling every 5s) and `#admin` (Supabase Login, Admin Live Queue Board polling every 5s, Appointment status actions, Barber status controls).

- [ ] **Step 1: Replace starter CSS & update `frontend/index.html`**

- Update `frontend/index.html` `<title>` to `BarberFlow — Booking & Antrean Barber`.
- Replace Vite starter styles in `frontend/src/index.css` and `frontend/src/App.css` with a clean, warm-editorial barbershop utility theme (high contrast light surfaces, crisp slate/charcoal typography, warm amber/emerald/rose semantic status colors, responsive 2-column desktop / single-column mobile layout, accessible focus rings and 44px touch targets).

- [ ] **Step 2: Build `frontend/src/components/CustomerView.tsx`**

- Loads `services` and `barbers` once on mount; polls `fetchPublicQueue()` every 5 seconds (paused when `document.visibilityState !== 'visible'`, immediately refreshes on visibility restore and after successful booking/walk-in submission).
- Form toggle: **Booking Online** vs **Antrean Walk-in**.
  - **Booking Online**: select service (shows `Rp 35.000` formatted with `Intl.NumberFormat('id-ID')`), select barber, date input bounded `today..today+7` (WIB), 30-min slot grid fetched via `fetchSlots(barberId, date)` (unavailable slots disabled with clear label `Terisi`/`Lewat`), customer name & phone inputs, submit button with loading lock and inline 409/422 error display.
  - **Walk-in**: select service, select barber (default `"Siapa saja"` -> `null`), customer name & phone inputs, submit button with inline 409/422 error display.
- **Ticket confirmation card** after submit (shows ticket code, service, barber, slot or queue position, button `"Daftar Lagi"`).
- **Live Queue Panel**: active barber status chips (`Tersedia`/`Sibuk`/`Istirahat`), live ordered queue list with clear badges (`Sedang Dilayani`, `Online HH:MM`, `Walk-in #N`), empty state, and last-synced timestamp.

- [ ] **Step 3: Build `frontend/src/components/AdminView.tsx`**

- Checks `getSupabaseClient()`: if `null`, displays clean Indonesian setup card explaining `VITE_SUPABASE_URL` & `VITE_SUPABASE_ANON_KEY` (Review Focus #5).
- Subscribes to `supabase.auth.getSession()` and `onAuthStateChange`; when session exists, verifies admin access via `fetchAdminMe(session.access_token)`.
- Login state: email & password form calling `supabase.auth.signInWithPassword`, showing clear errors for invalid credentials or `403` non-admin account (auto-signs out non-admin or provides sign-out button).
- Authenticated Admin Board:
  - Header with admin email, last-sync time, manual refresh button, and `Keluar` (`supabase.auth.signOut()`).
  - Summary counters (`Menunggu`, `Sedang Dilayani`, `Selesai Hari Ini`).
  - Barber status controls: per-barber buttons `Tersedia` (`idle`), `Sibuk` (`busy`), `Istirahat` (`break`) calling `updateBarberStatus`.
  - Live Queue Board (5s visibility-aware polling via `fetchAdminQueue`): shows customer name + phone number, type/slot/queue position, barber (with inline `<select>` to pick barber when walk-in has no barber yet before clicking `Panggil`), and status-aware action buttons (`Panggil` -> `confirmed`, `Selesai` -> `served`, `Batal` -> `cancelled`, `No-Show` -> `no_show`) with per-row loading state.
  - Handles `401` by clearing expired session back to login with a clear message.

- [ ] **Step 4: Wire `frontend/src/App.tsx`**

- Clean top header with BarberFlow brand, `#customer` / `#admin` view tabs synced with `window.location.hash`, and compact backend health status badge (reusing `fetchHealth`).

- [ ] **Step 5: Verify frontend tests, lint, and production build**

Run: `node --test tests/api.test.ts && npm run lint && npm run build` (workdir: `frontend`)
Expected: All tests PASS, `oxlint` 0 errors, `tsc -b && vite build` succeeds cleanly.

---

### Task 7: Live Supabase Migration, Seeding & End-to-End Verification

**Files:**
- No code edits expected unless smoke verification reveals a bug.

- [ ] **Step 1: Run full backend automated test suite (SQLite `:memory:`)**

Run: `php artisan test` (workdir: `backend`)
Expected: All tests PASS.

- [ ] **Step 2: Apply Stage 2 migrations and seeders to live Supabase PostgreSQL**

Run: `php artisan migrate --force && php artisan db:seed --force` (workdir: `backend`)
Expected: Migrations `2026_11_27_000005` and `2026_11_27_000006` run cleanly; `BarberSeeder` and `ServiceSeeder` complete cleanly.

- [ ] **Step 3: Live smoke check against local Laravel server & Supabase DB**

Start `php artisan serve` briefly or run a one-shot verification against live endpoints:
- `GET /api/health` -> `200`, `database: "connected"`
- `GET /api/services` -> `200`, 4 seeded services (no duration field)
- `GET /api/barbers` -> `200`, 3 seeded barbers
- `GET /api/queue` -> `200`, valid JSON structure
- `GET /api/admin/me` without token -> `401`
Stop the temporary server cleanly after verification.

- [ ] **Step 4: Re-run frontend tests, lint, and build**

Run: `node --test tests/api.test.ts && npm run lint && npm run build` (workdir: `frontend`)
Expected: All checks PASS.
