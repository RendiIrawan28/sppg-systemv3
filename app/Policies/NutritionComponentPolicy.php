<?php

namespace App\Policies;

use App\Models\NutritionComponent;
use App\Models\User;

class NutritionComponentPolicy
{
    /**
     * User yang memiliki akses modul gizi
     * dapat melihat daftar komponen.
     */
    public function viewAny(
        User $user
    ): bool {
        return $user->can(
            'nutrition.view'
        );
    }

    public function view(
        User $user,
        NutritionComponent $nutritionComponent
    ): bool {
        return $user->can(
            'nutrition.view'
        );
    }

    /**
     * Hanya user yang dapat mengelola
     * modul gizi yang boleh menambah data.
     */
    public function create(
        User $user
    ): bool {
        return $user->can(
            'nutrition.manage'
        );
    }

    public function update(
        User $user,
        NutritionComponent $nutritionComponent
    ): bool {
        return $user->can(
            'nutrition.manage'
        );
    }

    /**
     * Master gizi tidak dihapus karena dapat
     * digunakan oleh bahan, standar, dan menu.
     * Gunakan is_active untuk menonaktifkan.
     */
    public function delete(
        User $user,
        NutritionComponent $nutritionComponent
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
        NutritionComponent $nutritionComponent
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
        NutritionComponent $nutritionComponent
    ): bool {
        return false;
    }

    public function forceDeleteAny(
        User $user
    ): bool {
        return false;
    }
}
