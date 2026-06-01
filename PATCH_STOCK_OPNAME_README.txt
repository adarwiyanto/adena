PATCH STOCK OPNAME - koreksi stok negatif, submit alasan selisih, dan rekap selisih per dokumen opname

File yang diubah/ditambahkan:
1. core/inventory.php
   - Draft opname tidak lagi membuat physical_qty negatif bila stok sistem negatif.
   - system_qty tetap mengikuti ledger asli.
   - physical_qty default minimal 0.
   - variance dihitung sebagai physical_qty - system_qty.
   - Saat approval, ledger adjustment tetap berdasarkan variance sehingga saldo akhir mengikuti physical_qty.
   - Note ledger menyertakan alasan dan catatan item sebagai log audit.

2. admin/stock_opname_form.php
   - Tombol Submit Menunggu Approval berada dalam form item yang sama.
   - Saat submit dari detail, data physical_qty, alasan selisih, dan catatan disimpan terlebih dahulu sebelum status berubah menjadi waiting_approval.
   - Link Rekap Selisih ditambahkan di detail opname.

3. admin/stock_opname_variance_report.php
   - File baru untuk rekap laporan selisih dalam satu dokumen stok opname.
   - Menampilkan header opname, ringkasan total, rekap berdasarkan alasan, dan detail item berselisih.
   - Ada tombol Print / Simpan PDF.

4. admin/stock_opname.php
   - Link Rekap Selisih ditambahkan pada daftar stok opname.

5. admin/stock_opname_approval.php
   - Link Rekap Selisih ditambahkan pada daftar approval.

Contoh logika:
- Stok sistem: -1
- Input fisik opname: 5
- Variance: 5 - (-1) = +6
- Saat approve, ledger masuk +6
- Saldo akhir: -1 + 6 = 5

Catatan:
- Tidak ada perubahan struktur database.
- Tidak ada perubahan fitur POS, pembelian, penjualan, printer, maupun modul lain.
- Upload file sesuai struktur folder yang sama ke server.
