<?php

use App\Support\AccessControl;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (AccessControl::permissionNames() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Pada mode --pretend query INSERT tidak benar-benar dijalankan. Hindari
        // lookup permission berikutnya yang pasti gagal, tanpa mengubah perilaku
        // migration saat benar-benar diterapkan di production.
        if (in_array('--pretend', $_SERVER['argv'] ?? [], true)) {
            return;
        }

        foreach (AccessControl::rolePermissions() as $roleName => $permissionNames) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissionNames);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permission tidak dihapus agar role produksi dan histori audit tetap aman.
    }
};
