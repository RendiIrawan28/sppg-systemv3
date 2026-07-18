# SPPG System V3

V3 menggunakan Laravel dan native Livewire sebagai lapisan antarmuka baru. Model Eloquent, service bisnis, tabel, enum, serta permission dari V2 tetap menjadi sumber kebenaran agar migrasi tidak menduplikasi aturan operasional.

## Fondasi saat ini

- Entry point: `/v3`
- Login native: `/v3/login`
- Workspace single-unit otomatis: `/v3/dashboard`
- Dashboard berbasis permission dan unit aktif
- Penerima manfaat native: daftar, tambah, ubah, aktivasi/nonaktif, alergi, dan impor Excel/CSV dengan preview
- Periode penerima 14 hari: snapshot master, salin periode, kenaikan kelas, workflow persetujuan, aktivasi, dan ekspor
- Matriks perencanaan menu: siklus, porsi berbasis periode, input harian/Excel, workflow, dan editor resep pendukung
- Editor resep native: kategori penerima, komponen hidangan, empat profil gramasi, konversi satuan, kalkulasi gizi, dan revisi menu aktif
- Kebutuhan bahan: kalkulasi resep × profil porsi × buffer, workflow, ekspor, dan penerusan ke pengadaan
- Pengadaan bahan: detail per role, pemilihan supplier, input/verifikasi/finalisasi harga, revisi, dan pemesanan
- Gudang: penerimaan dan QC, mutasi serta saldo kartu stok, dan serah bahan ke Persiapan
- Master data native: profil organisasi single-unit, pengguna/role, sekolah, Posyandu, kategori penerima, satuan, komponen dan standar gizi, alergen, bahan beserta nilai gizinya, supplier, hari libur, area kebersihan, dan divisi
- Lapangan native: rencana H-3, pembaruan penerima satu tombol, aktivasi langsung, insiden, ekspor, dan laporan harian otomatis
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
3. Pengadaan, gudang, dan serah bahan ke Persiapan. **Selesai.**
4. Rencana lapangan, insiden, dan laporan harian otomatis. **Selesai.**
5. Pengolahan, Pemorsian, Distribusi, Pencucian, dan Kebersihan. **Selesai.**
6. Dekomisioning Filament dan multi-tenant. **Selesai.**

Source UI V2, provider panel, route `/admin`, aset CSS/JavaScript, serta paket Composer Filament sudah dihapus. Model, tabel, enum, service, PDF, dan histori bisnis tetap menjadi domain V3.

## Keputusan pengurangan alur

- Multi-unit pengguna dihapus; satu akun memiliki satu unit kerja.
- Resource daftar Siklus/Menu lama dihapus. Matriks menjadi entry point tunggal; editor resep tetap menjadi halaman pendukung.
- Konfirmasi penerima aktual harian lama dihapus.
- Pusat Persetujuan dan Laporan Eksekutif Kepala SPPG dihapus.
- Evaluasi Penerimaan dan Laporan Gizi Harian digabung dalam satu workspace.
- Satuan Bahan, Standar Gizi, dan Standar Porsi digabung dalam satu workspace.
- Dashboard Kepala Divisi dan Petugas Divisi diganti dashboard V3 generik berbasis role.
- Rencana Distribusi langsung aktif saat disubmit tanpa approval Kepala SPPG.
- Laporan Harian Asisten Lapangan dibentuk dan disetujui otomatis saat rencana selesai; tidak ada form manual.
