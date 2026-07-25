# SPPG Mobile

Aplikasi Android native Kotlin untuk seluruh petugas operasional SPPG. Versi saat ini mencakup autentikasi Laravel Sanctum, penyimpanan sesi lokal, dashboard berbasis peran, workflow Rencana Lapangan H-3, serta daftar dan rincian pekerjaan Gudang, Persiapan, Pengolahan, Pemorsian, Distribusi, Pencucian, dan Kebersihan.

## Menjalankan dari Android Studio

1. Buka folder `android` sebagai proyek Android Studio.
2. Jalankan backend dari root proyek dengan `php artisan serve --host=0.0.0.0`.
3. Pilih emulator atau perangkat Android, kemudian jalankan konfigurasi `app`.

Alamat API bawaan adalah `http://10.0.2.2:8000/api/mobile/`, yaitu alamat backend komputer dari Android Emulator. Untuk perangkat Android fisik, ubah `api_base_url` pada `app/src/main/res/values/strings.xml` menjadi alamat IP komputer pada jaringan lokal, misalnya `http://192.168.1.10:8000/api/mobile/`.

## Endpoint backend

- `POST /api/mobile/login`
- `GET /api/mobile/user`
- `POST /api/mobile/logout`
- `GET /api/mobile/field-plans`
- `GET /api/mobile/field-plans/{id}`
- `PUT /api/mobile/field-plans/{id}`
- `GET /api/mobile/field-plans/{id}/readiness`
- `POST /api/mobile/field-plans/{id}/activate`
- `GET /api/mobile/operational-modules`
- `GET /api/mobile/operational-modules/{module}/records`
- `GET /api/mobile/operational-modules/{module}/records/{id}`

Login menerima email atau nomor pegawai yang sama dengan aplikasi web SPPG V3.
Daftar ruang kerja diambil dari server sesuai role dan permission pengguna. Asisten
Lapangan menerima modul Insiden dan Laporan Harian selain Rencana Lapangan; Staf
Gudang menerima Penerimaan, Stok, Pengambilan, dan Retur; setiap petugas/kepala
divisi menerima ruang kerja Persiapan, Pengolahan, Pemorsian, Distribusi,
Pencucian, atau Kebersihan sesuai perannya.

## Cakupan interaksi

- Rencana Lapangan mendukung daftar, rincian, perubahan konfirmasi penerima/rute,
  pemeriksaan kesiapan, dan aktivasi langsung dari Android.
- Insiden Lapangan, Laporan Harian, empat ruang kerja Gudang, dan enam ruang
  kerja divisi menyediakan dashboard, daftar, serta rincian operasional.
- Pencatatan transaksi, unggah bukti, verifikasi Gudang, perubahan tahap kerja,
  serah-terima, dan persetujuan laporan untuk ruang kerja operasional masih
  dilakukan melalui antarmuka web SPPG V3.
