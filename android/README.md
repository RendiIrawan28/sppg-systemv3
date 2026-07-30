# SPPG Mobile

Aplikasi Android native Kotlin untuk seluruh petugas operasional SPPG. Versi saat ini mencakup autentikasi Laravel Sanctum, penyimpanan sesi lokal, dashboard berbasis peran, workflow Rencana Lapangan H-3, serta pekerjaan Persiapan, Pengolahan, Pemorsian, Distribusi, Pencucian, Kebersihan, dan Keamanan (Satpam).

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
- `POST /api/mobile/operational-modules/{module}/records`
- `GET /api/mobile/operational-modules/{module}/records/{id}`
- `PUT /api/mobile/operational-modules/{module}/records/{id}`
- `DELETE /api/mobile/operational-modules/{module}/records/{id}`
- `POST /api/mobile/operational-modules/{module}/records/{id}/actions/{action}`
- `POST /api/mobile/operational-modules/{module}/records/{id}/relations/{relation}`
- `PUT /api/mobile/operational-modules/{module}/records/{id}/relations/{relation}/{item}`
- `DELETE /api/mobile/operational-modules/{module}/records/{id}/relations/{relation}/{item}`
- `POST /api/mobile/operational-modules/{module}/records/{id}/relations/{relation}/{item}/actions/{action}`

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
  kerja divisi menyediakan dashboard, daftar, rincian, tambah, ubah, dan hapus
  data yang masih aman untuk diedit.
- Tahap pekerjaan utama, pengajuan laporan, persetujuan, dan permintaan revisi
  dapat dilakukan langsung dari rincian pekerjaan.
- Persiapan sampai Distribusi mendukung input rincian langsung dari Android:
  hasil bahan Persiapan, dokumentasi, bahan Pengolahan, suhu makanan, hasil
  makanan jadi, alokasi dan hasil Pemorsian per rute, sisa makanan, tujuan
  Distribusi, penerima, jumlah porsi, ompreng, dan status pengiriman.
- Foto bukti dapat diambil dengan kamera atau dipilih dari galeri. Foto
  dikompresi sebelum dikirim dan dibatasi maksimal 5 MB.
- Data yang sudah mulai dikerjakan tidak dapat dihapus. Sesi Persiapan dibuat
  otomatis dari pengambilan Gudang, sedangkan Satpam dapat memulai shift dengan
  satu ketukan.
- Pencucian mendukung penerimaan ompreng, konfirmasi/jenis limbah, checklist,
  foto hasil, penyelesaian, dan pengajuan laporan. Jika terdapat limbah,
  berita acara beserta itemnya dibuat dan diajukan dari aplikasi.
- Kebersihan mendukung checklist tiap area, bahan pembersih, dokumentasi sebelum
  atau sesudah, temuan, tindakan koreksi, berita acara limbah yang terhubung
  ke sesi sumber, serta laporan harian.
- Satpam dapat memulai shift 12 jam dengan satu ketukan dan mengirim laporan
  situasi berfoto setiap tiga jam. Shift selesai otomatis setelah target laporan
  terpenuhi.
