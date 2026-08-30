<?php

namespace App\Services\Mobile;

use App\Models\User;
use App\Support\DivisionRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class NotificationRecipientResolver
{
    /** @return Collection<int, User> */
    public function usersWithPermissionInUnit(
        int $unitId,
        string $permission,
        ?string $divisionCode = null,
    ): Collection {
        return User::query()
            ->where('is_active', true)
            ->permission($permission)
            ->when($divisionCode, function (Builder $query, string $divisionCode) use ($unitId): void {
                $roles = array_values(array_filter([
                    DivisionRole::headRoleForDivision($divisionCode),
                    DivisionRole::staffRoleForDivision($divisionCode),
                ]));

                $query->where(function (Builder $scope) use ($unitId, $divisionCode, $roles): void {
                    $scope->whereHas('divisions', fn (Builder $division) => $division
                        ->where('divisions.code', $divisionCode)
                        ->where('division_user.sppg_unit_id', $unitId)
                        ->where('division_user.is_active', true));

                    if ($roles !== []) {
                        $scope->orWhereHas('roles', fn (Builder $role) => $role->whereIn('name', $roles));
                    }
                });
            })
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, User> */
    public function activeUsersByIds(array $userIds): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('id', array_values(array_unique(array_filter($userIds))))
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, User> */
    public function activeUsersWithRoles(array $roles): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->role(array_values(array_unique(array_filter($roles))))
            ->orderBy('id')
            ->get();
    }
}
