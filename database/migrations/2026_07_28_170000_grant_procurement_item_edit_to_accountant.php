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

        $permission = Permission::findOrCreate('procurement.update', 'web');

        Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', [UserRole::PengawasKeuangan->value, 'akuntan'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'procurement.update')
            ->first();

        if ($permission) {
            Role::query()
                ->where('guard_name', 'web')
                ->whereIn('name', [UserRole::PengawasKeuangan->value, 'akuntan'])
                ->get()
                ->each(fn (Role $role) => $role->revokePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
