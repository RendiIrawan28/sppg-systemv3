<?php

namespace App\Policies;

use App\Models\BeneficiaryPeriod;
use App\Models\User;
use App\Support\V3\SystemUnit;

class BeneficiaryPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('beneficiary_periods.view');
    }

    public function view(User $user, BeneficiaryPeriod $record): bool
    {
        return $user->can('beneficiary_periods.view')
            && app(SystemUnit::class)->owns($record);
    }

    public function create(User $user): bool
    {
        return $user->can('beneficiary_periods.create');
    }

    public function update(User $user, BeneficiaryPeriod $record): bool
    {
        return $user->can('beneficiary_periods.update')
            && $record->isEditable()
            && $this->view($user, $record);
    }

    public function delete(User $user, BeneficiaryPeriod $record): bool
    {
        return $user->can('beneficiary_periods.delete')
            && $record->status === 'draft'
            && ! $record->distributionPlans()->exists()
            && $this->view($user, $record);
    }

    public function restore(User $user, BeneficiaryPeriod $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, BeneficiaryPeriod $record): bool
    {
        return false;
    }
}
