# POS Desktop (Electron + SQLite, Offline-First)

Aplikasi ini merecreate alur POS web Adena dengan pendekatan **offline-first**:
1. Simpan transaksi ke SQLite lokal dulu.
2. Lalu kirim ke API web (token statis).
3. Jika gagal, status tetap `pending/failed`, bisa manual sync.

## Kompatibilitas Database Web

Schema lokal memirror tabel web yang dipakai POS:

- Master: `users`, `roles`, `role_permissions`, `products`, `product_categories`, `guides`, `payment_methods`, `qris_banks`, `settings`.
- Transaksi/POS: `sales`, `orders`, `order_items`, `stock_ledger`, `pos_shifts`, `pos_shift_users`, `pos_cash_movements`, `pos_sync_queue_log`.

Field tambahan lokal yang dipakai hanya additive untuk offline/sync:
- `local_transaction_id`
- `sync_status`
- `sync_error`
- `last_synced_at`

## API yang Digunakan

- `GET /api/auth.php` → test token
- `POST /api/auth.php` → login user
- `GET /api/sync/pull.php` → sync master data
- `POST /api/sync/push.php` → push transaksi/shift/kas (idempotent by `offline_uuid` + `local_device_id/local_transaction_id`)

## Flow Transaksi

1. Login
2. Pilih produk
3. Keranjang
4. Checkout + metode bayar
5. Klik `BAYAR`
6. Simpan lokal ke `sales` dengan UUID
7. Auto sync ketika online
8. Jika gagal, tetap pending
9. Tombol `Print` + `Transaksi Baru`

## Pembayaran

- `Cash`, `QRIS`, `Transfer`, `Kartu Kredit`
- Field bank selalu tampil.
- Disabled saat cash.
- Wajib dipilih untuk non-tunai.
- Data bank diambil dari `qris_banks` hasil pull server.

## Setting API & Printer

Di halaman login (kiri bawah tombol **Setting API**):
- Base URL
- Token statis
- Test connection
- Default printer
- Ukuran struk (mm)
- Margin

## Print Native Windows

Print dilakukan dari proses Electron (`webContents.print`) ke device printer Windows yang dipilih (silent print), bukan tombol print browser halaman POS web.

## Menjalankan

```bash
cd pos-desktop
npm install
npm start
```

## Build EXE

```bash
cd pos-desktop
npm run build
```

Output installer akan dibuat oleh electron-builder (target NSIS/Windows).

## Catatan Integrasi Web

Repo web ini **sudah memiliki endpoint additive** untuk desktop sync (`api/sync/pull.php`, `api/sync/push.php`, `api/auth.php`), termasuk idempotency dan token API.
