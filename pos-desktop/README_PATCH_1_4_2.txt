PATCH ADENA POS DESKTOP ver 1.4.2

Isi perbaikan:
1. Fix error tutup shift: ReferenceError localDateTimeString is not defined.
2. Version bump menjadi Adena POS Desktop ver 1.4.2.
3. Receipt POS dibuat tidak kosong lagi pada mode Raw Thermal/Auto dengan payload rawReceipt lengkap.
4. Header struk memakai nama toko + alamat toko, bukan versi aplikasi.
5. Footer struk memakai "Adena POS ver 1.4.2".
6. Tampilan keranjang dirapikan menjadi kartu per item dengan kontrol Qty, Harga, Diskon, Masuk laporan, dan Hapus.
7. Install NSIS bisa langsung timpa versi lama tanpa uninstall manual.

Cara pakai:
1. Backup folder lama C:\xampp\htdocs\pos-desktop.
2. Copy folder pos-desktop dari patch ini ke C:\xampp\htdocs\pos-desktop, overwrite file lama.
3. Jalankan:
   cd C:\xampp\htdocs\pos-desktop
   npm install
   npm start
4. Untuk build installer:
   npm run build
5. File installer hasil build ada di folder dist. Installer bisa dijalankan untuk menimpa versi lama.

Catatan:
- Data lokal SQLite dan settings tidak dihapus oleh patch ini.
- Jangan uninstall versi lama bila ingin mempertahankan setting printer/token/data lokal.
