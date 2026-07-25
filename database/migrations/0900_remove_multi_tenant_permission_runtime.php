<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('roles', 'sppg_unit_id')) {
            $this->rebuildPermissionTablesWithoutTeams();
        }

        Schema::dropIfExists('sppg_unit_user');

        if (Schema::hasColumn('users', 'last_active_sppg_unit_id')) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                Schema::table('users', fn (Blueprint $table) => $table->dropIndex(['last_active_sppg_unit_id']));
            }
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_active_sppg_unit_id'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'last_active_sppg_unit_id')) {
            Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('last_active_sppg_unit_id')->nullable()->index());
        }

        if (! Schema::hasTable('sppg_unit_user')) {
            Schema::create('sppg_unit_user', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sppg_unit_id')->constrained('sppg_units')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->boolean('is_primary')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['sppg_unit_id', 'user_id']);
            });
        }
    }

    private function rebuildPermissionTablesWithoutTeams(): void
    {
        $unitId = DB::table('sppg_units')->where('is_active', true)->orderBy('id')->value('id');
        $roles = DB::table('roles')->orderBy('id')->get();
        $rolePermissions = DB::table('role_has_permissions')->get();
        $modelRoles = DB::table('model_has_roles')->get();
        $modelPermissions = DB::table('model_has_permissions')->get();

        $canonicalByKey = [];
        foreach ($roles->groupBy(fn ($role) => $role->guard_name.'|'.$role->name) as $key => $group) {
            $canonicalByKey[$key] = $group->firstWhere('sppg_unit_id', $unitId) ?: $group->first();
        }
        $canonicalIdByOldId = [];
        foreach ($roles as $role) {
            $canonicalIdByOldId[$role->id] = $canonicalByKey[$role->guard_name.'|'.$role->name]->id;
        }

        $globalRoles = collect($canonicalByKey)->values()->map(fn ($role): array => [
            'id' => $role->id, 'name' => $role->name, 'guard_name' => $role->guard_name,
            'created_at' => $role->created_at, 'updated_at' => $role->updated_at,
        ])->all();
        $globalRolePermissions = $rolePermissions->map(fn ($row): array => [
            'permission_id' => $row->permission_id,
            'role_id' => $canonicalIdByOldId[$row->role_id],
        ])->unique(fn ($row) => $row['permission_id'].'|'.$row['role_id'])->values()->all();

        $globalModelRoles = $modelRoles
            ->groupBy(fn ($row) => $row->model_type.'|'.$row->model_id)
            ->flatMap(function ($rows) use ($unitId, $canonicalIdByOldId): array {
                $selected = $rows->where('sppg_unit_id', $unitId);
                if ($selected->isEmpty()) {
                    $selected = $rows;
                }

                return $selected->map(fn ($row): array => [
                    'role_id' => $canonicalIdByOldId[$row->role_id],
                    'model_type' => $row->model_type, 'model_id' => $row->model_id,
                ])->all();
            })->unique(fn ($row) => $row['role_id'].'|'.$row['model_type'].'|'.$row['model_id'])->values()->all();

        $globalModelPermissions = $modelPermissions
            ->groupBy(fn ($row) => $row->model_type.'|'.$row->model_id)
            ->flatMap(function ($rows) use ($unitId): array {
                $selected = $rows->where('sppg_unit_id', $unitId);
                if ($selected->isEmpty()) {
                    $selected = $rows;
                }

                return $selected->map(fn ($row): array => [
                    'permission_id' => $row->permission_id,
                    'model_type' => $row->model_type, 'model_id' => $row->model_id,
                ])->all();
            })->unique(fn ($row) => $row['permission_id'].'|'.$row['model_type'].'|'.$row['model_id'])->values()->all();

        Schema::drop('role_has_permissions');
        Schema::drop('model_has_roles');
        Schema::drop('model_has_permissions');
        Schema::drop('roles');

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });

        foreach (array_chunk($globalRoles, 500) as $chunk) {
            DB::table('roles')->insert($chunk);
        }
        foreach (array_chunk($globalRolePermissions, 500) as $chunk) {
            DB::table('role_has_permissions')->insert($chunk);
        }
        foreach (array_chunk($globalModelRoles, 500) as $chunk) {
            DB::table('model_has_roles')->insert($chunk);
        }
        foreach (array_chunk($globalModelPermissions, 500) as $chunk) {
            DB::table('model_has_permissions')->insert($chunk);
        }
    }
};
