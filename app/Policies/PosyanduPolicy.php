<?php

namespace App\Policies;

use App\Models\Posyandu;
use App\Models\User;

class PosyanduPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('posyandus.view');
    }

    public function view(
        User $user,
        Posyandu $posyandu
    ): bool {
        return $user->can('posyandus.view');
    }

    public function create(User $user): bool
    {
        return $user->can('posyandus.create');
    }

    public function update(
        User $user,
        Posyandu $posyandu
    ): bool {
        return $user->can('posyandus.update');
    }

    public function delete(
        User $user,
        Posyandu $posyandu
    ): bool {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
