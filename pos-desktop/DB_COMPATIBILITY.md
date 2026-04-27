# Analisis Database POS Web → Mapping POS Desktop SQLite

Sumber acuan di repo web:
- `install/index.php`
- `db/updates_*.sql`
- `core/functions.php`
- `core/pos_shift.php`
- `api/sync/pull.php`
- `api/sync/push.php`

## Tabel Master

| Tabel | Sumber web | Dipakai desktop |
|---|---|---|
| users | install + api pull | login lokal/offline fallback & kasir metadata |
| roles | db/updates_roles_sales_revision.sql | role metadata |
| role_permissions | db/updates_roles_sales_revision.sql | sinkron kompatibilitas RBAC |
| products | install + api pull | katalog POS |
| product_categories | core/functions.php | filter kategori |
| guides | core/functions.php | pilihan guide saat checkout |
| payment_methods | db/updates_payment_methods.sql + core/functions.php | metode pembayaran |
| qris_banks | core/functions.php | bank/channel non-tunai |
| settings | install + api pull | store info & receipt |

## Tabel Transaksi POS

| Tabel | Sumber web | Dipakai desktop |
|---|---|---|
| sales | install + update pos/desktop sync | header+detail transaksi itemized |
| orders | install | pesanan pending |
| order_items | install | item pesanan pending |
| stock_ledger | db/updates_inventory.sql | jejak stok kompatibel |
| pos_shifts | db/updates_pos_shift.sql | shift open/close |
| pos_shift_users | db/updates_pos_shift.sql | user aktivitas shift |
| pos_cash_movements | db/updates_pos_shift.sql | kas masuk/keluar |
| pos_sync_queue_log | db/updates_pos_shift.sql | log sync/error |

## Additive Field Lokal

Tanpa mengubah struktur utama transaksi web, ditambahkan field lokal:
- `local_transaction_id`
- `sync_status`
- `sync_error`
- `last_synced_at`

Semua additive untuk kebutuhan offline-first & retry sync.
