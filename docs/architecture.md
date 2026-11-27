# Panduan Arsitektur & Aturan Teknis: Web booking barber & antrean pelanggan dengan notifikasi Wha

## Pilihan Teknologi (Tech Stack)
- **Frontend**: React + Vite
- **Backend**: Laravel
- **Database**: Supabase
- **Pola Arsitektur**: separate

## Keputusan Spesifikasi & Pertanyaan Terjawab
- **q1**: Membangun bot WhatsApp sendiri menggunakan library open-source (misal: Baileys) tanpa biaya API resmi.
- **q2**: Sistem 'walk-in' hybrid: pelanggan bisa booking online atau join antrean fisik yang tersinkronisasi digital.
- **q3**: Event-driven architecture menggunakan Redis Pub/Sub untuk update status antrean real-time ke frontend.

## Konvensi Rekayasa Perangkat Lunak
1. **Keterbacaan**: Utamakan kode yang jelas dan memiliki kepemilikan layer yang tegas.
2. **Keamanan Boundary**: Validasi semua input pengguna di API boundary (server-side).
3. **Konsistensi Gaya**: Ikuti pola penamaan file dan struktur folder yang sudah ada di codebase.
