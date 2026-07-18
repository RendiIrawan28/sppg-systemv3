<?php

namespace App\Policies;

use App\Models\CleaningArea;
use App\Models\User;

class CleaningAreaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cleaning.view');
    }

    public function view(User $user, CleaningArea $area): bool
    {
        return $user->can('cleaning.view');
    }

    public function create(User $user): bool
    {
        return $user->can('cleaning.create');
    }

    public function update(User $user, CleaningArea $area): bool
    {
        return $user->can('cleaning.update');
    }

    public function delete(User $user, CleaningArea $area): bool
    {
        return $user->can('cleaning.delete') && ! $area->sessions()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
