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

        $approve = Permission::findOrCreate('portioning.approve', 'web');
        $export = Permission::findOrCreate('portioning.export', 'web');

        Role::findOrCreate(UserRole::KepalaSppg->value, 'web')
            ->givePermissionTo($approve)
            ->revokePermissionTo($export);

        Role::findOrCreate(UserRole::AsistenLapangan->value, 'web')
            ->revokePermissionTo($approve)
            ->givePermissionTo($export);

        Role::findOrCreate(UserRole::KepalaDivisiPemorsian->value, 'web')
            ->revokePermissionTo($export);

        Role::findOrCreate(UserRole::PetugasPemorsian->value, 'web')
            ->revokePermissionTo($export);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $approve = Permission::findOrCreate('portioning.approve', 'web');
        $export = Permission::findOrCreate('portioning.export', 'web');

        Role::findOrCreate(UserRole::KepalaSppg->value, 'web')
            ->revokePermissionTo($approve)
            ->givePermissionTo($export);

        Role::findOrCreate(UserRole::AsistenLapangan->value, 'web')
            ->givePermissionTo($approve)
            ->givePermissionTo($export);

        Role::findOrCreate(UserRole::KepalaDivisiPemorsian->value, 'web')
            ->givePermissionTo($export);

        Role::findOrCreate(UserRole::PetugasPemorsian->value, 'web')
            ->givePermissionTo($export);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
