<?php

namespace App\Console\Commands;

use App\Support\AccessControl;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AuditAccessControl extends Command
{
    protected $signature = 'sppg:access-audit';
    protected $description = 'Memeriksa konsistensi role dan permission global aplikasi SPPG.';

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $roles = Role::query()->where('guard_name', 'web')->with('permissions')->get()->keyBy('name');
        $hasError = false;
        $rows = [];

        foreach (AccessControl::rolePermissions() as $roleName => $expected) {
            $role = $roles->get($roleName);
            if (! $role) {
                $rows[] = [$roleName, 'TIDAK ADA', count($expected), '-', '-'];
                $hasError = true;
                continue;
            }
            $actual = $role->permissions->pluck('name')->all();
            $missing = array_values(array_diff($expected, $actual));
            $extra = array_values(array_diff($actual, $expected));
            $hasError = $hasError || $missing !== [] || $extra !== [];
            $rows[] = [$roleName, 'Ada', count($expected), $missing ? implode(', ', $missing) : '-', $extra ? implode(', ', $extra) : '-'];
        }

        $this->table(['Role', 'Status', 'Target Permission', 'Permission Kurang', 'Permission Berlebih'], $rows);
        $unexpected = $roles->keys()->diff(array_keys(AccessControl::rolePermissions()));
        if ($unexpected->isNotEmpty()) {
            $hasError = true;
            $this->warn('Role tidak dikenal: '.$unexpected->implode(', '));
        }

        $hasError ? $this->error('Audit akses menemukan masalah.') : $this->info('Role dan permission global konsisten.');
        return $hasError ? self::FAILURE : self::SUCCESS;
    }
}
