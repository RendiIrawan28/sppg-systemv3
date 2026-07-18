<?php

namespace App\Policies;

use App\Models\SppgUnit;
use App\Models\User;

class SppgUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_super_admin;
    }

    public function view(
        User $user,
        SppgUnit $sppgUnit
    ): bool {
        return $user->is_super_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_super_admin;
    }

    public function update(
        User $user,
        SppgUnit $sppgUnit
    ): bool {
        return $user->is_super_admin;
    }

    public function delete(
        User $user,
        SppgUnit $sppgUnit
    ): bool {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(
        User $user,
        SppgUnit $sppgUnit
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        SppgUnit $sppgUnit
    ): bool {
        return false;
    }
}
