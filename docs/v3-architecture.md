# SPPG System V3

V3 menggunakan Laravel dan native Livewire sebagai lapisan antarmuka baru. Model Eloquent, service bisnis, tabel, enum, serta permission dari V2 tetap menjadi sumber kebenaran agar migrasi tidak menduplikasi aturan operasional.

## Fondasi saat ini

- Entry point: `/v3`
- Login native: `/v3/login`
- Workspace single-unit otomatis: `/v3/dashboard`
- Dashboard berbasis permission dan unit aktif
- Penerima manfaat native: daftar, tambah, ubah, aktivasi/nonaktif, alergi, dan impor Excel/CSV dengan preview
- Jumlah penerima 14 hari: input angka per sekolah/Posyandu dan kategori, langsung aktif, tanpa nama individu atau workflow persetujuan
- Matriks perencanaan menu: siklus, porsi berbasis periode, input harian/Excel, workflow, dan editor resep pendukung
- Editor resep native: kategori penerima, komponen hidangan, empat profil gramasi, konversi satuan, perbandingan asupan menu dengan kebutuhan gizi harian penuh, dan revisi menu aktif
- Kebutuhan bahan: kalkulasi resep × profil porsi × buffer, workflow, ekspor, dan penerusan ke pengadaan
- Pengadaan bahan: detail per role, pemilihan supplier, input/verifikasi/finalisasi harga, revisi, dan pemesanan
- Gudang: penerimaan dan QC, mutasi serta saldo kartu stok, pengambilan langsung oleh tiga divisi, dan verifikasi harian
- Persiapan: sesi otomatis dari pengambilan Gudang terverifikasi, rekonsiliasi bahan, checklist higiene/rantai dingin, dokumentasi, deviasi, serah-terima Pengolahan, dan persetujuan berjenjang
- Pengolahan: batch dari rencana lapangan, bahan masuk otomatis dari Persiapan/Gudang, checklist proses, kontrol suhu, dokumentasi, deviasi, serah-terima Pemorsian, dan persetujuan berjenjang
- Pemorsian: penerimaan hasil Pengolahan, perlengkapan Gudang, alokasi per rute, sampel berat per kategori porsi, checklist, kontrol suhu, dokumentasi, deviasi, dan serah-terima Distribusi
- Distribusi: tujuan terkonfirmasi Asisten Lapangan, data kendaraan/driver/kernet, urutan tujuan bebas, status perjalanan, bukti serah-terima, porsi kembali, serta rekonsiliasi ompreng
- Keamanan: shift 12 jam, laporan situasi umum setiap tiga jam, pengingat otomatis, foto kondisi, dan laporan insiden terpisah
- Master data native: profil organisasi single-unit, pengguna/role, sekolah, Posyandu, kategori penerima, satuan, komponen dan standar gizi, alergen, bahan beserta nilai gizinya, supplier, hari libur, area kebersihan, dan divisi
- Lapangan native: rencana H-3, pembaruan jumlah penerima satu tombol, aktivasi langsung, kebutuhan bahan, insiden, ekspor, dan laporan harian otomatis. Dokumen divisi dibuat manual.
- Operasional native: Pengolahan, Pemorsian, Distribusi, Pencucian, dan Kebersihan beserta detail, dokumentasi, deviasi, serah-terima, workflow, dan PDF
- Panel Filament, route `/admin`, source UI V2, dan dependency Filament sudah dihapus

## Batas arsitektur

1. Aplikasi bekerja pada satu `SystemUnit`; tidak ada pemilih unit, membership pengguna, atau slug unit pada URL.
2. `SetV3UnitContext` memasang profil organisasi tunggal sebelum layar dirender.
3. Role dan permission Spatie bersifat global (`teams=false`); pivot serta kolom team lama dikonsolidasikan oleh migrasi `0900`.
4. Komponen Livewire memanggil service/domain V2; aturan bisnis tidak ditulis ulang di Blade.
5. Navigasi hanya menampilkan fitur yang diizinkan oleh permission pengguna.
6. `sppg_unit_id` pada tabel domain dipertahankan sebagai identitas organisasi dan arsip data, bukan pemilih tenant.
7. V3 adalah satu-satunya UI aplikasi; domain model dan service tetap dipakai tanpa ketergantungan pada Filament.

## Urutan migrasi yang disarankan

1. Penerima manfaat: form, impor, dan periode. **Selesai dimigrasikan.** Konfirmasi aktual harian lama tidak dibawa ke V3.
2. Menu dan gizi: matriks, editor resep, kebutuhan bahan, evaluasi, dan laporan harian gabungan. **Selesai.**
3. Pengadaan, Gudang, dan Persiapan sampai serah-terima ke Pengolahan. **Selesai.**
4. Rencana lapangan, insiden, dan laporan harian otomatis. **Selesai.**
5. Pengolahan, Pemorsian, Distribusi, Pencucian, dan Kebersihan. **Selesai.**
6. Dekomisioning Filament dan multi-tenant. **Selesai.**

Source UI V2, provider panel, route `/admin`, aset CSS/JavaScript, serta paket Composer Filament sudah dihapus. Model, tabel, enum, service, PDF, dan histori bisnis tetap menjadi domain V3.

## Keputusan pengurangan alur

- Multi-unit pengguna dihapus; satu akun memiliki satu unit kerja.
- Resource daftar Siklus/Menu lama dihapus. Matriks menjadi entry point tunggal; editor resep tetap menjadi halaman pendukung.
- Konfirmasi penerima aktual harian lama dihapus.
- Snapshot penerima berdasarkan nama, impor periode, kenaikan kelas, dan persetujuan periode dinonaktifkan dari UI. Data lama tetap dibaca sebagai arsip.
- Pusat Persetujuan dan Laporan Eksekutif Kepala SPPG dihapus.
- Evaluasi Penerimaan dan Laporan Gizi Harian digabung dalam satu workspace.
- Satuan Bahan, Standar Gizi, dan Standar Porsi digabung dalam satu workspace.
- Dashboard Kepala Divisi dan Petugas Divisi diganti dashboard V3 generik berbasis role.
- Rencana Distribusi langsung aktif saat disubmit tanpa approval Kepala SPPG.
- Aktivasi Rencana Lapangan tidak lagi membuat atau menyinkronkan dokumen Pengolahan, Pemorsian, dan Distribusi. Setiap divisi membuat dokumennya secara manual.
- Laporan Harian Asisten Lapangan dibentuk dan disetujui otomatis saat rencana selesai; tidak ada form manual.
- Perjalanan Distribusi selesai saat driver kembali ke SPPG dan rekonsiliasi lengkap; tidak ada pengajuan atau persetujuan berlapis.

## Alur jumlah penerima sederhana

- Data berlaku untuk rentang 14 hari dan langsung aktif setelah disimpan.
- Pengguna memilih sekolah atau Posyandu, lalu mengisi angka pada setiap kategori penerima.
- Nama, identitas, alergi individu, impor snapshot, kenaikan kelas, dan persetujuan periode tidak diperlukan pada alur sederhana.
- Jumlah per kategori tetap menyimpan profil porsi kecil/besar serta kelompok menu siswa, Balita, atau maternal.
- Penyusunan menu, perhitungan porsi, kebutuhan bahan, dan Rencana Lapangan membaca angka agregat ini.
- Data periode lama berbasis nama tidak dihapus dan tetap dapat dibaca sebagai arsip/fallback.

## Alur pengambilan Gudang

- Pengambilan langsung hanya dilakukan oleh petugas Persiapan, Pengolahan, dan Pemorsian.
- Setiap pengambilan wajib terhubung ke rencana distribusi, batch pengolahan, atau sesi pemorsian yang aktif.
- Dokumentasi foto wajib untuk setiap barang/lot. Lot freezer dan chiller juga wajib mencatat suhu saat pengambilan.
- Jumlah yang diajukan menahan stok tersedia, tetapi belum mengurangi saldo resmi.
- Staf Gudang melakukan verifikasi harian dan dapat mengoreksi jumlah aktual berdasarkan kondisi lapangan.
- Saldo resmi dan kartu stok baru berubah setelah verifikasi Gudang.
- Seluruh jumlah mengikuti satuan asli barang; kilogram hanya dipakai sebagai kolom kompatibilitas untuk data lama dan perhitungan gizi.
- Lot dibedakan menurut gudang basah, gudang kering, freezer, atau chiller serta tetap mengikuti FIFO/FEFO.

## Alur divisi Persiapan

- Sesi Persiapan dibuat otomatis hanya setelah pengambilan bahan oleh Persiapan diverifikasi Staf Gudang.
- Petugas mencatat kondisi bahan, metode cuci/kupas/potong/thawing, jumlah hasil bersih, serta sisa atau limbah dalam satuan asli barang.
- Jumlah hasil bersih ditambah sisa harus sama dengan jumlah yang diterima dari Gudang.
- Checklist wajib meliputi APD dan cuci tangan, sanitasi peralatan, pencegahan kontaminasi silang, keamanan air, metode proses, serta rekonsiliasi hasil. Kontrol rantai dingin otomatis wajib bila bahan berasal dari freezer atau chiller.
- Foto sebelum dan sesudah proses wajib tersedia. Foto saat proses dapat ditambahkan sebagai bukti pendukung.
- Deviasi dicatat bersama tingkat keparahan dan tindakan langsung; deviasi tinggi atau kritis harus diselesaikan sebelum sesi ditutup.
- Setelah selesai, bahan diserahkan langsung kepada Petugas Pengolahan dengan nama penerima dan foto serah-terima.
- Bahan yang tidak digunakan, rusak, atau ditolak dapat diajukan sebagai retur dengan jumlah, kondisi, alasan, dan foto. Pengajuan belum menambah saldo stok.
- Staf Gudang memeriksa jumlah aktual dan memilih keputusan: kembali tersedia, karantina, atau ditolak. Saldo dan kartu stok baru berubah setelah verifikasi Gudang.
- Retur yang baik kembali ke lot asal. Retur rusak/ditolak dibuat sebagai lot terpisah di Area Retur Gudang agar tidak mencemari saldo tersedia.
- Rekonsiliasi akhir memakai rumus `hasil bersih + sisa/limbah + retur terverifikasi = jumlah diterima`; sesi tidak dapat ditutup selama retur masih menunggu Gudang.
- Laporan diajukan Petugas Persiapan, diperiksa Kepala Divisi Persiapan, lalu mendapat persetujuan akhir Kepala SPPG. Semua perubahan tahap dicatat dalam histori audit.
- Tabel serah-terima dan inspeksi Persiapan versi lama dipertahankan hanya untuk kompatibilitas arsip; transaksi baru memakai `PreparationSession` sebagai sumber utama.

## Alur divisi Pengolahan

- Batch Pengolahan dibentuk dari rencana distribusi aktif dan menjadi referensi untuk bahan, hasil produksi, serta serah-terima.
- Hasil bersih yang diserahkan Persiapan otomatis menjadi bahan masuk batch terkait. Pengambilan langsung oleh Petugas Pengolahan yang telah diverifikasi Gudang juga otomatis masuk ke batch.
- Bahan yang bersumber dari Persiapan/Gudang dikunci dari perubahan manual agar jumlah, lot, satuan, dan referensinya tetap dapat ditelusuri.
- Batch tidak dapat dimulai sebelum memiliki bahan masuk yang layak diolah.
- Checklist wajib meliputi higiene petugas, sanitasi area/peralatan, pencegahan kontaminasi silang, kesesuaian resep, pemantauan pemasakan, perlindungan produk matang, dan pemeriksaan akhir.
- Penyelesaian produksi mewajibkan seluruh checklist lulus, hasil produksi dan satuannya terisi, suhu akhir beserta batas aman tercatat, serta foto sebelum dan sesudah proses tersedia.
- Suhu di luar batas wajib memiliki tindakan koreksi. Deviasi tinggi atau kritis harus diselesaikan sebelum batch ditutup.
- Hasil produksi diserahkan ke Pemorsian dengan jumlah, satuan, nama penerima, dan foto serah-terima.
- Laporan diajukan Petugas Pengolahan, diperiksa Kepala Divisi Pengolahan, lalu disetujui Kepala SPPG. Persetujuan divisi dan persetujuan akhir disimpan terpisah dalam histori audit.

## Alur divisi Pemorsian

- Sesi Pemorsian dibentuk dari rencana distribusi dan terhubung dengan batch Pengolahan serta pembagian porsi setiap rute.
- Setelah Pengolahan menyerahkan hasil, sistem otomatis mencatat jumlah, satuan, suhu, penerima, waktu, dan bukti serah-terima sebagai input Pemorsian.
- Sesi tidak dapat dimulai sebelum hasil Pengolahan diterima dan pembagian rute tersedia.
- Pengambilan langsung barang/perlengkapan Pemorsian yang telah diverifikasi Gudang otomatis masuk ke daftar perlengkapan dan dikunci dari perubahan manual.
- Checklist wajib mencakup higiene petugas, sanitasi alat/wadah/timbangan, pencegahan kontaminasi silang, standar porsi, menu khusus/alergen, kondisi kemasan, waktu-suhu, dan rekonsiliasi rute.
- Setiap kategori porsi kecil atau besar yang aktif wajib memiliki sampel berat sendiri. Sampel di luar toleransi wajib disertai tindakan koreksi.
- Suhu saat Pemorsian dan sebelum serah-terima Distribusi wajib dicatat bersama batas aman; kondisi di luar batas wajib memiliki tindakan koreksi.
- Foto sebelum dan sesudah Pemorsian wajib tersedia. Deviasi tinggi atau kritis harus diselesaikan sebelum sesi ditutup.
- Realisasi boleh berbeda dari target atau jumlah hasil Pengolahan, tetapi perbedaannya wajib dijelaskan dalam catatan selisih lapangan.
- Serah-terima ke Distribusi wajib sama dengan total realisasi per rute serta mencatat penerima dan foto bukti.
- Laporan diajukan Petugas Pemorsian, diperiksa Kepala Divisi Pemorsian, lalu disetujui Kepala SPPG dengan histori audit terpisah.

## Alur divisi Distribusi

- Asisten Lapangan mengonfirmasi tujuan di dalam rencana aktif. Tujuan tersebut otomatis muncul di akun driver dan tidak dapat ditambah oleh driver.
- Satu perjalanan berisi satu driver, satu kernet, dan beberapa tujuan. Driver bebas mengubah urutan pengantaran.
- Serah-terima resmi Pemorsian otomatis memperbarui jumlah setiap tujuan berdasarkan realisasi per rute beserta suhu serah-terima. Driver belum dapat memuat jika serah-terima tersebut belum tercatat.
- Sebelum memuat, driver wajib mengisi kendaraan, nomor polisi, nama driver, dan nama kernet. Jumlah muatan otomatis mengikuti realisasi Pemorsian sehingga tidak perlu diketik ulang.
- Status perjalanan dibuat ringkas: Direncanakan → Sedang Memuat → Dalam Distribusi → Kembali ke SPPG.
- Pada setiap tujuan, driver menekan Tiba di Tujuan terlebih dahulu. Sistem mengisi jumlah terkirim sesuai rencana sebagai nilai awal yang dapat dikoreksi jika kondisi lapangan berbeda.
- Penyerahan wajib mencatat nama penerima dan foto. Porsi yang tidak tersalurkan otomatis dihitung sebagai porsi kembali; gagal kirim wajib disertai alasan.
- Setelah penyerahan, driver mencatat ompreng kembali, rusak, atau hilang. Seluruh porsi dan ompreng harus terekonsiliasi sebelum perjalanan dapat ditutup.
- Perjalanan otomatis dianggap selesai setelah driver menekan Kembali ke SPPG. Tidak ada pengajuan laporan dan verifikasi berlapis.

## Alur Staf Keamanan

- Petugas menekan Mulai Shift saat mulai bekerja. Satu shift berlangsung 12 jam tanpa jadwal jam tetap.
- Sistem membentuk empat batas laporan, yaitu jam ke-3, 6, 9, dan 12 sejak shift dimulai.
- Saat batas laporan tercapai, pengingat muncul di dashboard dan halaman Keamanan sampai laporan diisi. Laporan tidak diberi status terlambat dan tetap dapat diisi setelah waktunya lewat.
- Setiap laporan mencatat nama petugas otomatis dari akun, situasi aman/perlu perhatian/darurat, kondisi gerbang, kondisi lingkungan, aktivitas orang atau kendaraan, tamu, catatan, serta satu foto wajib.
- Laporan bersifat umum untuk seluruh area SPPG dan tidak memerlukan persetujuan.
- Setelah laporan keempat dikirim, shift otomatis selesai. Tidak ada proses serah-terima shift di dalam sistem.
- Pencurian, aktivitas mencurigakan, keributan, kebakaran, kecelakaan, dan pelanggaran akses dicatat sebagai insiden terpisah tanpa menunggu laporan tiga-jam berikutnya.
- Staf Keamanan dapat mencatat dan menyelesaikan insiden. Kepala SPPG, Asisten Lapangan, Admin SPPG, dan Kepala Divisi memiliki akses pemantauan tanpa perlu memberikan persetujuan.
