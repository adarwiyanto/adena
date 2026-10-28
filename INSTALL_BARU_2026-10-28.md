# Installer Adena — schema 28 Oktober 2026

Paket ini dirancang untuk **website baru / database kosong**. Tidak memigrasikan website produksi.

## Instalasi
1. Siapkan hosting PHP 8.4 dengan PDO MySQL dan database MariaDB/MySQL kosong.
2. Upload **isi folder `adena/`** ke document root domain. Jangan mengupload dump `adey8293_adena.sql` secara publik.
3. Buka `https://domain-anda/install/` (atau buka halaman utama dan ikuti pengalihan).
4. Isi URL website, database, lokasi upload privat di luar document root, dan akun Owner.
5. Klik **Install Sekarang**. Installer memuat `db/adena_install_schema.sql` (76 tabel sesuai dump 28 Oktober 2026), membuat owner pertama, dan konfigurasi lokal.
6. Setelah berhasil, file `install/install.lock` dan `install/LOCK` mengunci installer.

## Ketentuan penting
- Struktur SQL diambil dari dump `adey8293_adena.sql` tertanggal 28 Oktober 2026, **tanpa baris INSERT**; tidak menyertakan data pengguna, transaksi, stok, pelanggan, atau token lama.
- Database harus benar-benar kosong. Jika instalasi gagal setelah DDL berjalan, hapus database gagal dan buat database kosong baru sebelum mengulang. DDL MariaDB/MySQL tidak sepenuhnya transactional.
- Paket baru tidak memuat `config.php`, `config.local.php`, kunci installer, ataupun direktori `.git`.
- Folder privat dibuat saat instalasi. Pastikan izin file/folder dan akses database cukup.
- Belum diuji end-to-end pada hosting nyata; uji instalasi di staging sebelum produksi.
