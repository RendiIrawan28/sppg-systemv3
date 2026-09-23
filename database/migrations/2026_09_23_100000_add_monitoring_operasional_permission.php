<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('monitoring_operasional.view', 'web');

        foreach ([
            UserRole::SuperAdmin->value,
            UserRole::KepalaSppg->value,
            UserRole::AdminSppg->value,
            UserRole::AhliGizi->value,
            UserRole::AsistenLapangan->value,
            UserRole::PengawasKeuangan->value,
            'akuntan',
        ] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'monitoring_operasional.view')
            ->first();

        if (! $permission) {
            return;
        }

        foreach ([
            UserRole::SuperAdmin->value,
            UserRole::KepalaSppg->value,
            UserRole::AdminSppg->value,
            UserRole::AhliGizi->value,
            UserRole::AsistenLapangan->value,
            UserRole::PengawasKeuangan->value,
            'akuntan',
        ] as $roleName) {
            Role::query()
                ->where('guard_name', 'web')
                ->where('name', $roleName)
                ->first()?->revokePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
