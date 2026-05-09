PATCH ADENA WEB - API SETTINGS OWNER + PERMISSION PER TOKEN
Tanggal: 2026-05-09

Isi patch:
1. Menu Admin -> Pengaturan API
   - Hanya owner yang bisa akses.
   - Admin biasa tidak bisa akses menu API.
   - Link lama admin/api_desktop.php diarahkan ke menu baru.

2. Permission per jenis API/token
   - POS Desktop
   - Backoffice
   - Cabang/Toko
   - Dapur
   - Integrasi Lain

   Permission granular:
   - master.view
   - categories.view/import/edit
   - products.view/import/edit
   - sales.view/push/revise
   - purchases.view/push/revise
   - stocks.view/adjust/opname
   - transfers.view/create/receive/cancel
   - users.view/sync
   - logs.view

3. Endpoint baru:
   - /api/v1/master.php
   - /api/v1/categories.php
   - /api/v1/products.php
   - /api/v1/sales.php
   - /api/v1/purchases.php
   - /api/v1/stocks.php
   - /api/v1/transfers.php
   - /api/v1/users.php

4. Endpoint lama tetap dipertahankan:
   - /api/auth.php
   - /api/sync/pull.php
   - /api/sync/push.php
   - /api/sync/shift.php

5. Database update aman:
   - db/updates_api_settings_permissions_v1.sql
   - Menambah kolom api_tokens tanpa menghapus data lama.
   - Menambah tabel api_request_logs.

6. Installer clean install:
   - install/index.php diperbarui.
   - Bisa pilih mode: backoffice, branch/cabang, kitchen/dapur.
   - Menggunakan dump terbaru: db/adena_latest_single_belitung.sql.
   - Folder db dirapikan agar tidak membingungkan.

7. Admin boleh menambahkan kasir:
   - Owner tetap bisa undang role apa pun selain owner.
   - Admin hanya boleh menambahkan user kasir.

Yang sengaja tidak disentuh:
- POS Desktop.
- Raw thermal.
- Preview struk.
- Closing shift print.
- Alur shift/transaksi POS Desktop yang sudah stabil.

Cara pakai existing database:
1. Upload semua file patch ke server.
2. Import manual db/updates_api_settings_permissions_v1.sql ke database lama.
3. Login sebagai owner.
4. Buka Admin -> Pengaturan API.

Cara clean install:
1. Upload folder ke server/cabang baru.
2. Pastikan belum ada install/install.lock.
3. Buka /install/index.php.
4. Isi database, mode unit, kode unit, dan owner awal.
5. Setelah selesai, login dari /adm.php.
