<?php

namespace App\Policies;

use App\Models\DistributionRun;
use App\Models\User;

class DistributionRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('distribution.view');
    }

    public function view(User $user, DistributionRun $run): bool
    {
        return $user->can('distribution.view');
    }

    public function create(User $user): bool
    {
        return $user->can('distribution.create');
    }

    public function update(User $user, DistributionRun $run): bool
    {
        if ($user->can('distribution.approve')) {
            return true;
        }

        return $user->can('distribution.update') && $run->isReportEditable();
    }

    public function delete(User $user, DistributionRun $run): bool
    {
        return $user->can('distribution.delete') && $run->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
