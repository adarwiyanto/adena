# Adena POS Android 1.3.0 - Desktop Parity Native Offline

Baseline Android: AndroidPOS(5).zip
Reference behavior/UI: adena(5).zip / pos-desktop

## Prinsip
- POS Android tidak memakai WebView.
- POS Desktop (`pos-desktop`) adalah source of truth untuk flow dan field transaksi.
- Runtime transaksi selalu membaca/menulis SQLite lokal; jaringan hanya login/sync.

## Flow
Launcher -> Login native -> Sync native -> POS native

## Parity utama
- Login + Setting API
- Progress sinkronisasi master
- Topbar user, shift, status online/offline, pending sync, manual sync, keluar POS
- Tabs: POS, Receipt/Print, Riwayat, Rekapitulasi, Rekap Pelanggan, Order Masuk
- Menu kategori vertikal + pencarian produk
- Cart qty +/-
- Diskon item fixed/%
- Diskon transaksi fixed/%
- Customer name/phone
- Guide
- Payment method + bank/channel
- Quick cash + kembalian
- Multi-payment
- Local receipt + Bluetooth raw ESC/POS
- History offline
- Owner/admin edit/revision
- Owner/admin return
- Local stock rollback/apply on revision and return
- Durable sync queue

## API desktop
- POST /api/auth.php
- GET /api/sync/pull.php
- POST /api/sync/push.php
- POST /api/sync/shift.php
- POST /api/sync/revise.php (patch server)
- POST /api/sync/return.php (patch server)

## Catatan build
Gradle wrapper membutuhkan Gradle 9.0 dari services.gradle.org. Runtime penyusunan patch tidak memiliki akses internet sehingga compile Gradle penuh tidak dapat dijalankan di sini. XML dan PHP sudah divalidasi; PHP lint lulus.
