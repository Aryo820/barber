# Tahap 1 — Fondasi FE & BE BarberFlow

## Intent & Why
Menyiapkan fondasi terpisah `backend/` (Laravel) dan `frontend/` (React + Vite) beserta skema database inti dari PRD agar fitur booking, antrean hybrid, Redis Pub/Sub, dan notifikasi WA di tahap berikutnya punya pijakan yang terverifikasi sejak awal.

## Goals, Non-Goals, Anti-Goals
- **Goals:**
  - Struktur folder terpisah: `backend/` (Laravel API) dan `frontend/` (React + Vite).
  - 4 migration & Eloquent model inti sesuai `docs/prd.md`: `customers`, `barbers`, `appointments`, `queue_logs` lengkap dengan relasi & index.
  - Konfigurasi Supabase (PostgreSQL) siap pakai di `backend/.env.example`, sementara lokal berjalan lancar dengan SQLite.
  - Endpoint `GET /api/health` + CORS terhubung dari `frontend/` via `VITE_API_URL`, dibuktikan dengan feature test backend dan build frontend.
- **Non-Goals (Tahap berikutnya):**
  - Endpoint booking/antrean (`POST /bookings`, `POST /queue/join`), UI form booking/live queue board, Redis Pub/Sub, dan service WhatsApp Baileys.
  - Skill `.agents/skills/backend-go` diabaikan sesuai arahan user.
- **Anti-Goals:**
  - Boilerplate/abstraksi berlebih (repository pattern kosong, auth scaffolding belum diminta, library tambahan yang tidak dipakai).

## Constraints
- Mesin lokal memiliki `php 8.3`, `composer`, `node`, `npm`; tanpa `docker` dan tanpa `go`.
- Kredensial Supabase belum tersedia saat ini.
- Repo belum diinisialisasi git.

## Approaches
1. **Standard Scaffold + Local SQLite Fallback (Dipilih):** Laravel via `composer create-project` + `.env.example` template PostgreSQL Supabase + `.env` lokal SQLite; FE React + Vite. Dipilih karena migration, relasi model, dan koneksi FE↔BE bisa langsung diverifikasi lokal tanpa menunggu kredensial Supabase.
2. **Direct Supabase Setup:** Mengunci `.env` langsung ke PostgreSQL Supabase dari awal. Ditolak untuk Tahap 1 karena memblokir verifikasi migration sebelum kredensial Supabase tersedia.

## Keputusan
- **Keputusan user:** Scope Tahap 1 hanya fondasi (Opsi 1), folder FE & BE dipisah, abaikan skill `backend-go`.
- **Pilihan builder (bisa dikoreksi):**
  - Folder bernama `backend/` dan `frontend/`.
  - `frontend/` pakai React + Vite + TypeScript (template `react-ts`), tanpa UI library tambahan dulu.
  - Skema tabel mengikuti PRD Section 5 dengan penyesuaian aman untuk PostgreSQL & SQLite (gunakan kolom `string` untuk status/type agar portable di SQLite & Postgres, FK cascade/nullOnDelete yang jelas, `barber_id`, `scheduled_time`, `queue_position`, `wa_id`, `actor_id` nullable di tempat yang relevan).

## Diserahkan ke Builder
- Penamaan migration timestamp, konfigurasi CORS Laravel (`config/cors.php`), helper response JSON sederhana, dan satu feature test yang memverifikasi migrasi + relasi model + `GET /api/health`.
