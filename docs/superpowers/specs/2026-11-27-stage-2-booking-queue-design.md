# Tahap 2 — Hybrid Booking, Live Queue & Admin Auth (Supabase)

## Intent & Why
Membangun alur bisnis inti BarberFlow di atas fondasi Stage 1 agar pelanggan dapat memesan jadwal online (slot jam tetap per barber) atau bergabung ke antrean walk-in dengan nomor urut otomatis, serta memantau antrean secara langsung. Pemilik/barber mendapatkan Live Queue Board yang dilindungi autentikasi Supabase Auth untuk memanggil, menyelesaikan, membatalkan antrean, dan mengatur status barber.

## Goals, Non-Goals, Anti-Goals
- **Goals:**
  - **Tabel Layanan Dinamis (`services`)**: menyimpan daftar layanan (`name`, `price`, `is_active`) tanpa kolom durasi (karena durasi potong rambut tidak menentu), diisi lewat seeder dan diekspos lewat API baca.
  - **Penyempurnaan Skema Database**: menambahkan index FK PostgreSQL yang ditunda dari Stage 1 (`appointments.customer_id`, `appointments.barber_id`, `appointments.service_id`, `queue_logs.appointment_id`), kolom `service_id` (nullable FK ke `services`) pada `appointments` dengan tetap mengisi `service_type` sebagai snapshot nama layanan, serta partial unique index untuk mencegah double-booking online.
  - **API Publik Booking & Antrean**:
    - `GET /api/services` — daftar layanan aktif.
    - `GET /api/barbers` — daftar barber aktif beserta status saat ini.
    - `GET /api/slots` — daftar slot 30 menit untuk barber & tanggal tertentu beserta status ketersediaannya.
    - `GET /api/queue` — papan antrean aktif hari ini untuk publik (tanpa nomor HP pelanggan).
    - `POST /api/bookings` — buat booking online pada slot 30 menit tetap per barber dengan validasi bentrok (HTTP 409).
    - `POST /api/queue/join` — bergabung ke antrean walk-in dengan nomor urut (`queue_position`) otomatis per hari operasional.
  - **Autentikasi Admin (Supabase Auth) & API Admin**:
    - Verifikasi Bearer token Supabase Auth di backend Laravel melalui endpoint resmi Supabase `/auth/v1/user` + cek izin admin.
    - `GET /api/admin/me` — cek profil & status otorisasi admin.
    - `GET /api/admin/queue` — papan antrean lengkap hari ini untuk admin (menyertakan nomor HP pelanggan + ringkasan status).
    - `PATCH /api/admin/appointments/{appointment}/status` — transisi status antrean (`confirmed`, `served`, `cancelled`, `no_show`) dengan pencatatan audit ke `queue_logs`.
    - `PATCH /api/admin/barbers/{barber}/status` — ubah status barber (`idle`, `busy`, `break`).
  - **Frontend React + Vite (2 Mode dalam 1 App)**:
    - Tampilan **Pelanggan**: Form Booking Online, Form Walk-in, ringkasan tiket setelah berhasil mendaftar, dan Pantau Antrean Live (auto-polling tiap 5 detik).
    - Tampilan **Admin**: Login email/password menggunakan `@supabase/supabase-js`, Live Queue Board (auto-polling tiap 5 detik), tombol aksi status antrean, dan kontrol status barber.
    - Membersihkan starter CSS Vite (`frontend/src/index.css`) dan menghapus route bawaan `/api/user` yang tidak dipakai.
- **Non-Goals (Ditunda ke tahap berikutnya):**
  - Redis Pub/Sub, SSE, atau WebSocket (Stage 3).
  - Microservice bot WhatsApp Baileys dan pengiriman pesan otomatis (Stage 3).
  - CRUD layanan (`services`) dan CRUD barber di UI Admin (Stage 2 cukup Seeder + Read API).
  - Autentikasi/akun pelanggan (pelanggan cukup input nama & nomor HP).
- **Anti-Goals:**
  - Menambahkan kolom durasi/estimasi waktu pada layanan.
  - Membocorkan `phone_number` pelanggan di endpoint publik `GET /api/queue`.
  - Menggunakan kredensial admin hardcoded atau melewati verifikasi server-side.
  - Menambahkan UI component library besar atau state management eksternal di frontend.

## Constraints
- Lingkungan lokal: PHP 8.3.6 (`pdo_pgsql` aktif), Composer 2.8, Node 24, npm; tanpa Docker dan tanpa Redis lokal.
- Backend `backend/.env` sudah terhubung ke Supabase PostgreSQL; seluruh automated test PHPUnit harus tetap berjalan terisolasi di SQLite `:memory:` (termasuk mock panggilan HTTP Supabase Auth via `Http::fake()`).
- Aplikasi lokal memakai `php artisan serve` (single-threaded), sehingga live update menggunakan **polling tiap 5 detik** (bukan SSE/long-polling).
- Workspace belum diinisialisasi Git.

## Approaches Considered
1. **Sanctum Token + Layanan Statis (Ditolak user):** Memakai `laravel/sanctum` + tabel `users` lokal dan daftar layanan hardcoded. Cepat dan nol dependency, tetapi tidak memanfaatkan Supabase Auth dari stack PRD dan tidak fleksibel untuk data layanan/harga.
2. **Supabase Auth + Tabel `services` Dinamis (Dipilih user):** Frontend memakai `@supabase/supabase-js` untuk sesi admin, backend memverifikasi Bearer token lewat Supabase Auth API, dan layanan disimpan di tabel `services` tanpa kolom durasi. Menyelaraskan autentikasi dengan Supabase dan memberi dasar data layanan dinamis.

## Keputusan User (Terkunci)
- **Live update & akses admin**: Opsi C — Polling tiap 5 detik + Login Admin sekaligus di Stage 2.
- **Autentikasi & layanan**: Pendekatan 2 — Supabase Auth untuk admin + tabel `services` dinamis (`name`, `price`, `is_active`).
- **Durasi layanan**: Tidak memakai durasi/waktu pada layanan karena waktu potong rambut tidak menentu.
- **Aturan bentrok booking online**: Opsi 1 — Slot jam tetap per barber (interval 30 menit); 1 barber hanya boleh menerima 1 booking aktif pada slot jam yang sama.

## Desain Detail & Pilihan Arsitektur (Proposal Builder)

### 1. Skema Database & Seeder
- **Tabel baru `services`** (migration baru):
  - `id` (bigIncrements)
  - `name` (`string`, unique)
  - `price` (`unsignedInteger`, satuan Rupiah/IDR, contoh: `35000`)
  - `is_active` (`boolean`, default `true`)
  - `timestamps`
- **Migration penyesuaian `appointments` & `queue_logs`**:
  - Tambah `service_id` nullable FK ke `services` (`nullOnDelete`) pada `appointments` + index `service_id`. Kolom `service_type` tetap dipertahankan dan diisi snapshot `service.name` saat booking/walk-in dibuat agar kompatibel dengan skema PRD dan histori tidak berubah bila layanan diedit.
  - Tambah index FK PostgreSQL pada:
    - `appointments.customer_id`
    - `appointments.barber_id`
    - `queue_logs.appointment_id`
  - Tambah partial unique index (didukung PostgreSQL dan SQLite 3.8+) pada `appointments` (`barber_id`, `scheduled_time`) dengan kondisi `WHERE type = 'online' AND status IN ('pending', 'confirmed') AND barber_id IS NOT NULL AND scheduled_time IS NOT NULL` untuk menjamin anti-double-booking di level database.
- **Seeder (`DatabaseSeeder` memanggil `BarberSeeder` & `ServiceSeeder`, idempotent via `firstOrCreate`/`updateOrCreate`)**:
  - `BarberSeeder`: 3 barber aktif default (`Budi`, `Raka`, `Dimas`), `current_status = 'idle'`, `is_active = true`.
  - `ServiceSeeder`: 4 layanan default aktif tanpa durasi:
    - `Regular Haircut` — `35000`
    - `Haircut + Beard Trim` — `50000`
    - `Kids Haircut` — `25000`
    - `Beard Trim` — `20000`

### 2. Aturan Bisnis Hybrid Queue & Slot
- **Konfigurasi operasional** (`backend/config/barberflow.php`, pilihan builder):
  - Timezone operasional: `Asia/Jakarta` (WIB).
  - Jam operasional booking: `09:00` sampai `20:30` (slot terakhir `20:30`, interval 30 menit -> total 24 slot/hari: `09:00`, `09:30`, ..., `20:30`).
  - Jendela prioritas booking online mendekati jadwal: `30` menit sebelum `scheduled_time`.
  - Batas hari booking ke depan: hari ini sampai H+7 (`0..7` hari ke depan).
- **Normalisasi Nomor HP (`phone_number`)**:
  - Hapus karakter non-digit; jika diawali `0`, ganti jadi `62`; jika diawali `8`, tambahkan `62`. Validasi hasil akhir `10..15` digit dan diawali `62` (HTTP 422 bila tidak valid).
  - `customers` di-upsert berdasarkan `phone_number` (update `name` bila berubah, set `wa_id` = `{phone_number}@s.whatsapp.net` jika kosong agar siap dipakai Stage 3).
- **Booking Online (`POST /api/bookings`)**:
  - Payload: `name` (required, string max 100), `phone_number` (required), `barber_id` (required, harus barber aktif), `service_id` (required, harus service aktif), `date` (`YYYY-MM-DD`, dalam rentang hari ini..H+7 WIB), `slot` (`HH:MM`, harus salah satu slot 30 menit valid `09:00`..`20:30`).
  - Validasi waktu: gabungan `date + slot` (WIB) tidak boleh sudah lewat dibanding waktu sekarang.
  - Cek bentrok di dalam `DB::transaction()`: jika sudah ada appointment `type = 'online'`, `barber_id` sama, `scheduled_time` sama, dan `status IN ('pending', 'confirmed')` -> kembalikan HTTP `409 Conflict` dengan pesan jelas.
  - Simpan appointment dengan `type = 'online'`, `status = 'pending'`, `queue_position = null`, `service_id`, `service_type = service.name`, `scheduled_time` disimpan dalam timestamp UTC (dikonversi dari WIB).
  - Catat `queue_logs`: `action_type = 'join'`, `actor_id = null`.
  - Response `201 Created`: data appointment (id, kode tiket misal `BK-{id}`, barber, layanan, jadwal WIB, status).
- **Walk-in Queue (`POST /api/queue/join`)**:
  - Payload: `name` (required, string max 100), `phone_number` (required), `service_id` (required, harus service aktif), `barber_id` (nullable; jika diisi harus barber aktif).
  - Cegah duplikasi antrean aktif: jika pelanggan dengan `phone_number` tersebut sudah memiliki antrean `walk_in` berstatus `pending`/`confirmed` pada hari operasional ini (WIB) -> kembalikan HTTP `409 Conflict` beserta posisi antrean aktifnya.
  - Di dalam `DB::transaction()`: hitung `queue_position = MAX(queue_position) + 1` untuk appointment `type = 'walk_in'` yang `created_at` berada pada hari operasional ini (WIB), mulai dari `1`.
  - Simpan appointment dengan `type = 'walk_in'`, `status = 'pending'`, `scheduled_time = null`, `queue_position`, `service_id`, `service_type = service.name`.
  - Catat `queue_logs`: `action_type = 'join'`, `actor_id = null`.
  - Response `201 Created`: data appointment (id, nomor antrean `A-{queue_position}` / integer `queue_position`, barber atau `"Siapa saja"`, layanan, status).
- **Urutan Prioritas Papan Antrean Live (`GET /api/queue` & `GET /api/admin/queue`)**:
  - Menampilkan appointment aktif (`status IN ('pending', 'confirmed')`) untuk **hari operasional ini (WIB)** — yaitu walk-in yang dibuat hari ini dan booking online dengan `scheduled_time` pada hari ini.
  - Urutan prioritas:
    1. `status = 'confirmed'` (sedang dipanggil / di kursi), urut `updated_at ASC`.
    2. Booking `online` `pending` yang jadwalnya sudah masuk jendela prioritas (`scheduled_time <= now + 30 menit`), urut `scheduled_time ASC`.
    3. Antrean `walk_in` `pending`, urut `queue_position ASC`.
    4. Booking `online` `pending` yang jadwalnya masih `> 30 menit` lagi, urut `scheduled_time ASC`.
  - **Privasi publik**: `GET /api/queue` tidak menyertakan `phone_number` dan `wa_id`. `GET /api/admin/queue` menyertakan `phone_number`.
- **Transisi Status Admin (`PATCH /api/admin/appointments/{appointment}/status`)**:
  - Payload: `status` (`confirmed` | `served` | `cancelled` | `no_show`), `barber_id` (opsional, berguna untuk menetapkan barber pada walk-in saat transisi ke `confirmed`).
  - Matriks transisi yang diizinkan:
    - `pending` -> `confirmed`, `cancelled`, `no_show`
    - `confirmed` -> `served`, `cancelled`, `no_show`
    - Status terminal (`served`, `cancelled`, `no_show`) -> ditolak dengan HTTP `422`.
  - Jika transisi ke `confirmed` pada appointment yang belum punya `barber_id` dan admin mengirim `barber_id` valid & aktif, perbarui `barber_id`.
  - Mapping `queue_logs`:
    - `confirmed` atau `served` -> `action_type = 'serve'`
    - `cancelled` atau `no_show` -> `action_type = 'leave'`
    - `actor_id` diisi email admin dari token Supabase (fallback ke Supabase user `id`).
  - Perubahan status barber dilakukan secara eksplisit lewat `PATCH /api/admin/barbers/{barber}/status` (`idle` | `busy` | `break`) agar tidak memaksa status barber jika satu admin mencatat perubahan belakangan.

### 3. Autentikasi Admin via Supabase Auth
- **Konfigurasi Environment**:
  - `backend/.env.example`: tambahkan `SUPABASE_URL`, `SUPABASE_ANON_KEY`, `SUPABASE_ADMIN_EMAILS` (daftar email admin dipisah koma).
  - `frontend/.env.example`: tambahkan `VITE_SUPABASE_URL`, `VITE_SUPABASE_ANON_KEY`.
- **Alur Verifikasi (`SupabaseAuthMiddleware`)**:
  1. Ekstrak Bearer token dari header `Authorization`. Jika tidak ada -> `401 Unauthorized`.
  2. Jika `SUPABASE_URL` atau `SUPABASE_ANON_KEY` belum dikonfigurasi -> `500` dengan pesan konfigurasi jelas.
  3. Panggil `GET {SUPABASE_URL}/auth/v1/user` dengan header `Authorization: Bearer {token}` dan `apikey: {SUPABASE_ANON_KEY}` (timeout 5 detik).
  4. Jika respons bukan `200` -> `401 Unauthorized`.
  5. Cek hak admin: lolos bila `app_metadata.role === 'admin'` ATAU email user (lowercase) ada di daftar `SUPABASE_ADMIN_EMAILS`. Jika tidak -> `403 Forbidden`.
  6. Simpan profil admin terverifikasi di request attribute untuk dipakai controller (`GET /api/admin/me`, `queue_logs.actor_id`).
- **Catatan Skalabilitas**: verifikasi langsung ke `/auth/v1/user` memprioritaskan kesederhanaan & keamanan tanpa dependensi JWT tambahan; jika jumlah admin paralel meningkat di masa depan, dapat di-upgrade ke verifikasi JWKS lokal atau cache token ber-TTL pendek.

### 4. Antarmuka Frontend (`frontend/`)
- **Struktur sederhana tanpa router berat**: 2 tampilan (`Pelanggan` dan `Admin`) dengan state navigasi + hash URL (`#customer` / `#admin`) agar bisa di-refresh tanpa konfigurasi server SPA khusus.
- **Tampilan Pelanggan**:
  - Switch tab kecil: **Booking Online** vs **Antrean Walk-in**.
  - **Booking Online**: pilih Layanan (tampil nama + format `Rp`), pilih Barber, pilih Tanggal (hari ini s/d H+7), grid tombol Slot 30 menit (otomatis memuat dari `GET /api/slots`, slot terisi/lewat dinonaktifkan), input Nama & Nomor WA, tombol kirim.
  - **Antrean Walk-in**: pilih Layanan, pilih Barber (termasuk opsi `"Siapa saja"`), input Nama & Nomor WA, tombol gabung antrean.
  - **Kartu Konfirmasi**: tampilkan ringkasan booking/nomor antrean setelah submit berhasil + tombol buat pendaftaran baru.
  - **Live Queue Panel**: daftar barber aktif & status (`Tersedia`/`Sibuk`/`Istirahat`), daftar antrean aktif hari ini terurut sesuai prioritas hybrid (menampilkan badge `Sedang Dilayani` / `Online` / `Walk-in`, jam slot atau nomor antrean, nama pelanggan, layanan, barber), otomatis refresh tiap 5 detik (berhenti sementara saat tab browser tidak aktif via `document.visibilityState` agar hemat request).
- **Tampilan Admin**:
  - Jika belum ada sesi Supabase: form login email + password via `supabase.auth.signInWithPassword`, menampilkan pesan jelas bila kredensial salah atau akun bukan admin (`403`). Jika `VITE_SUPABASE_URL` / `VITE_SUPABASE_ANON_KEY` belum diisi, tampilkan panduan konfigurasi singkat alih-alih crash.
  - Jika sudah login & terverifikasi via `GET /api/admin/me`:
    - Header info email admin + tombol Logout + indikator waktu sync terakhir.
    - **Ringkasan Hari Ini**: jumlah menunggu, sedang dilayani, selesai.
    - **Panel Status Barber**: kartu tiap barber dengan tombol cepat `Tersedia (idle)`, `Sibuk (busy)`, `Istirahat (break)`.
    - **Live Queue Board**: daftar antrean aktif (auto-polling 5 detik) lengkap dengan nomor HP pelanggan, tipe (`Online`/`Walk-in`), slot/nomor urut, barber (dropdown pilih barber bila walk-in masih kosong), dan tombol aksi sesuai status (`Panggil`, `Selesai`, `Batal`, `No-Show`) dengan proteksi double-click saat request berlangsung.

### 5. Strategi Pengujian & Verifikasi
- **Backend (PHPUnit di SQLite `:memory:`)**:
  - Test API publik: `GET /api/services`, `GET /api/barbers`, `GET /api/slots` (menandai slot terpakai & lewat), `POST /api/bookings` (sukses, normalisasi nomor HP, bentrok slot 409, validasi slot tidak valid/masa lalu 422), `POST /api/queue/join` (sukses auto-increment `queue_position`, duplikasi aktif hari yang sama 409, barber nullable), `GET /api/queue` (urutan prioritas hybrid & tidak mengekspos `phone_number`).
  - Test Auth & Admin API (`Http::fake()` ke Supabase `/auth/v1/user`): 401 tanpa/token invalid, 403 user non-admin, 200 admin via `SUPABASE_ADMIN_EMAILS` & via `app_metadata.role`, `GET /api/admin/queue` menyertakan `phone_number`, transisi status valid + tercatat di `queue_logs` dengan `actor_id`, transisi status terminal ditolak 422, update status barber valid & invalid.
  - Jalankan migrasi & seeder ke Supabase PostgreSQL live setelah semua test lokal hijau.
- **Frontend**:
  - Unit test `frontend/tests/api.test.ts` diperluas untuk helper API booking/queue/admin (termasuk penyertaan header Bearer token & handling error 409/422/401/403).
  - `npm run lint` (`oxlint`) dan `npm run build` (`tsc -b && vite build`) wajib lolos tanpa error.

## Diserahkan ke Builder
- Nama class controller/service/request di Laravel (`app/Http/Controllers`, `app/Services`, `app/Http/Middleware`).
- Detail struktur komponen React (`src/components/*`, `src/lib/supabase.ts`, `src/lib/api.ts`) dan styling CSS yang responsif (desktop & mobile) tanpa framework CSS tambahan.
- Urutan task implementasi detail di dokumen rencana (`writing-plans`).
