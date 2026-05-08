PATCH ADENA - MULTICABANG DASHBOARD + CUSTOMER DEMOGRAFI

Cara pasang di hosting:
1. Backup file website dan database terlebih dahulu.
2. Upload isi folder `adena/` dari patch ini ke root website Adena, overwrite file lama.
3. Login sebagai owner/admin.
4. Buka halaman admin agar schema otomatis dipastikan oleh sistem.
5. Opsional: jalankan db/updates_multibranch_dashboard_customer.sql bila ingin migrasi manual via phpMyAdmin.
   Catatan: bila ada pesan duplicate column/index saat SQL manual, abaikan karena kolom/tabel sudah ada.

Isi patch:
- Role manager digeser menjadi manager_cabang.
- Tambah permission `branch_page` di halaman Role & Permission.
- Manager cabang dan pegawai cabang diarahkan ke halaman cabang.
- User dapat diassign ke satu cabang dari Admin > User.
- Halaman cabang memakai permission:
  * view = akses halaman cabang
  * create = input stok masuk / stock opname
  * export = lihat semua riwayat cabang; tanpa export hanya riwayat sendiri
- Input stok masuk cabang langsung menambah stok sistem dan membuat audit log.
- Halaman admin stok masuk cabang berubah menjadi Audit Stok Masuk Cabang, bukan approval.
- Stock opname cabang tetap blind opname: cabang input fisik, tidak melihat stok sistem/selisih.
- Admin dashboard mendapat ringkasan performa per cabang, hari/jam ramai, top produk, metode pembayaran, dead stock, slow moving, dan ringkasan customer-demografi.
- Customer difokuskan ke demografi: tanggal lahir, jenis kelamin, domisili, Instagram; HP dan email opsional.

Yang tidak diubah:
- POS desktop.
- API lama.
- Flow printer/sync POS desktop.
