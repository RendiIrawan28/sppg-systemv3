<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case AdminSppg = 'admin_sppg';
    case KepalaSppg = 'kepala_sppg';
    case AsistenLapangan = 'asisten_lapangan';
    case AhliGizi = 'ahli_gizi';
    case PengawasKeuangan = 'pengawas_keuangan';
    case StafGudang = 'staf_gudang';

    case KepalaDivisiPersiapan = 'kepala_divisi_persiapan';
    case KepalaDivisiPengolahan = 'kepala_divisi_pengolahan';
    case KepalaDivisiPemorsian = 'kepala_divisi_pemorsian';
    case KepalaDivisiDistribusi = 'kepala_divisi_distribusi';
    case KepalaDivisiPencucian = 'kepala_divisi_pencucian';
    case KepalaDivisiKebersihan = 'kepala_divisi_kebersihan';

    case PetugasPersiapan = 'petugas_persiapan';
    case PetugasPengolahan = 'petugas_pengolahan';
    case PetugasPemorsian = 'petugas_pemorsian';
    case PetugasDistribusi = 'petugas_distribusi';
    case PetugasPencucian = 'petugas_pencucian';
    case PetugasKebersihan = 'petugas_kebersihan';

    case Satpam = 'satpam';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::AdminSppg => 'Admin SPPG',
            self::KepalaSppg => 'Kepala SPPG',
            self::AsistenLapangan => 'Asisten Lapangan',
            self::AhliGizi => 'Ahli Gizi',
            self::PengawasKeuangan => 'Pengawas Keuangan',
            self::StafGudang => 'Staf Gudang',
            self::KepalaDivisiPersiapan => 'Kepala Divisi Persiapan',
            self::KepalaDivisiPengolahan => 'Kepala Divisi Pengolahan',
            self::KepalaDivisiPemorsian => 'Kepala Divisi Pemorsian',
            self::KepalaDivisiDistribusi => 'Kepala Divisi Distribusi',
            self::KepalaDivisiPencucian => 'Kepala Divisi Pencucian',
            self::KepalaDivisiKebersihan => 'Kepala Divisi Kebersihan',
            self::PetugasPersiapan => 'Petugas Persiapan',
            self::PetugasPengolahan => 'Petugas Pengolahan',
            self::PetugasPemorsian => 'Petugas Pemorsian',
            self::PetugasDistribusi => 'Petugas Distribusi',
            self::PetugasPencucian => 'Petugas Pencucian',
            self::PetugasKebersihan => 'Petugas Kebersihan',
            self::Satpam => 'Satpam',
            self::Viewer => 'Viewer / Auditor',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Akses teknis penuh seluruh unit dan pengaturan sistem.',
            self::AdminSppg => 'Mengelola pengguna, master data, dan administrasi sistem.',
            self::KepalaSppg => 'Memantau seluruh proses dan memberikan persetujuan akhir.',
            self::AsistenLapangan => 'Mengelola penerima, rencana H-3, jadwal distribusi, dan laporan lapangan.',
            self::AhliGizi => 'Mengelola menu, resep, kebutuhan bahan, gizi, dan food safety.',
            self::PengawasKeuangan => 'Memeriksa dan menyetujui permintaan pembelian.',
            self::StafGudang => 'Mengelola supplier, pemesanan, penerimaan, stok, dan serah bahan.',
            self::Satpam => 'Mencatat pemeriksaan keamanan, akses, fasilitas, dan insiden.',
            self::Viewer => 'Akses baca tanpa perubahan data.',
            default => str_starts_with($this->value, 'kepala_divisi_')
                ? 'Mengelola dan menyetujui pekerjaan hanya pada divisinya.'
                : 'Mencatat dan mengajukan pekerjaan hanya pada divisinya.',
        };
    }

    public function isAssignable(): bool
    {
        return $this !== self::SuperAdmin;
    }

    public function sortOrder(): int
    {
        return array_search($this, self::cases(), true);
    }

    /** @return array<int, string> */
    public static function assignableValues(): array
    {
        return array_values(array_map(
            static fn (self $role): string => $role->value,
            array_filter(self::cases(), static fn (self $role): bool => $role->isAssignable()),
        ));
    }

    public static function sortOrderFor(?string $role): int
    {
        return self::tryFrom((string) $role)?->sortOrder() ?? 999;
    }

    public static function labelFor(?string $role): string
    {
        if (blank($role)) {
            return '-';
        }

        $legacyLabels = [
            'akuntan' => 'Akuntan (Role Lama)',
            'kepala_divisi' => 'Kepala Divisi (Role Lama)',
            'petugas_divisi' => 'Petugas Divisi (Role Lama)',
        ];

        return self::tryFrom($role)?->label()
            ?? $legacyLabels[$role]
            ?? str($role)->replace('_', ' ')->title()->toString();
    }
}
