# Adena POS Android - build/runtime fix

Perbaikan dari AdenaPOSAndroid(8):
- Memperbaiki 5 error Kotlin `Unresolved reference: padding` dengan `setPadding(...)`.
- Normalisasi hasil `/api/sync/pull.php`: kategori produk dipetakan dari `category_id` ke nama kategori.
- Stok produk digabung dari array `stocks` ke cache produk lokal sehingga validasi stok offline benar.
- `branch_id` disimpan dari hasil sync.
- `active_shift` server diimpor ke SQLite lokal agar status shift konsisten setelah sync/login.
- Login memberi pesan eksplisit bila API token belum disetting pada instalasi pertama.
- Halaman Setting API mendapat tombol Test Koneksi.

Catatan: API Adena Desktop memang membutuhkan static Bearer API token sebelum username/password login.
