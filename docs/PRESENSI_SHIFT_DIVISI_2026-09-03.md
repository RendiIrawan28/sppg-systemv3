# Pembaruan presensi pegawai: divisi, shift, dan keterlambatan

## 1. File baru

- `app/Models/AttendanceWorkSchedule.php`, `AttendanceWorkScheduleAssignment.php`: jadwal utama dan penugasan khusus pegawai.
- `app/Data/ResolvedAttendanceSchedule.php`: hasil penentuan jadwal beserta snapshot presensi.
- `app/Services/AttendanceWorkScheduleResolver.php`: satu sumber aturan jadwal dan tanggal kerja.
- `app/Services/AttendanceScheduleManager.php`: validasi, pencegahan benturan, serta versi perubahan jadwal.
- `app/Services/AttendanceAbsenceService.php` dan `app/Console/Commands/MarkAttendanceAbsent.php`: ketidakhadiran otomatis.
- `app/Services/AttendanceReportData.php`: filter dan pengelompokan data laporan.
- `app/Livewire/V3/Attendance/WorkSchedules.php` dan view terkait: halaman Jam Kerja & Shift.
- `tests/Feature/AttendanceWorkScheduleTest.php`, `AttendanceReportsTest.php`, dan `tests/Support/IsolatedAttendanceDatabase.php`.

## 2. File yang diubah

- `VolunteerAttendanceService`, `AttendanceSession`, halaman dan view Attendance Index.
- `AttendanceReportController` serta template PDF presensi.
- `AccessControl`, navigasi Kepegawaian, rute web dan jadwal perintah.
- Pengujian presensi sebelumnya dialihkan ke koneksi SQLite memori khusus, tanpa `RefreshDatabase` maupun `migrate:fresh`.
- Tidak ada perubahan alur modul gudang, produksi, distribusi, keamanan, maupun aplikasi mobile.

## 3. Migration baru

1. `2026_09_03_150000_add_work_schedules_to_attendance.php`
   - Menambah tabel jadwal dan penugasan; menambah kolom snapshot pada presensi.
   - Mengisi divisi data lama menggunakan keanggotaan aktif: divisi utama terlebih dahulu, lalu urutan divisi.
   - Tidak menebak jam kerja atau keterlambatan data lama; tidak menghapus sesi presensi.
2. `2026_09_03_151000_add_attendance_schedule_permission.php`
   - Memberi `attendance.schedules` hanya kepada Kepala SPPG dan Admin SPPG. Super Admin menggunakan akses menyeluruh yang sudah tersedia.

Migration ini **belum dijalankan pada database operasional**.

## 4. Perilaku baru

- Menu Kepegawaian memiliki Presensi Pegawai dan Jam Kerja & Shift.
- Jadwal diisi manual, termasuk divisi, jam masuk/pulang, hari kerja, toleransi, dan masa berlaku. Tidak ada jadwal contoh yang ditanamkan.
- Hari di luar shift khusus merupakan hari tidak terjadwal; tidak kembali ke jadwal utama.
- Jam pulang yang sama atau lebih awal dari masuk berarti lintas hari. Tanggal kerja mengikuti tanggal mulai shift.
- Keterlambatan dihitung per menit penuh setelah toleransi: batas 16.10 dan tap 16.10.30 masih 0 menit; tap 16.11.00 menjadi 1 menit.
- Hadir terlambat tetap `present`. Jumlah terlambat adalah bagian dari jumlah hadir.
- Ketidakhadiran otomatis dicatat setelah shift selesai, hanya bagi pegawai terjadwal yang belum memiliki data presensi, izin, sakit, atau ketidakhadiran manual.
- Tidak ada kaitan pembatasan presensi dengan hari libur pelayanan/menu.
- Batas minimal bekerja 4 jam, keluar otomatis setelah 14 jam, jeda masuk kembali 6 jam, dan anti-tap-ganda 60 detik tetap berlaku.
- Tap offline dapat memperbarui ketidakhadiran otomatis tanpa membuat sesi ganda. Izin/sakit/manual tidak ditimpa; konflik urutan tap meminta koreksi admin.
- Perubahan jadwal/penugasan yang sudah berjalan membuat versi mulai hari perubahan. Versi sebelumnya disimpan untuk riwayat dan tap offline.
- Penonaktifan menggunakan isian Aktif pada form Ubah; data historis tidak dihapus.
- Hasil ekspor dikelompokkan menggunakan snapshot divisi. Excel semua divisi berisi Rekap Semua dan sheet divisi yang memiliki data; filter satu divisi menghasilkan satu sheet.
- UID Excel tetap teks agar nol awal tidak hilang. Teks yang menyerupai formula tidak dijalankan.

## 5. Pengujian

Suite: AttendanceWorkScheduleTest, AttendanceReportsTest, VolunteerAttendanceFlowTest, AttendanceAutoCheckOutTest, AttendanceResetTest, AttendanceModuleReadinessTest.

Suite fitur memakai `IsolatedAttendanceDatabase`: semua koneksi diganti dengan satu koneksi `attendance_memory` berjenis SQLite `:memory:` sebelum membuat tabel uji. Tidak membaca/menulis data kerja, tidak menjalankan seeder, tidak menjalankan `migrate:fresh`.

Pengujian meliputi batas detik, lintas tengah malam, hari tidak terjadwal, tanggal efektif, konflik jadwal/penugasan, scope unit, snapshot, perubahan jadwal, rekonsiliasi offline, idempotensi, 4/6/14 jam, form Livewire, filter tanggal/divisi, akses, reset, serta isi PDF/Excel. Pemeriksaan sintaks PHP, pemformatan, dan build aset juga dijalankan.

## 6. Hasil dan pemeriksaan visual

Hasil: 54 pengujian lulus dengan 220 assertion. Pengujian khusus ekspor juga diulang setelah perapian pagination PDF: 6 pengujian lulus dengan 26 assertion. Pemeriksaan sintaks, pemformatan PHP, build aset, dan pemeriksaan diff lulus. Sampel PDF empat halaman diperiksa secara visual, termasuk pengulangan kepala tabel dan nomor halaman. Halaman form diuji melalui render dan aksi simpan Livewire; pemeriksaan browser pratinjau berkas lokal dibatasi oleh kebijakan browser.

## 7. Kompatibilitas data lama

- Tidak ada perhitungan keterlambatan retrospektif pada sesi yang sudah ada.
- Rekaman lama tanpa jadwal tetap dapat dilihat/diekspor, dengan keterangan jadwal kosong.
- Snapshot presensi yang sudah tersimpan tidak mengikuti perubahan nama divisi/jadwal saat ini.
- Sesi yang direset Super Admin tidak dibuat ulang diam-diam oleh penjadwal ketidakhadiran.
- Akun tanpa divisi aktif masih dapat tap, tetapi tidak diberi jadwal ataupun ketidakhadiran otomatis.
- Firmware dan format payload LCD (`pegawai` berisi nama) tidak berubah.

## 8. Langkah deployment VPS

Langkah berikut adalah petunjuk, **bukan perintah yang sudah dijalankan**.

1. Backup database dan berkas aplikasi, lalu periksa bahwa konfigurasi menunjuk database VPS yang benar. Jadwalkan jeda deployment singkat agar kode baru tidak digunakan sebelum kolom baru tersedia.
2. Unggah perubahan kode dan hasil build aset. Di server, gunakan direktori proyek Laravel yang benar.
3. Setelah persetujuan pengelola database, jalankan **hanya dua migration baru** berikut secara berurutan:

   ```sh
   php artisan migrate --path=database/migrations/2026_09_03_150000_add_work_schedules_to_attendance.php --force
   php artisan migrate --path=database/migrations/2026_09_03_151000_add_attendance_schedule_permission.php --force
   ```

   Jangan memakai `migrate:fresh`, `db:wipe`, atau seeder reset.

4. Perbarui cache konfigurasi/rute/view sesuai prosedur deployment server; restart worker jangka panjang jika digunakan. Bila membangun aset di VPS, jalankan `npm run build`.
5. Pastikan cron Laravel yang sudah ada memanggil `php artisan schedule:run` setiap menit dari direktori proyek. **Jangan membuat cron duplikat**. `attendance:auto-check-out` dan `attendance:mark-absent` sama-sama dijadwalkan setiap menit tanpa tumpang tindih.
6. Login sebagai Admin/Kepala SPPG; buka **Kepegawaian → Jam Kerja & Shift** dan isi jadwal asli. Akuntan tetap dapat melihat, mengoreksi, dan mengekspor presensi, tetapi tidak mengatur shift.
7. Uji satu pegawai/perangkat pada data uji yang disepakati: nama LCD, jam lokal, hasil keterlambatan, izin/sakit, ekspor. Ketidakhadiran baru muncul setelah jadwal yang valid berakhir dan penjadwal berjalan.

Tanpa menjalankan dua migration tersebut, kode baru belum siap digunakan pada database lama. Aplikasi Android/iOS tidak perlu dibangun ulang untuk perubahan presensi web/RFID ini.
