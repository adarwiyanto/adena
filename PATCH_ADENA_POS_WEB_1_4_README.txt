PATCH ADENA POS DESKTOP 1.4 + WEB MULTI AREA

Isi utama:
1. POS Desktop ver 1.4
   - Diskon per item dan diskon transaksi dalam Rp / persen.
   - Pending order lokal sampai dibayar atau dihapus.
   - Produk harga fleksibel untuk item seperti ongkir.
   - Opsi produk masuk / tidak masuk laporan penjualan.
   - Sync diskon dan flag laporan ke DB web.

2. Web
   - Area Web di sidebar: Admin Area, Dapur Produksi, Toko/Cabang.
   - Dapur: dashboard, BOM, produksi, stok dapur, transfer keluar.
   - Toko/Cabang: dashboard, approval transfer masuk, stok toko, stok opname.
   - Pembelian tetap di Admin; stok diarahkan ke lokasi/cabang dari menu pembelian yang sudah ada.
   - Stok awal satu kali per produk/lokasi, override hanya owner.
   - Transfer stok menggunakan status sent/accepted/rejected; stok masuk penerima setelah approval.

3. Database
   - Jalankan db/updates_adena_pos_1_4.sql bila ingin migrasi manual.
   - File core/ops14.php juga melakukan ensure schema otomatis saat halaman baru/API dibuka.

Catatan pemasangan:
- Copy semua isi patch ke root folder web adena, overwrite file lama.
- Untuk POS Desktop, copy folder pos-desktop yang ikut patch ke source pos-desktop lalu jalankan npm install bila perlu dan npm run build.
- Patch bersifat additive. Tidak menghapus tabel lama.
- Fitur shift, print, dan sync lama tidak sengaja diubah selain penambahan field diskon/pending.

Validasi yang sudah dilakukan:
- php -l untuk file PHP yang berubah/baru: OK.
- node --check untuk file JS POS desktop yang berubah: OK.
