PATCH ADENA POS DESKTOP ver 1.4.3

Isi update:
1. Layout Multi pembayaran dibuat vertikal ke bawah agar field metode, bank/channel, nominal, dan uang diterima lebih mudah diklik pada area keranjang.
2. Saat Multi pembayaran diaktifkan, nominal otomatis diisi sesuai sisa pembayaran.
3. Setelah tambah alokasi pembayaran, nominal berikutnya otomatis menjadi sisa pembayaran dan fokus kembali ke kolom nominal.
4. Version bump menjadi Adena POS Desktop ver 1.4.3.

Catatan keamanan perubahan:
- Tidak mengubah logic printer Windows/raw thermal/HTML print.
- Tidak mengubah layout struk, margin, logo, atau alur receipt.
- Tidak mengubah struktur database.
- Perubahan hanya pada UI multi-payment dan version label.

Cara pasang:
1. Replace file sesuai struktur folder patch.
2. Jalankan npm install bila diperlukan.
3. Jalankan npm start untuk test.
4. Setelah aman, build ulang dengan npm run build.
