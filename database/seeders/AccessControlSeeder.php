<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Division;
use App\Models\SppgUnit;
use App\Models\User;
use App\Support\AccessControl;
use App\Support\DivisionRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        SppgUnit::query()->firstOrCreate(
            ['code' => 'SPPG-001'],
            ['name' => 'SPPG NOGOTIRTO', 'slug' => Str::slug('SPPG-001-SPPG NOGOTIRTO'), 'address' => 'Niten, Nogotirto, Gamping, Sleman', 'is_active' => true],
        );

        foreach (DivisionRole::DIVISIONS as $code => $definition) {
            Division::query()->updateOrCreate(['code' => $code], [
                'name' => $definition['label'],
                'sort_order' => array_search($code, array_keys(DivisionRole::DIVISIONS), true) + 1,
                'is_active' => true,
            ]);
        }

        foreach (AccessControl::permissionNames() as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }
        foreach (AccessControl::rolePermissions() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }

        $user = User::query()->updateOrCreate(['email' => 'admin@sppg.test'], [
            'name' => 'Super Admin', 'password' => Hash::make('password123'),
            'is_active' => true, 'is_super_admin' => true,
        ]);
        $user->syncRoles([UserRole::SuperAdmin->value]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
