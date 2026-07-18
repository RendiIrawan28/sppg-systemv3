<?php

namespace App\Policies;

use App\Models\Ingredient;
use App\Models\User;

class IngredientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ingredients.view');
    }

    public function view(
        User $user,
        Ingredient $ingredient
    ): bool {
        return $user->can('ingredients.view');
    }

    public function create(User $user): bool
    {
        return $user->can('ingredients.create');
    }

    public function update(
        User $user,
        Ingredient $ingredient
    ): bool {
        return $user->can('ingredients.update');
    }

    public function delete(
        User $user,
        Ingredient $ingredient
    ): bool {
        return false;
    }
}
