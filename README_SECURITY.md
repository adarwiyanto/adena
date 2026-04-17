# README SECURITY (Upload Hardening)

Dokumen ini menjelaskan perubahan keamanan upload agar file disimpan **non-public**, hanya diakses lewat gateway, dan kompatibel untuk XAMPP (Windows) serta cPanel (Linux).

## Ringkasan Perubahan

- Semua file upload disimpan di lokasi **di luar document root**.
- Akses file dilakukan melalui `download.php` dengan kontrol akses berbasis sesi (login).
- Upload divalidasi dengan **whitelist ekstensi + MIME**, batas ukuran, dan **rename random**.
- Path legacy seperti `uploads/...` masih didukung untuk data lama.

## Konfigurasi Lokasi Upload

File konfigurasi: `config/upload.php`

Prioritas lokasi upload:

1) **Environment variable** `HOPE_UPLOAD_BASE` (disarankan di production)
2) Jika tidak ada, **auto-detect**:
   - **Windows (XAMPP)**: `C:/xampp/private_uploads/hope/`
   - **Linux (cPanel)**: `/home/{cpanel_user}/private_uploads/hope/`
   - Fallback Linux lain: satu level di atas root app (`/path/to/app/../private_uploads/hope/`)

Subfolder otomatis:
- `images/` untuk gambar
- `docs/` untuk dokumen (PDF)

### Contoh set HOPE_UPLOAD_BASE di cPanel

- **cPanel → Setup PHP Environment / Environment Variables**
  - `HOPE_UPLOAD_BASE=/home/USERNAME/private_uploads/hope/`

Atau via `.htaccess` (jika diizinkan):
```
SetEnv HOPE_UPLOAD_BASE /home/USERNAME/private_uploads/hope/
```

### Contoh set HOPE_UPLOAD_BASE di Windows (XAMPP)

- Set Environment Variable Windows: `HOPE_UPLOAD_BASE=C:\xampp\private_uploads\hope\`
- Atau set di Apache vhost dengan `SetEnv`.

## Gateway Download

File: `download.php`

- Hanya melayani file token hasil upload baru (pattern hash 32 hex + ekstensi).
- Validasi sesi minimal: `$_SESSION['user']` atau `$_SESSION['customer']`.
- Untuk file legacy yang masih tersimpan sebagai `uploads/...`, sistem **tetap** menampilkan path lama sampai migrasi manual dilakukan.

**TODO migrasi data lama**: setelah file lama dipindahkan ke lokasi private, simpan ulang field menjadi token (tanpa path).

## Checklist Test (Wajib)

- Upload JPG/PNG valid → tersimpan di non-public.
- Upload PDF valid → tersimpan di non-public.
- Upload file `.php` → ditolak.
- Upload JPG palsu (MIME tidak cocok) → ditolak.
- Akses file via URL lama `/uploads/...` → masih bekerja untuk data lama.
- Akses token via `download.php` tanpa login → 403.
- Akses token via `download.php` dengan login → 200 dan tampil.

## CSP Mode (Report-Only vs Enforce)

Header CSP dikirim dari `core/security.php` dengan mode berikut:

- Default: `Content-Security-Policy-Report-Only` (tidak memblokir, hanya report)
- Enforce: set environment variable `CSP_ENFORCE=1` agar mengirim header `Content-Security-Policy`

Rollback cepat:
- Set `CSP_ENFORCE=0` (atau unset variabel) untuk kembali ke mode report-only.

