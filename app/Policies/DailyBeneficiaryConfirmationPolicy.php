<?php

namespace App\Policies;

use App\Models\DailyBeneficiaryConfirmation;
use App\Models\User;
use App\Support\V3\SystemUnit;

class DailyBeneficiaryConfirmationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('daily_beneficiary_confirmations.view');
    }

    public function view(User $user, DailyBeneficiaryConfirmation $record): bool
    {
        return $user->can('daily_beneficiary_confirmations.view')
            && $this->canAccessRecordUnit($user, $record);
    }

    public function create(User $user): bool
    {
        return $user->can('daily_beneficiary_confirmations.create');
    }

    public function update(User $user, DailyBeneficiaryConfirmation $record): bool
    {
        return $user->can('daily_beneficiary_confirmations.update')
            && $this->canAccessRecordUnit($user, $record);
    }

    public function delete(User $user, DailyBeneficiaryConfirmation $record): bool
    {
        return $user->can('daily_beneficiary_confirmations.delete')
            && $record->status === 'pending'
            && $this->canAccessRecordUnit($user, $record);
    }

    public function restore(User $user, DailyBeneficiaryConfirmation $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, DailyBeneficiaryConfirmation $record): bool
    {
        return false;
    }

    private function canAccessRecordUnit(User $user, DailyBeneficiaryConfirmation $record): bool
    {
        return app(SystemUnit::class)->owns($record);
    }
}
