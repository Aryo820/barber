# PRD — BarberFlow: Hybrid Booking & WA Queue System

## 1. Ringkasan
Platform booking barber hybrid yang menyinkronkan antrean fisik dan online melalui notifikasi WhatsApp otomatis berbasis bot open-source (Baileys). Solusi ini menghilangkan ketidakpastian waktu tunggu bagi pelanggan dan mengurangi beban administrasi manual bagi pemilik usaha melalui arsitektur event-driven real-time.

## 2. Target Pengguna
*   **Pelanggan:** Individu yang membutuhkan transparansi status antrean real-time dan konfirmasi jadwal via WhatsApp tanpa instalasi aplikasi berat.
*   **Barber/Owner:** Pemilik usaha kecil-menengah yang memerlukan dashboard sederhana untuk manajemen slot, penjadwalan walk-in, dan kontrol komunikasi otomatis.

## 3. Fitur Utama
*   **Hybrid Queue Engine:** Modul inti Laravel yang menangani logika prioritas antara booking online (`appointments`) dan join antrean fisik (`walk_in`), dengan sinkronisasi posisi antrean global.
*   **WA Notification Service:** Microservice Node.js terpisah menggunakan library Baileys untuk mengirim pesan transaksional (konfirmasi, reminder, "giliran Anda") ke nomor telepon pelanggan.
*   **Real-time Frontend Dashboard:** Interface React + Vite yang terhubung via WebSocket/SSE untuk menampilkan visualisasi antrean live, status barber, dan tombol aksi admin.
*   **Redis Pub/Sub Event Bus:** Infrastruktur messaging untuk memancarkan perubahan status antrean dari Backend ke Frontend dan memicu job notifikasi secara asinkron.

## 4. Relasi Fitur
Frontend —memanggil→ Backend API
Backend API —mengeksekusi→ Booking Module
Booking Module —menyimpan di→ Appointments Entity
Booking Module —mengelola→ Customers Entity
Booking Module —memicu→ Redis Pub/Sub
Redis Pub/Sub —trigger→ WA Notification Service
WA Notification Service —mengirim pesan ke→ Customers

## 5. Data Model (konsep tabel/entitas)
*   `customers`: id, name, phone_number (unique index), wa_id, created_at.
*   `barbers`: id, name, is_active, current_status (idle/busy/break).
*   `appointments`: id, customer_id, barber_id, service_type, scheduled_time, queue_position, status (pending/confirmed/served/cancelled/no_show), type (online/walk_in).
*   `queue_logs`: id, appointment_id, action_type (join/leave/serve), timestamp, actor_id.

## 6. Infrastruktur & Integrasi
*   **Stack:** Laravel 10+ (API Core), React + Vite (Admin/User UI), Supabase (PostgreSQL Database & Auth).
*   **Messaging:** Redis Instance (Pub/Sub channels: `queue_updates`, `wa_jobs`).
*   **Notification Worker:** Dedicated Node.js container running Baileys session manager; handles QR code generation for initial WA login and message queuing.
*   **Deployment Strategy:** Docker Compose for local dev; Vercel (Frontend) + Railway/Fly.io (Backend & Worker) for MVP production to manage stateful WA sessions efficiently.

## 7. List Tugas
1.  Setup Laravel project, configure Supabase connection, and define Eloquent models/migrations for core entities.
2.  Implement Redis Pub/Sub listeners in Laravel to broadcast queue position changes.
3.  Build standalone Node.js service with Baileys; implement endpoint to accept JSON payload `{phone, message}` from Laravel via HTTP or Redis queue.
4.  Develop React frontend components: Live Queue Board (admin) and Booking Form (user) with WebSocket integration.
5.  Create API endpoints for `POST /bookings` (online) and `POST /queue/join` (walk-in) with conflict resolution logic.
6.  Integrate notification triggers: Send WA confirmation on booking success and "Your Turn" alert when queue position reaches threshold.