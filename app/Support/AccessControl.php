<?php

namespace App\Support;

use App\Enums\UserRole;

final class AccessControl
{
    public const LEGACY_ROLES = [
        'akuntan',
        'kepala_divisi',
        'petugas_divisi',
    ];

    /**
     * Permission disimpan terpusat agar seeder tambahan tidak saling menimpa.
     *
     * @return array<string, array<int, string>>
     */
    public static function permissionDefinitions(): array
    {
        $crud = ['view', 'create', 'update', 'delete'];
        $operational = ['view', 'create', 'update', 'delete', 'submit', 'approve', 'export'];

        return [
            'dashboard' => ['view'],
            'units' => $crud,
            'organization' => ['view', 'manage'],
            'users' => [...$crud, 'assign_role'],
            'divisions' => ['view', 'manage'],
            'settings' => ['manage'],
            'audit_logs' => ['view'],
            'notifications' => ['view', 'manage'],
            'reports' => ['view', 'export'],

            'schools' => [...$crud, 'import', 'export'],
            'posyandus' => [...$crud, 'import', 'export'],
            'beneficiaries' => [...$crud, 'import', 'export'],
            'beneficiary_periods' => [...$crud, 'import', 'copy', 'submit', 'approve', 'activate', 'close', 'export'],
            'daily_beneficiary_confirmations' => ['view', 'create', 'update', 'delete', 'submit', 'export'],

            'menus' => [...$crud, 'submit', 'approve', 'activate', 'export'],
            'nutrition' => ['view', 'manage', 'approve', 'export'],
            'measurement_units' => ['view', 'manage'],
            'ingredients' => [...$crud, 'export'],
            'allergens' => ['view', 'manage'],
            'food_safety' => ['view', 'manage', 'approve'],

            'suppliers' => $crud,
            'procurement' => [...$crud, 'submit', 'select_supplier', 'price_input', 'approve', 'finalize_price', 'order', 'export'],
            'stock' => [...$crud, 'approve', 'export'],

            'preparation' => [...$operational, 'import'],
            'processing' => $operational,
            'portioning' => $operational,
            'distribution' => $operational,
            'washing' => $operational,
            'cleaning' => $operational,

            'field_planning' => [...$crud, 'submit', 'export'],
            'field_daily_reports' => ['view', 'export'],
            'field_incidents' => ['view', 'create', 'update', 'resolve'],
            'incidents' => ['view', 'create', 'update', 'close'],
            'sanitation' => ['view', 'manage', 'verify'],
            'security' => ['view', 'create', 'update', 'close'],

            'finance' => ['view', 'create', 'update', 'verify', 'approve', 'close_period', 'export'],
            'head_dashboard' => ['view'],
        ];
    }

    /** @return array<int, string> */
    public static function permissionNames(): array
    {
        $permissions = [];

        foreach (self::permissionDefinitions() as $module => $actions) {
            foreach ($actions as $action) {
                $permissions[] = "{$module}.{$action}";
            }
        }

        return array_values(array_unique($permissions));
    }

    /** @return array<string, array<int, string>> */
    public static function rolePermissions(): array
    {
        $roles = [
            UserRole::SuperAdmin->value => self::permissionNames(),
            UserRole::KepalaSppg->value => self::kepalaSppgPermissions(),
            UserRole::AdminSppg->value => self::adminPermissions(),
            UserRole::AsistenLapangan->value => self::fieldAssistantPermissions(),
            UserRole::AhliGizi->value => self::nutritionistPermissions(),
            UserRole::PengawasKeuangan->value => self::financeSupervisorPermissions(),
            // Kompatibilitas akun lama yang masih memakai role `akuntan`.
            'akuntan' => self::financeSupervisorPermissions(),
            UserRole::StafGudang->value => self::warehousePermissions(),
            UserRole::Satpam->value => self::securityPermissions(),
            UserRole::Viewer->value => self::viewerPermissions(),
        ];

        foreach (DivisionRole::DIVISIONS as $definition) {
            $roles[$definition['head_role']] = self::divisionHeadPermissions($definition['permission_prefix']);
            $roles[$definition['staff_role']] = self::divisionStaffPermissions($definition['permission_prefix']);
        }

        return array_map(
            static fn (array $permissions): array => array_values(array_unique($permissions)),
            $roles,
        );
    }

    /** @return array<int, string> */
    public static function permissionsForRole(string $roleName): array
    {
        return self::rolePermissions()[$roleName] ?? [];
    }

    /** @return array<int, string> */
    private static function kepalaSppgPermissions(): array
    {
        return [
            // Dashboard, profil organisasi, audit, notifikasi, dan laporan lintas-modul.
            'dashboard.view',
            'organization.view',
            'audit_logs.view',
            'notifications.view',
            'reports.view',
            'reports.export',

            // Master penerima hanya untuk pemantauan. Kepala SPPG tidak melakukan CRUD teknis.
            ...self::module('schools', ['view']),
            ...self::module('posyandus', ['view']),
            ...self::module('beneficiaries', ['view']),
            ...self::module('beneficiary_periods', ['view', 'approve', 'activate', 'close', 'export']),

            // Menu disusun Ahli Gizi dan disetujui Kepala SPPG.
            ...self::module('menus', ['view', 'approve', 'activate', 'export']),
            ...self::module('nutrition', ['view', 'approve', 'export']),
            ...self::module('measurement_units', ['view']),
            ...self::module('ingredients', ['view']),
            ...self::module('allergens', ['view']),
            ...self::module('food_safety', ['view', 'approve']),

            // Pengadaan dan gudang hanya dipantau. Approval pembelian tetap milik Pengawas Keuangan.
            ...self::module('suppliers', ['view']),
            ...self::module('procurement', ['view', 'finalize_price', 'export']),
            ...self::module('stock', ['view']),
            ...self::module('finance', ['view']),

            // Approval rencana H-3 dan laporan Asisten Lapangan.
            ...self::module('field_planning', ['view', 'export']),
            ...self::module('field_daily_reports', ['view', 'export']),
            ...self::module('field_incidents', ['view']),

            // Kepala SPPG memberi persetujuan akhir laporan divisi selain Pemorsian,
            // tetapi tidak boleh membuat atau mengubah transaksi operasional.
            ...self::module('preparation', ['view', 'approve', 'export']),
            ...self::module('processing', ['view', 'approve', 'export']),
            ...self::module('portioning', ['view', 'export']),
            ...self::module('distribution', ['view', 'approve', 'export']),
            ...self::module('washing', ['view', 'approve', 'export']),
            ...self::module('cleaning', ['view', 'approve', 'export']),

            // Pengawasan umum.
            ...self::module('incidents', ['view', 'close']),
            ...self::module('sanitation', ['view']),
            ...self::module('security', ['view']),

            // Modul khusus Kepala SPPG.
            'head_dashboard.view',
        ];
    }

    /** @return array<int, string> */
    private static function adminPermissions(): array
    {
        return [
            'dashboard.view',
            'organization.view', 'organization.manage',
            'users.view', 'users.create', 'users.update', 'users.delete', 'users.assign_role',
            'divisions.view', 'divisions.manage',
            'settings.manage', 'audit_logs.view', 'notifications.view', 'notifications.manage',
            'reports.view', 'reports.export',
            ...self::module('schools', ['view', 'create', 'update', 'delete', 'import', 'export']),
            ...self::module('posyandus', ['view', 'create', 'update', 'delete', 'import', 'export']),
            ...self::module('beneficiaries', ['view', 'create', 'update', 'delete', 'import', 'export']),
            ...self::module('beneficiary_periods', ['view', 'create', 'update', 'delete', 'import', 'copy', 'submit', 'export']),
            ...self::module('menus', ['view']),
            ...self::module('nutrition', ['view']),
            ...self::module('measurement_units', ['view', 'manage']),
            ...self::module('ingredients', ['view', 'create', 'update', 'delete', 'export']),
            ...self::module('allergens', ['view', 'manage']),
            ...self::module('suppliers', ['view', 'create', 'update', 'delete']),
            ...self::module('procurement', ['view']),
            ...self::module('stock', ['view']),
            ...self::operationalViewPermissions(),
            ...self::module('field_planning', ['view']),
            ...self::module('field_daily_reports', ['view']),
            ...self::module('field_incidents', ['view']),
            ...self::module('incidents', ['view']),
            ...self::module('security', ['view']),
        ];
    }

    /** @return array<int, string> */
    private static function fieldAssistantPermissions(): array
    {
        return [
            'dashboard.view', 'reports.view', 'reports.export',
            ...self::module('schools', ['view', 'create', 'update', 'delete', 'import', 'export']),
            ...self::module('posyandus', ['view', 'create', 'update', 'delete', 'import', 'export']),
            ...self::module('beneficiaries', ['view', 'create', 'update', 'delete', 'import', 'export']),
            ...self::module('beneficiary_periods', ['view', 'create', 'update', 'delete', 'import', 'copy', 'submit', 'export']),
            ...self::module('menus', ['view']),
            ...self::module('nutrition', ['view']),
            ...self::module('field_planning', ['view', 'create', 'update', 'delete', 'submit', 'export']),
            ...self::module('field_daily_reports', ['view', 'export']),
            ...self::module('field_incidents', ['view', 'create', 'update', 'resolve']),
            ...self::module('incidents', ['view', 'create', 'update']),
            ...self::operationalViewPermissions(),
            ...self::module('portioning', ['approve', 'export']),
            ...self::module('sanitation', ['view', 'manage', 'verify']),
            ...self::module('security', ['view']),
        ];
    }

    /** @return array<int, string> */
    private static function nutritionistPermissions(): array
    {
        return [
            'dashboard.view', 'reports.view', 'reports.export',
            ...self::module('beneficiaries', ['view']),
            ...self::module('beneficiary_periods', ['view']),
            ...self::module('menus', ['view', 'create', 'update', 'delete', 'submit', 'export']),
            ...self::module('nutrition', ['view', 'manage', 'export']),
            ...self::module('measurement_units', ['view', 'manage']),
            ...self::module('ingredients', ['view', 'create', 'update', 'delete', 'export']),
            ...self::module('allergens', ['view', 'manage']),
            ...self::module('food_safety', ['view', 'manage']),
            ...self::module('field_planning', ['view']),
            ...self::module('suppliers', ['view']),
            // Ahli Gizi adalah pengaju kebutuhan bahan dan boleh mengisi estimasi harga
            // sampai Kepala SPPG mengunci harga final.
            ...self::module('procurement', ['view', 'create', 'update', 'submit', 'price_input', 'export']),
            ...self::module('stock', ['view']),
            ...self::operationalViewPermissions(),
        ];
    }

    /** @return array<int, string> */
    private static function financeSupervisorPermissions(): array
    {
        return [
            'dashboard.view', 'reports.view', 'reports.export',
            ...self::module('finance', ['view', 'verify', 'approve', 'export']),
            // Akuntan/Pengawas Keuangan dapat mengoreksi daftar bahan, jumlah,
            // satuan, dan catatan sampai proses Verifikasi Keuangan dilakukan.
            // Permission submit/create tidak diberikan agar pemisahan pengajuan
            // dan pemeriksaan keuangan tetap terjaga.
            ...self::module('procurement', ['view', 'update', 'price_input', 'approve', 'export', 'submit']),
            ...self::module('stock', ['view']),
            ...self::module('suppliers', ['view']),
            ...self::module('ingredients', ['view']),
            ...self::module('menus', ['view']),
        ];
    }

    /** @return array<int, string> */
    private static function warehousePermissions(): array
    {
        return [
            'dashboard.view', 'reports.view', 'reports.export',
            ...self::module('suppliers', ['view', 'create', 'update', 'delete']),
            ...self::module('procurement', ['view', 'select_supplier', 'order', 'export']),
            ...self::module('stock', ['view', 'create', 'update', 'delete', 'approve', 'export']),
            ...self::module('ingredients', ['view']),
            ...self::module('nutrition', ['view']),
        ];
    }

    /** @return array<int, string> */
    private static function securityPermissions(): array
    {
        return [
            'dashboard.view', 'reports.view',
            ...self::module('security', ['view', 'create', 'update', 'close']),
            ...self::module('incidents', ['view', 'create', 'update']),
            ...self::module('field_incidents', ['view', 'create', 'update']),
        ];
    }

    /** @return array<int, string> */
    private static function viewerPermissions(): array
    {
        return [
            'dashboard.view', 'organization.view', 'reports.view', 'reports.export',
            'schools.view', 'posyandus.view', 'beneficiaries.view',
            'beneficiary_periods.view',
            'menus.view', 'nutrition.view', 'measurement_units.view', 'ingredients.view', 'allergens.view',
            'suppliers.view', 'procurement.view', 'stock.view',
            ...self::operationalViewPermissions(),
            'field_planning.view', 'field_daily_reports.view', 'field_incidents.view', 'incidents.view',
            'food_safety.view', 'sanitation.view', 'security.view', 'finance.view',
        ];
    }

    /** @return array<int, string> */
    private static function divisionHeadPermissions(string $prefix): array
    {
        return [
            'dashboard.view', 'reports.view', 'reports.export',
            'menus.view', 'ingredients.view', 'nutrition.view',
            'incidents.view', 'incidents.create', 'incidents.update', 'incidents.close',
            'security.view',
            ...self::module($prefix, ['view', 'create', 'update', 'delete', 'submit', 'approve', 'export']),
        ];
    }

    /** @return array<int, string> */
    private static function divisionStaffPermissions(string $prefix): array
    {
        return [
            'dashboard.view', 'reports.view',
            'menus.view', 'ingredients.view',
            'incidents.view', 'incidents.create', 'incidents.update',
            ...self::module($prefix, ['view', 'create', 'update', 'delete', 'submit', 'export']),
        ];
    }

    /** @return array<int, string> */
    private static function operationalViewPermissions(): array
    {
        return array_map(
            static fn (array $definition): string => $definition['permission_prefix'].'.view',
            DivisionRole::DIVISIONS,
        );
    }

    /** @param array<int, string> $actions
     * @return array<int, string>
     */
    private static function module(string $module, array $actions): array
    {
        return array_map(
            static fn (string $action): string => "{$module}.{$action}",
            $actions,
        );
    }
}
