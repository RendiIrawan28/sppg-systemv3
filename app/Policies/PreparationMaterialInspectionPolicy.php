<?php

namespace App\Policies;

use App\Models\PreparationMaterialInspection;
use App\Models\User;

class PreparationMaterialInspectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('preparation.view');
    }

    public function view(User $user, PreparationMaterialInspection $inspection): bool
    {
        return $user->can('preparation.view');
    }

    public function create(User $user): bool
    {
        return $user->can('preparation.create');
    }

    public function update(User $user, PreparationMaterialInspection $inspection): bool
    {
        if ($user->can('preparation.approve')) {
            return true;
        }

        return $user->can('preparation.update') && $inspection->isEditable();
    }

    public function delete(User $user, PreparationMaterialInspection $inspection): bool
    {
        return $user->can('preparation.delete') && $inspection->isEditable();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, PreparationMaterialInspection $inspection): bool
    {
        return false;
    }

    public function forceDelete(User $user, PreparationMaterialInspection $inspection): bool
    {
        return false;
    }
}
