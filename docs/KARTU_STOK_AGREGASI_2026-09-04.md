# Perbaikan kartu stok pangan — 4 September 2026

## Penyebab yang ditemukan

- Mobile menggunakan `InventoryLot` untuk setiap baris daftar Kartu Stok sehingga dua penerimaan bahan yang sama tampil sebagai dua kartu.
- Web sudah menjumlahkan movement, tetapi grouping memasukkan `ingredient_name_snapshot` dan `unit_snapshot`. Perubahan nama atau satuan pada riwayat dapat memecah bahan dengan ID yang sama.
- Penghitung lot aktif web sebelumnya memakai daftar terbatas 100 lot dan tidak menyaring status `available`.
- Penerimaan sendiri tidak membuat master bahan baru: memilih ID master yang tersedia. Lot memakai kunci `stock_receipt_item_id`, bukan nomor lot supplier. Setiap penerimaan menghasilkan movement baru. Bagian ini dipertahankan.

## Perubahan

1. `WarehouseStockCardService` menjadi rekap baca-saja untuk pangan, berdasarkan unit SPPG + gudang + ID bahan. Nama dan kode berasal dari master; nama snapshot transaksi tidak ditulis ulang.
2. Saldo tetap dari `StockMovement.quantity_in - quantity_out`, bukan penjumlahan saldo lot. Satuan lama dikonversi lewat `InventoryUnitService` menggunakan berat snapshot atau faktor satuan yang sudah tersedia. Jika konversi tidak diketahui, tampil peringatan, bukan angka gabungan yang menyesatkan.
3. Kartu web/mobile menampilkan kode, nama, satuan, saldo total, jumlah lot aktif. Detail memuat semua lot dan mutasi. Lot aktif berarti saldo positif dan status `available`; tidak dibatasi 100.
4. Saldo berjalan diurutkan `movement_date`, `created_at`, kemudian `id`. Lot supplier yang sama tetap dapat dibedakan dengan ID lot internal.
5. Filter nama, kode, kategori, lokasi, dan status lot memilih bahan tanpa memotong saldo totalnya. Saldo kartu selalu kumulatif; filter tanggal bawaan mobile tidak lagi menyaring berdasarkan kedaluwarsa lot.
6. Mobile tetap memakai ID lot wakil untuk kompatibilitas alamat detail lama, tetapi isi detail dihitung per ID bahan/gudang. Koreksi dan perubahan lokasi tetap per lot: pengguna memilih lot, dan server menolak lot milik bahan/gudang/unit lain. Respons setelah aksi juga tetap rekap bahan.
7. Web memiliki ekspor CSV rekap (satu baris per bahan) dan CSV detail mutasi. Sebelumnya tidak ditemukan endpoint ekspor khusus kartu stok. Fitur PDF/share mobile tidak diubah menjadi CSV secara diam-diam.
8. Non-pangan tetap memakai implementasi sebelumnya. Tidak ada perubahan alur penerimaan, penarikan barang, verifikasi, FIFO/FEFO, atau alur divisi.

## Berkas

Baru:
- `app/Services/WarehouseStockCardService.php`
- `app/Support/Mobile/MobileStockCardPresenter.php`
- `app/Http/Controllers/WarehouseStockCardExportController.php`
- `resources/views/livewire/v3/warehouse/stock/detail.blade.php`
- `tests/Feature/WarehouseStockCardAggregationTest.php`
- `tests/Support/IsolatedStockCardDatabase.php`

Diubah:
- `app/Services/InventoryUnitService.php`
- `app/Livewire/V3/Warehouse/Stock/Index.php`
- `resources/views/livewire/v3/warehouse/stock/index.blade.php`
- `app/Http/Controllers/Api/MobileOperationalController.php`
- `app/Support/Mobile/MobileWorkspaceRegistry.php`
- `routes/v3.php`
- Tiga suite regresi lama: `ManualStockReceiptTest`, `OpeningStockEntryTest`, `NonFoodWarehouseFlowTest` memakai fixture memori agar tidak menjalankan RefreshDatabase.

## Data existing dan migration

Tidak memerlukan migration. Tidak ada tabel `stock_cards`, penghapusan data, penggabungan lot, perubahan movement, atau pembaruan database aktif. Data dengan ID master berbeda tetap berbeda walaupun namanya sama; penggabungan master tidak termasuk pekerjaan ini.

## Pengujian

Suite baru mencakup penerimaan berulang, lot supplier sama/berbeda, stok awal + penerimaan + mutasi keluar, rename master, pencarian lokasi, dua bahan, lot karantina/habis, konversi sak, konversi tidak diketahui, non-pangan, API daftar/detail, aksi per lot, isolasi gudang/unit, hak akses, urutan saldo berjalan, render detail Blade, ekspor, dan lebih dari 100 lot.

Suite yang dijalankan: `WarehouseStockCardAggregationTest`, `ManualStockReceiptTest`, `OpeningStockEntryTest`, `NonFoodWarehouseFlowTest`. Semua menggunakan SQLite `:memory:` dengan koneksi lain dikeluarkan dari konfigurasi pengujian. Tidak memakai database operasional.

Hasil: 24 pengujian / 116 assertion lulus. Build aset web berhasil. Bukan pengujian seluruh suite proyek atau uji perangkat Android fisik.

## Deployment VPS

1. Backup aplikasi dan database sebagai prosedur deployment biasa.
2. Unggah berkas aplikasi di atas dan hasil `public/build` dari build terbaru.
3. Bersihkan cache route dan view Laravel, lalu buat ulang cache sesuai prosedur VPS yang sudah digunakan. Restart PHP/OPcache jika konfigurasi VPS memerlukannya.
4. **Tidak menjalankan migrate, migrate:fresh, migrate:refresh, atau seeder.**
5. Buka Kartu Stok pangan, pilih bahan dengan beberapa penerimaan, periksa satu kartu, jumlah lot, saldo, dan ekspor CSV.
6. Di aplikasi mobile, muat ulang Kartu Stok. Perubahan berasal dari API dan menggunakan komponen detail yang sudah ada; tidak memerlukan perubahan APK untuk tampilan rekap ini.
7. Lakukan pemeriksaan penerimaan/non-pangan dan pemilihan lot koreksi menggunakan data uji yang memang diizinkan. Tidak ada pengujian mutasi di database aktif yang dilakukan oleh Codex.

Perubahan dibuat pada source proyek saat ini, bukan mengekstrak atau menimpa proyek dari ZIP referensi. Belum dideploy ke VPS.
