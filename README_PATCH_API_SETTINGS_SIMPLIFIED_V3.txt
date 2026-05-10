PATCH API SETTINGS SIMPLIFIED V3

Tujuan:
- Menyederhanakan Pengaturan API menjadi 2 alur:
  1. Pembuat / Pengirim API: Nama, generate API token, permission.
  2. Penerima API: Nama, domain pembuat, API token pembuat, permission.
- Permission dibuat dalam bentuk list/tree explorer dengan expand/collapse.
- Penerima API memiliki tombol Test Koneksi seperti pola POS Desktop.
- Detail permission token existing disembunyikan di tombol Detail agar halaman lebih ringkas.
- Tidak mengubah alur single branch, inventory, POS desktop, atau print.

File yang berubah:
- admin/api_settings.php
- core/api_permissions.php

File baru:
- db/updates_api_settings_simplified_v3.sql
- README_PATCH_API_SETTINGS_SIMPLIFIED_V3.txt

Cara pasang:
1. Backup file lama dan database.
2. Upload isi patch ke root website Adena, overwrite file yang sama.
3. Jalankan SQL:
   db/updates_api_settings_simplified_v3.sql
4. Login sebagai owner.
5. Buka Admin > Pengaturan API.
6. Buat API Pembuat atau Penerima sesuai kebutuhan.
7. Untuk Penerima API, isi domain pembuat + token, lalu klik Test Koneksi.

Catatan:
- SQL sudah dibuat aman terhadap duplicate column.
- Token pembuat tetap memakai Authorization: Bearer dan kompatibel dengan POS Desktop lama.
- Token hanya tampil sekali saat generate/regenerate.
