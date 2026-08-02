# Firmware presensi RFID SPPG

Perangkat yang didukung: ESP8266 NodeMCU, MFRC522, LCD I2C 16×2 alamat `0x27`, LED hijau dan merah dengan susunan pin yang sama seperti firmware lama.

## Pemasangan

1. Buka **Presensi relawan → Perangkat** di website.
2. Buat perangkat dan segera salin `DEVICE_CODE` serta `DEVICE_KEY`.
3. Masukkan keduanya pada bagian konfigurasi awal `presensi-esp8266.ino`.
4. Pastikan `SERVER_URL` dapat diakses oleh jaringan Wi-Fi perangkat.
5. Pasang library WiFiManager, MFRC522, dan LiquidCrystal_I2C pada Arduino IDE.
6. Pilih board **NodeMCU 1.0 (ESP-12E Module)**, aktifkan LittleFS, lalu unggah firmware.
7. Saat pertama menyala, sambungkan ponsel ke `SPPG-PRESENSI-SETUP` dengan sandi `12345678` untuk memilih Wi-Fi.

Nama relawan selalu diambil dari server dan tampil di baris pertama LCD. Tap yang terjadi saat internet putus disimpan di LittleFS lalu disinkronkan setelah koneksi kembali. Untuk keamanan produksi, ubah `SERVER_URL` menjadi domain HTTPS setelah sertifikat server tersedia.
