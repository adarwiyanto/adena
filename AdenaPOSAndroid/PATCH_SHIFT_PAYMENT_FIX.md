# Adena POS Android 1.3.2 – Shift Persistence & Payment DB Fix

## Fix pembayaran
- DB schema 5 -> 6.
- Migrasi `sales` lama sekarang menambahkan seluruh field native yang dibutuhkan, termasuk `product_id`, `qty`, dan `price_each`.
- Ditambahkan compatibility repair pada `onOpen()` agar instalasi yang pernah mengalami migrasi parsial tetap sembuh tanpa Clear Data.
- Riwayat native mengabaikan row transaksi legacy yang tidak mempunyai identitas item native.
- Commit penjualan tetap atomic: sales + stock ledger + stock lokal + sync queue dalam satu transaction.

## Fix shift
- Keluar/close aplikasi tidak pernah memanggil close shift.
- Sinkronisasi active shift tidak lagi menandai shift lokal lain sebagai closed hanya karena server id berbeda.
- Active shift server direkonsiliasi dengan local pending shift menggunakan offline_open_uuid / shift_code / pending local row.
- Shift mempunyai `effectiveId`: server id dipakai jika sudah tersedia; local id hanya fallback saat benar-benar offline.
- Pembayaran dan Tutup Shift mengirim server shift id bila shift sudah tersinkron.
- `markQueue()` ikut memperbarui sync_status shift.

Tidak diperlukan perubahan server untuk patch ini.
