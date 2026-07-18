<?php

namespace App\Policies;

use App\Models\Beneficiary;
use App\Models\User;

class BeneficiaryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(
            'beneficiaries.view'
        );
    }

    public function view(
        User $user,
        Beneficiary $beneficiary
    ): bool {
        return $user->can(
            'beneficiaries.view'
        );
    }

    public function create(User $user): bool
    {
        return $user->can(
            'beneficiaries.create'
        );
    }

    public function update(
        User $user,
        Beneficiary $beneficiary
    ): bool {
        return $user->can(
            'beneficiaries.update'
        );
    }

    public function delete(
        User $user,
        Beneficiary $beneficiary
    ): bool {
        return $user->can(
            'beneficiaries.delete'
        );
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(
            'beneficiaries.delete'
        );
    }
}
