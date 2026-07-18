<?php

namespace App\Policies;

use App\Models\Allergen;
use App\Models\User;

class AllergenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('allergens.view');
    }

    public function view(User $user, Allergen $allergen): bool
    {
        return $user->can('allergens.view');
    }

    public function create(User $user): bool
    {
        return $user->can('allergens.manage');
    }

    public function update(User $user, Allergen $allergen): bool
    {
        return $user->can('allergens.manage');
    }

    public function delete(User $user, Allergen $allergen): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
