Patch API Desktop simplified v4

Tujuan:
1. Menghapus konsep halaman api_settings.php dari patch.
2. Setelan API aktif tetap memakai admin/api_desktop.php.
3. Membagi form menjadi:
   - Website Pembuat / Pengirim API: Nama, API token/generate, Permission.
   - Website Penerima: Nama, Domain Pembuat, API token, Permission.
4. Permission dibuat dalam bentuk list/tree checklist.
5. Website Penerima memiliki tombol Test Koneksi seperti POS Desktop.

File yang diupload/overwrite:
- admin/api_desktop.php
- admin/api/remote_test.php
- assets/api_desktop.js
- core/api_permissions.php
- db/update_api_desktop_simplified_v4.sql

Penting:
- File admin/api_settings.php dari patch sebelumnya tidak dipakai lagi.
- Setelah upload patch ini, hapus manual file berikut bila sempat terupload dari patch sebelumnya:
  admin/api_settings.php

SQL:
- Boleh jalankan db/update_api_desktop_simplified_v4.sql via phpMyAdmin.
- Namun file PHP juga sudah melakukan additive migration otomatis saat halaman admin/api_desktop.php dibuka.

Catatan keamanan/stabilitas:
- Patch ini tidak mengubah POS Desktop, printer, raw thermal, shift, sales, atau flow single branch.
- Test koneksi hanya melakukan GET ke /api/auth.php pada domain pembuat memakai Bearer token.
