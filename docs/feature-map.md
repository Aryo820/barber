# Peta Fitur & Arsitektur: Web booking barber & antrean pelanggan dengan notifikasi Wha

## Diagram Alur Arsitektur (Mermaid)

```mermaid
flowchart TD
  %% Nodes
  frontend["React + Vite Frontend"]:::module
  backend["Laravel Backend API"]:::module
  booking_module["Modul Booking & Antrean"]:::module
  notification_service["Service Notifikasi WhatsApp"]:::integration
  appointments["appointments"]:::entity
  customers["customers"]:::entity

  %% Edges / Relations
  frontend -->|memanggil| backend
  backend -->|mengeksekusi| booking_module
  booking_module -->|menyimpan di| appointments
  booking_module -->|mengelola| customers
  booking_module -->|memicu| notification_service
  notification_service -->|mengirim pesan ke| customers

  %% Visual Styling
  classDef module fill:#3b82f6,stroke:#1d4ed8,color:#ffffff,stroke-width:2px;
  classDef entity fill:#6366f1,stroke:#4338ca,color:#ffffff,stroke-width:2px;
  classDef integration fill:#f59e0b,stroke:#d97706,color:#ffffff,stroke-width:2px;
```

## Daftar Modul & Entitas
- **React + Vite Frontend** (Modul)
- **Laravel Backend API** (Modul)
- **Modul Booking & Antrean** (Modul)
- **Service Notifikasi WhatsApp** (Integrasi Eksternal)
- **appointments** (Basis Data / Entitas)
- **customers** (Basis Data / Entitas)
