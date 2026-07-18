<?php

namespace App\Policies;

use App\Models\BeneficiaryCategory;
use App\Models\User;

class BeneficiaryCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(
            'beneficiaries.view'
        );
    }

    public function view(
        User $user,
        BeneficiaryCategory $category
    ): bool {
        return $user->can(
            'beneficiaries.view'
        );
    }

    public function create(User $user): bool
    {
        return $user->can(
            'beneficiaries.update'
        );
    }

    public function update(
        User $user,
        BeneficiaryCategory $category
    ): bool {
        return $user->can(
            'beneficiaries.update'
        );
    }

    public function delete(
        User $user,
        BeneficiaryCategory $category
    ): bool {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
