Adena POS Desktop Patch v1.5.4

Fokus patch:
- Penjualan POS Desktop yang tersinkron ke web sekarang membentuk stock_ledger qty_out sehingga stok web berkurang.
- Penjualan lokal desktop juga mencatat stock_ledger lokal untuk menjaga konsistensi data offline.
- Hapus/retur transaksi web mengembalikan stok melalui stock_ledger qty_in sebelum sales dihapus/ditandai retur.
- Menjaga alur printer, preview struk, raw thermal, multi-payment, diskon item/transaksi, dan shift tanpa perubahan.

File utama:
- api/sync/push.php
- api/v1/sales.php
- core/inventory.php
- admin/sales.php
- pos-desktop/src/main/transactions.js
