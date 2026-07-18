<?php

namespace App\Policies;

use App\Models\NutritionStandard;
use App\Models\User;

class NutritionStandardPolicy
{
    public function viewAny(
        User $user
    ): bool {
        return $user->can(
            'nutrition.view'
        );
    }

    public function view(
        User $user,
        NutritionStandard $nutritionStandard
    ): bool {
        return $user->can(
            'nutrition.view'
        );
    }

    public function create(
        User $user
    ): bool {
        return $user->can(
            'nutrition.manage'
        );
    }

    public function update(
        User $user,
        NutritionStandard $nutritionStandard
    ): bool {
        return $user->can(
            'nutrition.manage'
        );
    }

    public function delete(
        User $user,
        NutritionStandard $nutritionStandard
    ): bool {
        return false;
    }

    public function deleteAny(
        User $user
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        NutritionStandard $nutritionStandard
    ): bool {
        return false;
    }

    public function restoreAny(
        User $user
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        NutritionStandard $nutritionStandard
    ): bool {
        return false;
    }

    public function forceDeleteAny(
        User $user
    ): bool {
        return false;
    }
}
