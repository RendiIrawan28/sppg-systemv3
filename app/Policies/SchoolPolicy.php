<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;

class SchoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('schools.view');
    }

    public function view(
        User $user,
        School $school
    ): bool {
        return $user->can('schools.view');
    }

    public function create(User $user): bool
    {
        return $user->can('schools.create');
    }

    public function update(
        User $user,
        School $school
    ): bool {
        return $user->can('schools.update');
    }

    public function delete(
        User $user,
        School $school
    ): bool {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
