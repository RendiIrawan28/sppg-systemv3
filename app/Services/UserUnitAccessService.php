<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Division;
use App\Models\SppgUnit;
use App\Models\User;
use App\Support\DivisionRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserUnitAccessService
{
    /**
     * Menempatkan user pada satu unit aktif, mengatur satu role utama,
     * dan memasang divisi secara otomatis dari role spesifik.
     */
    public function sync(
        User $user,
        SppgUnit $unit,
        int $roleId,
        array $divisionIds = [],
        string $position = 'staff',
        bool $isMembershipActive = true,
    ): void {
        DB::transaction(function () use (
            $user,
            $unit,
            $roleId,
            $divisionIds,
            $position,
            $isMembershipActive,
        ): void {
            $role = Role::query()->whereKey($roleId)->where('guard_name', 'web')->first();

            if (! $role) {
                throw ValidationException::withMessages([
                    'role_id' => 'Role tidak tersedia pada Unit SPPG aktif.',
                ]);
            }

            $knownRole = UserRole::tryFrom($role->name);

            if (! $knownRole) {
                throw ValidationException::withMessages([
                    'role_id' => 'Role lama tidak dapat digunakan. Pilih role baru yang spesifik.',
                ]);
            }

            if (
                $knownRole === UserRole::SuperAdmin
                && auth()->user()?->is_super_admin !== true
            ) {
                throw ValidationException::withMessages([
                    'role_id' => 'Role Super Admin hanya dapat diatur oleh Super Admin.',
                ]);
            }

            if (DivisionRole::isDivisionRole($role->name)) {
                $divisionCode = DivisionRole::divisionCodeForRole($role->name);
                $divisionId = Division::query()
                    ->where('code', $divisionCode)
                    ->where('is_active', true)
                    ->value('id');

                if (! $divisionId) {
                    throw ValidationException::withMessages([
                        'role_id' => 'Divisi untuk role ini belum tersedia atau tidak aktif.',
                    ]);
                }

                $divisionIds = [(int) $divisionId];
                $position = DivisionRole::positionForRole($role->name) ?? 'staff';
            } else {
                // Role non-divisi tidak membawa penempatan divisi lama.
                $divisionIds = [];
                $position = 'staff';
            }

            DB::table('division_user')
                ->where('user_id', $user->getKey())
                ->where('sppg_unit_id', '!=', $unit->getKey())
                ->delete();

            $this->syncRole($user, $unit, $role);
            $this->syncDivisions(
                user: $user,
                unit: $unit,
                divisionIds: $divisionIds,
                position: $position,
                isActive: $isMembershipActive,
            );

            $user->forceFill(['is_active' => $isMembershipActive])->save();
        });
    }

    private function syncRole(User $user, SppgUnit $unit, Role $role): void
    {
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        $user->syncRoles([$role]);

        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
    }

    private function syncDivisions(
        User $user,
        SppgUnit $unit,
        array $divisionIds,
        string $position,
        bool $isActive,
    ): void {
        $validDivisionIds = Division::query()
            ->whereIn('id', $divisionIds)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        DB::table('division_user')
            ->where('user_id', $user->getKey())
            ->where('sppg_unit_id', $unit->getKey())
            ->delete();

        if ($validDivisionIds === []) {
            return;
        }

        $now = now();
        $rows = collect($validDivisionIds)
            ->values()
            ->map(static fn (int $divisionId, int $index): array => [
                'sppg_unit_id' => $unit->getKey(),
                'division_id' => $divisionId,
                'user_id' => $user->getKey(),
                'position' => $position,
                'is_primary' => $index === 0,
                'is_active' => $isActive,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        DB::table('division_user')->insert($rows);
    }
}
