<?php

namespace App\Support;

final class DivisionRole
{
    public const DIVISIONS = [
        'persiapan' => [
            'label' => 'Persiapan',
            'permission_prefix' => 'preparation',
            'head_role' => 'kepala_divisi_persiapan',
            'staff_role' => 'petugas_persiapan',
        ],
        'pengolahan' => [
            'label' => 'Pengolahan',
            'permission_prefix' => 'processing',
            'head_role' => 'kepala_divisi_pengolahan',
            'staff_role' => 'petugas_pengolahan',
        ],
        'pemorsian' => [
            'label' => 'Pemorsian',
            'permission_prefix' => 'portioning',
            'head_role' => 'kepala_divisi_pemorsian',
            'staff_role' => 'petugas_pemorsian',
        ],
        'distribusi' => [
            'label' => 'Distribusi',
            'permission_prefix' => 'distribution',
            'head_role' => 'kepala_divisi_distribusi',
            'staff_role' => 'petugas_distribusi',
        ],
        'pencucian' => [
            'label' => 'Pencucian',
            'permission_prefix' => 'washing',
            'head_role' => 'kepala_divisi_pencucian',
            'staff_role' => 'petugas_pencucian',
        ],
        'kebersihan' => [
            'label' => 'Kebersihan',
            'permission_prefix' => 'cleaning',
            'head_role' => 'kepala_divisi_kebersihan',
            'staff_role' => 'petugas_kebersihan',
        ],
    ];

    /** @return array<int, string> */
    public static function headRoleNames(): array
    {
        return array_column(self::DIVISIONS, 'head_role');
    }

    /** @return array<int, string> */
    public static function staffRoleNames(): array
    {
        return array_column(self::DIVISIONS, 'staff_role');
    }

    /** @return array<int, string> */
    public static function allRoleNames(): array
    {
        return [...self::headRoleNames(), ...self::staffRoleNames()];
    }

    public static function isHeadRole(?string $roleName): bool
    {
        return filled($roleName) && in_array($roleName, self::headRoleNames(), true);
    }

    public static function isStaffRole(?string $roleName): bool
    {
        return filled($roleName) && in_array($roleName, self::staffRoleNames(), true);
    }

    public static function isDivisionRole(?string $roleName): bool
    {
        return self::isHeadRole($roleName) || self::isStaffRole($roleName);
    }

    public static function divisionCodeForRole(?string $roleName): ?string
    {
        foreach (self::DIVISIONS as $code => $definition) {
            if (in_array($roleName, [$definition['head_role'], $definition['staff_role']], true)) {
                return $code;
            }
        }

        return null;
    }

    public static function permissionPrefixForDivision(?string $divisionCode): ?string
    {
        return self::DIVISIONS[$divisionCode]['permission_prefix'] ?? null;
    }

    public static function headRoleForDivision(?string $divisionCode): ?string
    {
        return self::DIVISIONS[$divisionCode]['head_role'] ?? null;
    }

    public static function staffRoleForDivision(?string $divisionCode): ?string
    {
        return self::DIVISIONS[$divisionCode]['staff_role'] ?? null;
    }

    public static function positionForRole(?string $roleName): ?string
    {
        return match (true) {
            self::isHeadRole($roleName) => 'head',
            self::isStaffRole($roleName) => 'staff',
            default => null,
        };
    }
}
