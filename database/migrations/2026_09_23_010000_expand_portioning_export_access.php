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

        $portioningExport = Permission::findOrCreate('portioning.export', 'web');
        $operationalViews = collect([
            'preparation.view',
            'processing.view',
            'portioning.view',
            'distribution.view',
            'washing.view',
            'cleaning.view',
        ])->map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'));

        Role::findOrCreate(UserRole::AsistenLapangan->value, 'web')
            ->givePermissionTo($operationalViews);

        foreach ([
            UserRole::SuperAdmin,
            UserRole::AdminSppg,
            UserRole::KepalaSppg,
            UserRole::AsistenLapangan,
            UserRole::AhliGizi,
            UserRole::KepalaDivisiPemorsian,
            UserRole::PetugasPemorsian,
            UserRole::Viewer,
        ] as $role) {
            Role::findOrCreate($role->value, 'web')->givePermissionTo($portioningExport);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $portioningExport = Permission::findOrCreate('portioning.export', 'web');
        foreach ([
            UserRole::AdminSppg,
            UserRole::KepalaSppg,
            UserRole::AhliGizi,
            UserRole::KepalaDivisiPemorsian,
            UserRole::PetugasPemorsian,
            UserRole::Viewer,
        ] as $role) {
            Role::findOrCreate($role->value, 'web')->revokePermissionTo($portioningExport);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
