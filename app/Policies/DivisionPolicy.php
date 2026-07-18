<?php

namespace App\Policies;

use App\Models\Division;
use App\Models\User;

class DivisionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('divisions.view') ||
            $user->can('divisions.manage');
    }

    public function view(
        User $user,
        Division $division
    ): bool {
        return $user->can('divisions.view') ||
            $user->can('divisions.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('divisions.manage');
    }

    public function update(
        User $user,
        Division $division
    ): bool {
        return $user->can('divisions.manage');
    }

    public function delete(
        User $user,
        Division $division
    ): bool {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(
        User $user,
        Division $division
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        Division $division
    ): bool {
        return false;
    }
}
