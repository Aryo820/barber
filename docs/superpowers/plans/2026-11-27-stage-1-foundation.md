# BarberFlow Tahap 1 — Fondasi FE & BE Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membangun fondasi terpisah `backend/` (Laravel + 4 migrations/Eloquent models + `GET /api/health` + CORS) dan `frontend/` (React + Vite + TypeScript + status koneksi API) yang terverifikasi lokal tanpa ketergantungan Docker atau kredensial Supabase.

**Architecture:** Dua folder independen di root workspace (`backend/` dan `frontend/`). Backend memakai Laravel dengan konfigurasi `.env.example` siap PostgreSQL/Supabase dan `.env` lokal memakai SQLite agar seluruh migrasi dan feature test langsung jalan. Frontend memakai React + Vite (TypeScript) dengan satu helper API tipis (`src/lib/api.ts`) yang membaca `VITE_API_URL` untuk mengecek `GET /api/health`.

**Tech Stack:** Laravel (PHP 8.3, Composer 2.8), SQLite (lokal) / PostgreSQL Supabase (`.env.example`), React + Vite + TypeScript (Node 24, npm), PHPUnit (`php artisan test`), Node built-in test runner (`node --test`).

**Spec:** `docs/superpowers/specs/2026-11-27-stage-1-foundation-design.md`

## Global Constraints
- Folder terpisah: `backend/` (Laravel) dan `frontend/` (React + Vite + TypeScript).
- Abaikan `.agents/skills/backend-go`.
- Tanpa Docker, tanpa Go, tanpa kredensial Supabase aktif; `.env.example` backend tetap menyediakan template PostgreSQL Supabase.
- Tanpa boilerplate/abstraksi ekstra (tidak ada repository pattern, auth scaffolding, atau UI library tambahan).
- Semua kolom status/type memakai tipe `string` yang portable di SQLite dan PostgreSQL.
- Workspace belum memakai Git (`git commit` tidak dijalankan).

## Review Focus
- Duplikasi `customers.phone_number` harus ditolak di level database (unique index) → diverifikasi di Task 2 (`test_customer_phone_number_must_be_unique`).
- Menghapus `barber` tidak boleh gagal atau menghapus riwayat `appointments` (`barber_id` menjadi `null` via `nullOnDelete`) → diverifikasi di Task 2 (`test_deleting_barber_nullifies_appointment_barber_id`).
- Antrean walk-in harus bisa disimpan dengan `barber_id = null` dan `scheduled_time = null` → diverifikasi di Task 2 (`test_walk_in_appointment_allows_nullable_barber_and_scheduled_time`).
- `GET /api/health` harus mengembalikan header CORS `Access-Control-Allow-Origin` sesuai `FRONTEND_URL` (`http://localhost:5173`) agar fetch dari browser Vite tidak diblokir → diverifikasi di Task 1 (`test_health_endpoint_returns_ok_and_cors_header`).
- Frontend harus menangani kondisi backend mati atau status HTTP non-2xx dengan pesan error eksplisit (bukan blank screen/unhandled rejection) → diverifikasi di Task 3 (`tests/api.test.ts`).

---

### Task 1: Backend Laravel Scaffold, Supabase `.env.example`, Health API & CORS

**Files:**
- Create: `backend/` (scaffold via `composer create-project laravel/laravel backend`)
- Modify: `backend/.env.example`
- Modify: `backend/.env`
- Create/Modify: `backend/config/cors.php`
- Create/Modify: `backend/routes/api.php`
- Modify: `backend/bootstrap/app.php` (pastikan routing `api.php` aktif)
- Test: `backend/tests/Feature/HealthEndpointTest.php`

**Interfaces:**
- Consumes: none
- Produces:
  - `GET /api/health` → `200 JSON`: `{"status":"ok","service":"barberflow-api","database":"connected"}` (atau `503 JSON` `{"status":"error","service":"barberflow-api","database":"disconnected"}` bila koneksi DB gagal)
  - CORS aktif pada `api/*` mengizinkan `env('FRONTEND_URL', 'http://localhost:5173')`

- [ ] **Step 1: Scaffold Laravel di `backend/` dan aktifkan API routing**

Run:
```powershell
composer create-project laravel/laravel backend --no-interaction
```
Lalu di `backend/`:
```powershell
php artisan install:api --no-interaction
php artisan config:publish cors --no-interaction
```
Expected: folder `backend/` terbentuk, `backend/routes/api.php` dan `backend/config/cors.php` tersedia, `backend/database/database.sqlite` ada.

- [ ] **Step 2: Tulis failing test untuk `/api/health` dan CORS**

Buat `backend/tests/Feature/HealthEndpointTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok_and_cors_header(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
        ])->getJson('/api/health');

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'barberflow-api',
                'database' => 'connected',
            ])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
```

- [ ] **Step 3: Jalankan test untuk pastikan gagal sebelum implementasi**

Run (workdir `backend`): `php artisan test --filter=HealthEndpointTest`
Expected: FAIL (`404 Not Found` karena `/api/health` belum ada).

- [ ] **Step 4: Konfigurasi `.env.example` (Supabase), `.env` (SQLite lokal), CORS, dan route `/api/health`**

- Di `backend/.env.example`: set `APP_NAME=BarberFlow`, tambahkan `FRONTEND_URL=http://localhost:5173`, dan isi blok DB untuk PostgreSQL Supabase (`DB_CONNECTION=pgsql`, `DB_HOST=db.<project-ref>.supabase.co`, `DB_PORT=5432`, `DB_DATABASE=postgres`, `DB_USERNAME=postgres`, `DB_PASSWORD=`, `DB_SSLMODE=require`).
- Di `backend/.env`: pastikan `DB_CONNECTION=sqlite` (hapus/komentari `DB_HOST` dll. agar memakai `database/database.sqlite`), tambahkan `FRONTEND_URL=http://localhost:5173`.
- Di `backend/config/cors.php`: set `'paths' => ['api/*']` dan `'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')]`.
- Di `backend/routes/api.php`: tambahkan `Route::get('/health', ...)` yang memanggil `DB::connection()->getPdo()` dalam `try/catch (\Throwable)` dan mengembalikan JSON `200` (`status: ok`, `service: barberflow-api`, `database: connected`) atau `503` (`status: error`, `service: barberflow-api`, `database: disconnected`).

- [ ] **Step 5: Jalankan test untuk verifikasi lulus**

Run (workdir `backend`): `php artisan test --filter=HealthEndpointTest`
Expected: PASS (1 passed).

---

### Task 2: Core Database Migrations & Eloquent Models

**Files:**
- Create: `backend/database/migrations/2026_11_27_000001_create_customers_table.php`
- Create: `backend/database/migrations/2026_11_27_000002_create_barbers_table.php`
- Create: `backend/database/migrations/2026_11_27_000003_create_appointments_table.php`
- Create: `backend/database/migrations/2026_11_27_000004_create_queue_logs_table.php`
- Create: `backend/app/Models/Customer.php`
- Create: `backend/app/Models/Barber.php`
- Create: `backend/app/Models/Appointment.php`
- Create: `backend/app/Models/QueueLog.php`
- Test: `backend/tests/Feature/CoreSchemaTest.php`

**Interfaces:**
- Consumes: `backend/` Laravel app dari Task 1
- Produces:
  - Tabel `customers`: `id`, `name`, `phone_number` (unique), `wa_id` (nullable), `timestamps`
  - Tabel `barbers`: `id`, `name`, `is_active` (boolean default `true`), `current_status` (string default `'idle'`), `timestamps`
  - Tabel `appointments`: `id`, `customer_id` (FK `customers.id` cascade), `barber_id` (nullable FK `barbers.id` nullOnDelete), `service_type` (string), `scheduled_time` (nullable timestamp), `queue_position` (nullable unsignedInteger), `status` (string default `'pending'`), `type` (string), `timestamps`, index `['status', 'queue_position']`
  - Tabel `queue_logs`: `id`, `appointment_id` (FK `appointments.id` cascade), `action_type` (string), `timestamp` (timestamp useCurrent), `actor_id` (nullable string), `timestamps`
  - Model & relasi:
    - `Customer::appointments(): HasMany`
    - `Barber::appointments(): HasMany`
    - `Appointment::customer(): BelongsTo`, `Appointment::barber(): BelongsTo`, `Appointment::queueLogs(): HasMany`
    - `QueueLog::appointment(): BelongsTo`

- [ ] **Step 1: Tulis failing test untuk 4 tabel, relasi, dan constraint**

Buat `backend/tests/Feature/CoreSchemaTest.php` menggunakan `RefreshDatabase` dengan 4 test method:
1. `test_core_entities_and_relations_persist_correctly`: buat `Customer`, `Barber`, `Appointment` (online), dan `QueueLog` (`join`), lalu assert semua relasi dua arah (`$customer->appointments`, `$barber->appointments`, `$appointment->customer`, `$appointment->barber`, `$appointment->queueLogs`, `$log->appointment`) dan nilai atribut/cast (`$barber->is_active === true`).
2. `test_customer_phone_number_must_be_unique`: buat 2 `Customer` dengan `phone_number` sama → assert `expectException(\Illuminate\Database\QueryException::class)`.
3. `test_walk_in_appointment_allows_nullable_barber_and_scheduled_time`: buat `Appointment` dengan `type => 'walk_in'`, `barber_id => null`, `scheduled_time => null`, `queue_position => 1` → assert tersimpan di `appointments`.
4. `test_deleting_barber_nullifies_appointment_barber_id`: hapus `Barber` yang terhubung ke `Appointment` → refresh `$appointment` dan assert `$appointment->barber_id === null` serta baris `appointments` tetap ada.

- [ ] **Step 2: Jalankan test untuk pastikan gagal**

Run (workdir `backend`): `php artisan test --filter=CoreSchemaTest`
Expected: FAIL (class/table belum ada).

- [ ] **Step 3: Buat 4 file migration sesuai skema di blok Interfaces**

Pastikan foreign key constraint diaktifkan saat runtime SQLite Laravel (`DB_FOREIGN_KEYS=true` default Laravel), gunakan `foreignId('customer_id')->constrained()->cascadeOnDelete()`, `foreignId('barber_id')->nullable()->constrained()->nullOnDelete()`, `foreignId('appointment_id')->constrained()->cascadeOnDelete()`.

- [ ] **Step 4: Buat 4 Eloquent models (`Customer`, `Barber`, `Appointment`, `QueueLog`)**

Setiap model mendefinisikan `$fillable`, `$casts` (`is_active => 'boolean'`, `scheduled_time => 'datetime'`, `timestamp => 'datetime'`, `queue_position => 'integer'`), dan relasi sesuai blok Interfaces.

- [ ] **Step 5: Jalankan migration dan seluruh test backend**

Run (workdir `backend`):
```powershell
php artisan migrate:fresh --no-interaction
php artisan test
```
Expected: semua migration sukses, `HealthEndpointTest` + `CoreSchemaTest` semua PASS.

---

### Task 3: Frontend React + Vite Scaffold & Backend Health Check UI

**Files:**
- Create: `frontend/` (scaffold via `npm create vite@latest frontend -- --template react-ts`)
- Create: `frontend/.env.example`
- Create: `frontend/.env`
- Create: `frontend/src/lib/api.ts`
- Create: `frontend/tests/api.test.ts`
- Modify: `frontend/src/App.tsx`
- Modify: `frontend/src/App.css` (atau bersihkan CSS bawaan Vite agar tampilan bersih)
- Modify: `frontend/index.html` (ubah `<title>` ke `BarberFlow`)

**Interfaces:**
- Consumes: `GET /api/health` dari Task 1 (`{"status":"ok","service":"barberflow-api","database":"connected"}`)
- Produces:
  - `API_BASE_URL: string` dan `fetchHealth(fetchImpl?: typeof fetch, baseUrl?: string): Promise<HealthResponse>` di `frontend/src/lib/api.ts`
  - Halaman status Tahap 1 di `frontend/src/App.tsx` yang menampilkan status koneksi backend (`loading` / `connected` / `error` + tombol `Coba Lagi`)

- [ ] **Step 1: Scaffold Vite React TypeScript di `frontend/` dan install dependencies**

Run (workdir root):
```powershell
npm create vite@latest frontend -- --template react-ts
```
Lalu di `frontend/`:
```powershell
npm install
```
Expected: `frontend/package.json`, `frontend/vite.config.ts`, `frontend/src/` terbentuk dan `node_modules/` terpasang.

- [ ] **Step 2: Buat `frontend/.env.example` dan `frontend/.env`**

Isi kedua file:
```env
VITE_API_URL=http://localhost:8000
```

- [ ] **Step 3: Tulis failing test untuk `fetchHealth` di `frontend/tests/api.test.ts`**

Pakai `node:test` dan `node:assert/strict` (bawaan Node 24, diletakkan di `tests/` di luar `src/` agar tidak perlu tambah `@types/node` ke `tsconfig.app.json`):
1. `fetchHealth returns parsed payload on 200 OK`: mock `fetchImpl` mengembalikan `Response` JSON `{"status":"ok","service":"barberflow-api","database":"connected"}` dan cek URL yang dipanggil adalah `http://localhost:8000/api/health`.
2. `fetchHealth throws descriptive error on non-2xx response`: mock `fetchImpl` mengembalikan `503` → assert `assert.rejects` dengan pesan yang memuat status `503`.
3. `fetchHealth throws descriptive error on network failure`: mock `fetchImpl` reject `TypeError('fetch failed')` → assert `assert.rejects`.

- [ ] **Step 4: Jalankan test untuk pastikan gagal**

Run (workdir `frontend`): `node --test tests/api.test.ts`
Expected: FAIL (file `src/lib/api.ts` belum ada).

- [ ] **Step 5: Implementasikan `frontend/src/lib/api.ts` dan `frontend/src/App.tsx`**

- `frontend/src/lib/api.ts`: definisikan tipe `HealthResponse = { status: 'ok' | 'error'; service: string; database: 'connected' | 'disconnected' }`, export `API_BASE_URL` (ambil dari `import.meta.env?.VITE_API_URL` dengan fallback `'http://localhost:8000'` dan trim trailing slash), export `fetchHealth(fetchImpl = fetch, baseUrl = API_BASE_URL)`.
- `frontend/src/App.tsx`: panggil `fetchHealth()` pada mount dan lewat tombol `Coba Lagi`, tampilkan 3 state jelas (`Memeriksa koneksi...`, `Terhubung` + detail `service` & `database` + `API_BASE_URL`, atau pesan error + tombol `Coba Lagi`). Bersihkan template counter bawaan Vite.
- `frontend/index.html`: set title `BarberFlow — Stage 1 Foundation`.

- [ ] **Step 6: Verifikasi test unit dan production build frontend**

Run (workdir `frontend`):
```powershell
node --test tests/api.test.ts
npm run build
```
Expected: 3 test `tests/api.test.ts` PASS, `tsc -b && vite build` keluar kode 0 tanpa error.
