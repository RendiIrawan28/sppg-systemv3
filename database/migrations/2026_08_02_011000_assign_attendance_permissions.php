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
        $permissions = collect(['view', 'manage', 'correct', 'export', 'devices'])
            ->mapWithKeys(fn (string $action) => [$action => Permission::findOrCreate("attendance.{$action}", 'web')]);

        Role::findOrCreate(UserRole::KepalaSppg->value, 'web')->givePermissionTo($permissions->values());
        Role::findOrCreate(UserRole::AdminSppg->value, 'web')->givePermissionTo($permissions->values());
        Role::findOrCreate(UserRole::PengawasKeuangan->value, 'web')->givePermissionTo($permissions->only(['view', 'correct', 'export'])->values());
        Role::findOrCreate('akuntan', 'web')->givePermissionTo($permissions->only(['view', 'correct', 'export'])->values());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([UserRole::KepalaSppg->value, UserRole::AdminSppg->value, UserRole::PengawasKeuangan->value, 'akuntan'] as $role) {
            Role::findOrCreate($role, 'web')->revokePermissionTo(Permission::query()->whereIn('name', ['attendance.view', 'attendance.manage', 'attendance.correct', 'attendance.export', 'attendance.devices'])->get());
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
